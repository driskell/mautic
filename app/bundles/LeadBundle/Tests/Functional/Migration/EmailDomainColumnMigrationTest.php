<?php

declare(strict_types=1);

namespace Mautic\LeadBundle\Tests\Functional\Migration;

use Doctrine\DBAL\Connection;
use Mautic\CoreBundle\Test\MauticMysqlTestCase;
use Mautic\Migrations\Version20260816120000;
use Psr\Log\NullLogger;

/**
 * The functional suite builds its schema with `doctrine:schema:create` and then merely marks every
 * migration as applied, so migration bodies are never otherwise executed. This test puts the leads
 * table back into the legacy state (indexed virtual column) and runs the migration against it.
 */
final class EmailDomainColumnMigrationTest extends MauticMysqlTestCase
{
    protected $useCleanupRollback = false;

    private const COLUMN_NAME = 'generated_email_domain';

    private string $tablePrefix;

    private string $table;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tablePrefix = static::getContainer()->getParameter('mautic.db_table_prefix');
        $this->table       = $this->tablePrefix.'leads';
    }

    /**
     * This test rewrites the leads table definition, and $useCleanupRollback = false only restores
     * data, not DDL. If an assertion fails midway the table would stay in the legacy shape and take
     * every later test in the process down with it, so put it back unconditionally.
     */
    protected function beforeTearDown(): void
    {
        if ('' !== $this->getColumnExtraOrEmpty()) {
            $this->connection->executeStatement(sprintf('ALTER TABLE %s DROP COLUMN %s', $this->table, self::COLUMN_NAME));
            $this->connection->executeStatement(sprintf('ALTER TABLE %s ADD %s VARCHAR(255) DEFAULT NULL', $this->table, self::COLUMN_NAME));
        }

        if (!$this->hasIndex($this->tablePrefix.self::COLUMN_NAME)) {
            $this->connection->executeStatement(sprintf(
                'ALTER TABLE %s ADD INDEX `%s` (%s)',
                $this->table,
                $this->tablePrefix.self::COLUMN_NAME,
                self::COLUMN_NAME
            ));
        }
    }

    public function testMigrationConvertsTheVirtualColumnAndPreservesValues(): void
    {
        $emails = [
            'someone@example.com',
            'another@mail.example.co.uk',
            'not-an-email',
            'someone@',
            null,
        ];

        $this->revertToVirtualColumn();

        $leadIds = [];
        foreach ($emails as $email) {
            $leadIds[] = $this->insertLead($email);
        }

        // Capture what the database itself computed, so the migrated values can be compared against
        // the exact behaviour being replaced rather than against a hand-written expectation.
        $valuesBefore = $this->readDomains($leadIds);

        $this->assertSame('VIRTUAL GENERATED', $this->getColumnExtra(), 'Test setup failed to recreate the legacy virtual column.');

        $this->runMigration();

        $this->assertSame('', $this->getColumnExtra(), 'The column should no longer be a generated column.');
        // The index keeps the prefixed name, which is both the legacy name and the name that entity
        // metadata produces once DoctrineEventsSubscriber::loadClassMetadata() prefixes it.
        $this->assertTrue($this->hasIndex($this->tablePrefix.self::COLUMN_NAME), 'The column should be indexed after the migration.');
        $this->assertSame($valuesBefore, $this->readDomains($leadIds), 'Backfilled values must match what the virtual column produced.');
    }

    public function testMigrationIsSkippedWhenTheColumnIsAlreadyRegular(): void
    {
        // The schema created from entity metadata already has the regular column, so the skip
        // assertion should short-circuit preUp().
        $this->expectException(\Doctrine\Migrations\Exception\SkipMigration::class);

        $migration = $this->createMigration();
        $migration->preUp($this->introspectSchema());
    }

    private function revertToVirtualColumn(): void
    {
        foreach ([$this->tablePrefix.self::COLUMN_NAME, self::COLUMN_NAME] as $indexName) {
            if ($this->hasIndex($indexName)) {
                $this->connection->executeStatement(sprintf('ALTER TABLE %s DROP INDEX `%s`', $this->table, $indexName));
            }
        }

        $this->connection->executeStatement(sprintf('ALTER TABLE %s DROP COLUMN %s', $this->table, self::COLUMN_NAME));
        $this->connection->executeStatement(sprintf(
            'ALTER TABLE %s ADD %s VARCHAR(255) AS (SUBSTRING(email, LOCATE("@", email) + 1)) COMMENT \'(DC2Type:generated)\'',
            $this->table,
            self::COLUMN_NAME
        ));
        $this->connection->executeStatement(sprintf(
            'ALTER TABLE %s ADD INDEX `%s`(%s)',
            $this->table,
            $this->tablePrefix.self::COLUMN_NAME,
            self::COLUMN_NAME
        ));
    }

    private function runMigration(): void
    {
        $migration = $this->createMigration();
        $migration->up($this->introspectSchema());
    }

    private function createMigration(): Version20260816120000
    {
        $migration = new Version20260816120000($this->connection, new NullLogger());
        $migration->setPrefix($this->tablePrefix);

        return $migration;
    }

    private function introspectSchema(): \Doctrine\DBAL\Schema\Schema
    {
        return $this->connection->createSchemaManager()->introspectSchema();
    }

    /**
     * Inserted with raw SQL rather than through the entity: while the column is temporarily back to
     * being a generated column the ORM cannot write to it, and MySQL rejects any attempt to do so.
     */
    private function insertLead(?string $email): int
    {
        $this->connection->insert($this->table, [
            'email'      => $email,
            'points'     => 0,
            'date_added' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        return (int) $this->connection->lastInsertId();
    }

    /**
     * @param int[] $leadIds
     *
     * @return array<int, string|null>
     */
    private function readDomains(array $leadIds): array
    {
        $rows = $this->connection->fetchAllAssociative(
            sprintf('SELECT id, %s AS domain FROM %s WHERE id IN (:ids) ORDER BY id', self::COLUMN_NAME, $this->table),
            ['ids' => $leadIds],
            ['ids' => Connection::PARAM_INT_ARRAY]
        );

        $domains = [];
        foreach ($rows as $row) {
            $domains[(int) $row['id']] = null === $row['domain'] ? null : (string) $row['domain'];
        }

        return $domains;
    }

    private function getColumnExtra(): string
    {
        $column = $this->connection->fetchAssociative(
            sprintf('SHOW COLUMNS FROM %s WHERE Field = %s', $this->table, $this->connection->quote(self::COLUMN_NAME))
        );

        $this->assertNotFalse($column, 'The generated_email_domain column is missing.');

        return strtoupper((string) $column['Extra']);
    }

    /**
     * Same as getColumnExtra() but without assertions, so it is safe to call during tear down.
     */
    private function getColumnExtraOrEmpty(): string
    {
        $column = $this->connection->fetchAssociative(
            sprintf('SHOW COLUMNS FROM %s WHERE Field = %s', $this->table, $this->connection->quote(self::COLUMN_NAME))
        );

        return false === $column ? '' : strtoupper((string) $column['Extra']);
    }

    private function hasIndex(string $indexName): bool
    {
        return false !== $this->connection->fetchAssociative(
            sprintf('SHOW INDEX FROM %s WHERE Key_name = %s', $this->table, $this->connection->quote($indexName))
        );
    }
}
