<?php

declare(strict_types=1);

namespace Mautic\CoreBundle\Tests\Functional\Doctrine\Helper;

use Mautic\CoreBundle\Test\MauticMysqlTestCase;
use Mautic\LeadBundle\Entity\LeadField;
use Mautic\LeadBundle\Field\Settings\InstantAlgorithmSettings;
use Mautic\LeadBundle\Model\FieldModel;

/**
 * Covers the field_force_instant_algorithm setting end to end: the LeadBundle parameter has to reach
 * ColumnSchemaHelper and make custom field columns be added and dropped with ALGORITHM=INSTANT.
 *
 * These also serve as a live check that the leads table is still eligible for instant DDL. If an
 * indexed generated column were ever reintroduced, the forced ALTER would be refused by the database
 * and these tests would fail rather than silently regressing to a full table rebuild.
 */
final class ColumnSchemaHelperInstantAlgorithmTest extends MauticMysqlTestCase
{
    protected $useCleanupRollback = false;

    protected function setUp(): void
    {
        $this->configParams[InstantAlgorithmSettings::FIELD_FORCE_INSTANT_ALGORITHM] = true;

        parent::setUp();
    }

    public function testCustomFieldColumnIsAddedAndDroppedWithInstantAlgorithmForced(): void
    {
        $alias = 'instant_algorithm_test';

        $field = $this->createCustomField($alias);

        $this->assertTrue($this->columnExists($alias), 'The custom field column should have been added.');

        /** @var FieldModel $fieldModel */
        $fieldModel = static::getContainer()->get(FieldModel::class);
        $fieldModel->deleteEntity($field);

        $this->assertFalse($this->columnExists($alias), 'The custom field column should have been dropped.');
    }

    private function createCustomField(string $alias): LeadField
    {
        $field = new LeadField();
        $field->setType('text');
        $field->setObject('lead');
        $field->setGroup('core');
        $field->setLabel('Instant algorithm test');
        $field->setAlias($alias);

        /** @var FieldModel $fieldModel */
        $fieldModel = static::getContainer()->get(FieldModel::class);
        $fieldModel->saveEntity($field);

        return $field;
    }

    private function columnExists(string $column): bool
    {
        $columns = $this->connection->createSchemaManager()->listTableColumns($this->getTablePrefix().'leads');

        return isset($columns[$column]);
    }
}
