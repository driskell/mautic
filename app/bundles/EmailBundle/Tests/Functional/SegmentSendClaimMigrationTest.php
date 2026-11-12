<?php

declare(strict_types=1);

namespace Mautic\EmailBundle\Tests\Functional;

use Doctrine\Migrations\Exception\SkipMigration;
use Mautic\CoreBundle\Test\MauticMysqlTestCase;
use Mautic\EmailBundle\Entity\Stat;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\Migrations\Version20261111120000;
use Psr\Log\NullLogger;

final class SegmentSendClaimMigrationTest extends MauticMysqlTestCase
{
    /**
     * Schema changes commit implicitly, so the test can't run inside a rollback transaction.
     */
    protected $useCleanupRollback = false;

    protected function beforeTearDown(): void
    {
        // Leave the schema complete for later tests even when this one fails part-way.
        $table   = MAUTIC_TABLE_PREFIX.Stat::TABLE_NAME;
        $manager = $this->connection->createSchemaManager();

        if (!array_key_exists('segment_email_id', $manager->listTableColumns($table))) {
            $this->connection->executeStatement("ALTER TABLE {$table} ADD COLUMN segment_email_id INT UNSIGNED NULL");
        }

        if (!array_key_exists(MAUTIC_TABLE_PREFIX.'stat_segment_send', $manager->listTableIndexes($table))) {
            $this->connection->executeStatement('ALTER TABLE '.$table.' ADD UNIQUE INDEX '.MAUTIC_TABLE_PREFIX.'stat_segment_send (segment_email_id, lead_id)');
        }
    }

    public function testMigrationAddsTheClaimColumnAndIndexToATableWithRowsAndSkipsWhenApplied(): void
    {
        $table = MAUTIC_TABLE_PREFIX.Stat::TABLE_NAME;
        $index = MAUTIC_TABLE_PREFIX.'stat_segment_send';

        $this->connection->executeStatement("ALTER TABLE {$table} DROP INDEX {$index}, DROP COLUMN segment_email_id");

        $contact = new Lead();
        $contact->setEmail('contact@example.com');
        $this->em->persist($contact);
        $this->em->flush();

        $this->connection->insert($table, [
            'lead_id'           => $contact->getId(),
            'email_address'     => 'contact@example.com',
            'date_sent'         => (new \DateTime())->format('Y-m-d H:i:s'),
            'is_read'           => 0,
            'is_failed'         => 0,
            'viewed_in_browser' => 0,
        ]);

        $this->executeMigration();

        $this->assertClaimSchemaExists($table, $index);
        $this->assertSame(
            [['lead_id' => $contact->getId(), 'segment_email_id' => null]],
            $this->fetchStats($table)
        );

        $this->executeMigration();
        $this->assertClaimSchemaExists($table, $index);
    }

    /**
     * Runs the migration as Doctrine does: skip assertions against the live schema, then its SQL.
     */
    private function executeMigration(): void
    {
        $migration = new Version20261111120000($this->connection, new NullLogger());
        $migration->setPrefix(MAUTIC_TABLE_PREFIX);
        $schema = $this->connection->createSchemaManager()->introspectSchema();

        try {
            $migration->preUp($schema);
        } catch (SkipMigration) {
            return;
        }

        $migration->up($schema);

        foreach ($migration->getSql() as $query) {
            $this->connection->executeStatement($query->getStatement());
        }
    }

    private function assertClaimSchemaExists(string $table, string $index): void
    {
        $columns = $this->connection->createSchemaManager()->listTableColumns($table);
        $this->assertArrayHasKey('segment_email_id', $columns);
        $this->assertFalse($columns['segment_email_id']->getNotnull());
        $this->assertTrue($columns['segment_email_id']->getUnsigned());

        $indexes = $this->connection->createSchemaManager()->listTableIndexes($table);
        $this->assertArrayHasKey($index, $indexes);
        $this->assertTrue($indexes[$index]->isUnique());
        $this->assertSame(['segment_email_id', 'lead_id'], $indexes[$index]->getColumns());
    }

    /**
     * @return array<int, array{lead_id: int, segment_email_id: int|null}>
     */
    private function fetchStats(string $table): array
    {
        return array_map(
            fn (array $row): array => [
                'lead_id'          => (int) $row['lead_id'],
                'segment_email_id' => null === $row['segment_email_id'] ? null : (int) $row['segment_email_id'],
            ],
            $this->connection->fetchAllAssociative("SELECT lead_id, segment_email_id FROM {$table}")
        );
    }
}
