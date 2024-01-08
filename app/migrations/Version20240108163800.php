<?php

declare(strict_types=1);

namespace Mautic\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\Exception\SkipMigration;
use Mautic\CoreBundle\Doctrine\AbstractMauticMigration;

final class Version20240108163800 extends AbstractMauticMigration
{
    /**
     * @var string
     */
    private $table = 'lead_lists';

    /**
     * @throws SkipMigration
     */
    public function preUp(Schema $schema): void
    {
        $table              = $this->prefix.$this->table;
        $shouldRunMigration = !$schema->getTable($table)->hasColumn('last_built_duration') || !$schema->getTable($table)->hasColumn('is_suspended');

        if (!$shouldRunMigration) {
            throw new SkipMigration('Schema includes this migration');
        }
    }

    public function up(Schema $schema): void
    {
        $table = $this->prefix.$this->table;
        $sql   = "ALTER TABLE {$table} ADD last_built_duration_ms INT DEFAULT NULL, ADD is_suspended TINYINT(1) NOT NULL; ";
        $this->addSql($sql);
    }

    public function down(Schema $schema): void
    {
        $table = $this->prefix.$this->table;
        $sql   = "ALTER TABLE {$table} DROP last_built_duration, DROP is_suspended; ";
        $this->addSql($sql);
    }
}
