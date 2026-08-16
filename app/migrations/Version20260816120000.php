<?php

declare(strict_types=1);

namespace Mautic\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Mautic\CoreBundle\Doctrine\PreUpAssertionMigration;
use Mautic\CoreBundle\Doctrine\Type\GeneratedType;

/**
 * Converts leads.generated_email_domain from an indexed virtual column into a regular indexed column.
 *
 * An indexed generated column prevents MySQL and MariaDB from using ALGORITHM=INSTANT for any
 * ALTER TABLE on the table it belongs to. Contact custom fields add columns to `leads` routinely, so
 * that restriction turned every custom field addition into a full table rebuild. The column keeps its
 * name and its values; it is simply populated by PHP now (see Lead::extractEmailDomain()).
 */
final class Version20260816120000 extends PreUpAssertionMigration
{
    protected const TABLE_NAME = 'leads';

    private const COLUMN_NAME = 'generated_email_domain';

    /**
     * Rows updated per statement during the backfill. The leads table can hold millions of rows, so
     * the update is chunked to keep individual transactions and lock durations manageable.
     */
    private const BATCH_SIZE = 25000;

    protected function preUpAssertions(): void
    {
        $this->skipAssertion(
            fn (Schema $schema) => $schema->getTable($this->getPrefixedTableName())->hasColumn(self::COLUMN_NAME)
                && GeneratedType::GENERATED !== $this->getColumnType($schema, self::TABLE_NAME, self::COLUMN_NAME),
            sprintf('Column %s is already a regular column', self::COLUMN_NAME)
        );
    }

    public function up(Schema $schema): void
    {
        $table     = $schema->getTable($this->getPrefixedTableName());
        $tableName = $this->getPrefixedTableName();

        // Statements are executed inline rather than queued with addSql() because the order matters:
        // the column has to exist before it can be backfilled, and the backfill has to finish before
        // the index is built so that it is only built once. addSql() would run everything afterwards.
        foreach ($this->getExistingIndexNames($table) as $indexName) {
            $this->connection->executeStatement(sprintf('ALTER TABLE %s DROP INDEX `%s`', $tableName, $indexName));
        }

        if ($table->hasColumn(self::COLUMN_NAME)) {
            $this->connection->executeStatement(sprintf('ALTER TABLE %s DROP COLUMN %s', $tableName, self::COLUMN_NAME));
        }

        $this->connection->executeStatement(
            sprintf('ALTER TABLE %s ADD %s VARCHAR(255) DEFAULT NULL', $tableName, self::COLUMN_NAME)
        );

        $this->backfill($tableName);

        $this->connection->executeStatement(
            sprintf('ALTER TABLE %s ADD INDEX `%s` (%s)', $tableName, self::COLUMN_NAME, self::COLUMN_NAME)
        );

        $this->suppressNoSQLStatementError();
    }

    public function down(Schema $schema): void
    {
        $tableName = $this->getPrefixedTableName();

        $this->connection->executeStatement(sprintf('ALTER TABLE %s DROP INDEX `%s`', $tableName, self::COLUMN_NAME));
        $this->connection->executeStatement(sprintf('ALTER TABLE %s DROP COLUMN %s', $tableName, self::COLUMN_NAME));
        $this->connection->executeStatement(sprintf(
            'ALTER TABLE %s ADD %s VARCHAR(255) AS (SUBSTRING(email, LOCATE("@", email) + 1)) COMMENT \'(DC2Type:generated)\'',
            $tableName,
            self::COLUMN_NAME
        ));
        $this->connection->executeStatement(sprintf(
            'ALTER TABLE %s ADD INDEX `%s`(%s)',
            $tableName,
            $this->prefix.self::COLUMN_NAME,
            self::COLUMN_NAME
        ));

        $this->suppressNoSQLStatementError();
    }

    /**
     * Populate the new column using the very expression that used to define the virtual column, so
     * the migrated values are identical to the ones the database was computing before.
     */
    private function backfill(string $tableName): void
    {
        $maxId = (int) $this->connection->fetchOne(sprintf('SELECT MAX(id) FROM %s', $tableName));

        if (0 === $maxId) {
            return;
        }

        $minId = (int) $this->connection->fetchOne(sprintf('SELECT MIN(id) FROM %s', $tableName));
        $sql   = sprintf(
            'UPDATE %s SET %s = SUBSTRING(email, LOCATE("@", email) + 1) WHERE id >= :start AND id < :end AND email IS NOT NULL',
            $tableName,
            self::COLUMN_NAME
        );

        for ($start = $minId; $start <= $maxId; $start += self::BATCH_SIZE) {
            $this->connection->executeStatement($sql, ['start' => $start, 'end' => $start + self::BATCH_SIZE]);
        }
    }

    /**
     * The index was previously created by GeneratedColumn::getIndexName(), which prefixes the table
     * prefix. Entity metadata now declares it without one, so both spellings have to be cleaned up.
     *
     * @return string[]
     */
    private function getExistingIndexNames(Table $table): array
    {
        $candidates = array_unique([$this->prefix.self::COLUMN_NAME, self::COLUMN_NAME]);

        return array_values(array_filter($candidates, $table->hasIndex(...)));
    }
}
