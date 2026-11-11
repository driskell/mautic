<?php

declare(strict_types=1);

namespace Mautic\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Mautic\CoreBundle\Doctrine\PreUpAssertionMigration;

final class Version20261111120000 extends PreUpAssertionMigration
{
    protected const TABLE_NAME = 'form_export_scheduler';

    protected function preUpAssertions(): void
    {
        $this->skipAssertion(
            fn (Schema $schema) => $schema->hasTable($this->getPrefixedTableName(self::TABLE_NAME)),
            sprintf('Table %s already exists', $this->getPrefixedTableName(self::TABLE_NAME))
        );
    }

    public function up(Schema $schema): void
    {
        $tableName      = $this->getPrefixedTableName(self::TABLE_NAME);
        $usersTableName = $this->getPrefixedTableName('users');
        $formsTableName = $this->getPrefixedTableName('forms');

        $userIdType = $schema->getTable($usersTableName)->getColumn('id')->getUnsigned() ? 'UNSIGNED' : 'SIGNED';
        $formIdType = $schema->getTable($formsTableName)->getColumn('id')->getUnsigned() ? 'UNSIGNED' : 'SIGNED';

        [$userIdx, $userFk] = $this->generateKeys(self::TABLE_NAME, ['user_id']);
        [$formIdx, $formFk] = $this->generateKeys(self::TABLE_NAME, ['form_id']);

        $this->addSql(
            "CREATE TABLE {$tableName} (
                id INT UNSIGNED AUTO_INCREMENT NOT NULL,
                user_id INT {$userIdType} NOT NULL,
                form_id INT {$formIdType} NOT NULL,
                scheduled_datetime DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                data JSON NOT NULL COMMENT '(DC2Type:json)',
                INDEX {$userIdx} (user_id),
                INDEX {$formIdx} (form_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB"
        );

        $this->addSql("ALTER TABLE {$tableName} ADD CONSTRAINT {$userFk} FOREIGN KEY (user_id) REFERENCES {$usersTableName} (id) ON DELETE CASCADE");
        $this->addSql("ALTER TABLE {$tableName} ADD CONSTRAINT {$formFk} FOREIGN KEY (form_id) REFERENCES {$formsTableName} (id) ON DELETE CASCADE");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DROP TABLE {$this->getPrefixedTableName(self::TABLE_NAME)}");
    }
}
