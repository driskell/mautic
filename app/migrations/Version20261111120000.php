<?php

declare(strict_types=1);

namespace Mautic\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Mautic\CoreBundle\Doctrine\PreUpAssertionMigration;
use Mautic\EmailBundle\Entity\Stat;

final class Version20261111120000 extends PreUpAssertionMigration
{
    private const COLUMN_NAME = 'segment_email_id';

    public function getDescription(): string
    {
        return 'Add the segment send claim column and unique index to email stats';
    }

    protected function preUpAssertions(): void
    {
        $this->skipAssertion(
            fn (Schema $schema): bool => $this->hasColumn($schema),
            sprintf('Column %s already exists', self::COLUMN_NAME)
        );

        $this->skipAssertion(
            fn (Schema $schema): bool => $this->hasIndex($schema),
            sprintf('Index %s already exists', $this->getIndexName())
        );
    }

    public function up(Schema $schema): void
    {
        $tableName = $this->getPrefixedTableName(Stat::TABLE_NAME);

        // Fail fast rather than queue behind long transactions for the metadata lock.
        $this->addSql('SET SESSION lock_wait_timeout = 10');

        if (!$this->hasColumn($schema)) {
            // MySQL adds this instantly; MariaDB can't with the indexed generated column and rebuilds the table, blocking writes.
            $this->addSql(sprintf(
                'ALTER TABLE %s ADD COLUMN %s INT UNSIGNED NULL',
                $tableName,
                self::COLUMN_NAME
            ));
        }

        if (!$this->hasIndex($schema)) {
            $this->addSql(sprintf(
                'ALTER TABLE %s ADD UNIQUE INDEX %s (%s, lead_id), ALGORITHM=INPLACE, LOCK=NONE',
                $tableName,
                $this->getIndexName(),
                self::COLUMN_NAME
            ));
        }

        $this->addSql('SET SESSION lock_wait_timeout = DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $tableName = $this->getPrefixedTableName(Stat::TABLE_NAME);

        $this->addSql(sprintf('ALTER TABLE %s DROP INDEX %s', $tableName, $this->getIndexName()));
        $this->addSql(sprintf('ALTER TABLE %s DROP COLUMN %s', $tableName, self::COLUMN_NAME));
    }

    private function hasColumn(Schema $schema): bool
    {
        return $schema->getTable($this->getPrefixedTableName(Stat::TABLE_NAME))->hasColumn(self::COLUMN_NAME);
    }

    private function hasIndex(Schema $schema): bool
    {
        return $schema->getTable($this->getPrefixedTableName(Stat::TABLE_NAME))->hasIndex($this->getIndexName());
    }

    private function getIndexName(): string
    {
        return $this->prefix.'stat_segment_send';
    }
}
