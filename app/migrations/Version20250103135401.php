<?php

declare(strict_types=1);

namespace Mautic\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Mautic\CampaignBundle\Entity\Event;
use Mautic\CampaignBundle\Entity\LeadEventLog;
use Mautic\CoreBundle\Doctrine\PreUpAssertionMigration;

final class Version20250103135401 extends PreUpAssertionMigration
{
    protected function preUpAssertions(): void
    {
    }

    public function up(Schema $schema): void
    {
        $tableName = Event::TABLE_NAME;
        $this->addSql("ALTER TABLE {$this->prefix}{$tableName} ADD `trigger_source` VARCHAR(255) DEFAULT NULL AFTER `trigger_date`");

        $tableName = LeadEventLog::TABLE_NAME;
        $this->addSql("ALTER TABLE {$this->prefix}{$tableName} ADD `trigger_source` VARCHAR(255) DEFAULT NULL AFTER `is_scheduled`, ADD INDEX lead_trigger_source (lead_id, trigger_source)");
    }

    public function down(Schema $schema): void
    {
        $tableName = LeadEventLog::TABLE_NAME;
        $this->addSql("ALTER TABLE {$this->prefix}{$tableName} DROP `trigger_source`, DROP INDEX lead_trigger_source");

        $tableName = Event::TABLE_NAME;
        $this->addSql("ALTER TABLE {$this->prefix}{$tableName} DROP `trigger_source`");
    }
}
