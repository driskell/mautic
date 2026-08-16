<?php

declare(strict_types=1);

namespace Mautic\LeadBundle\Tests\Entity;

use Mautic\CoreBundle\Test\MauticMysqlTestCase;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Entity\LeadList;
use Mautic\LeadBundle\Entity\LeadRepository;
use Mautic\LeadBundle\Entity\ListLead;
use Mautic\LeadBundle\Model\LeadModel;

/**
 * `leads.generated_email_domain` used to be an indexed virtual column. It is now a regular indexed
 * column kept up to date by PHP, which is what allows ALTER TABLE ... ALGORITHM=INSTANT on `leads`.
 *
 * These tests cover the two things that change had to preserve: the column has to still be indexed
 * (so segment filters stay fast) and it has to still hold the right value after every write path.
 */
final class LeadEmailDomainFunctionalTest extends MauticMysqlTestCase
{
    protected $useCleanupRollback = false;

    public function testColumnIsARegularIndexedColumn(): void
    {
        $table  = $this->getTablePrefix().'leads';
        $column = $this->connection->fetchAssociative(
            sprintf('SHOW COLUMNS FROM %s WHERE Field = %s', $table, $this->connection->quote('generated_email_domain'))
        );

        $this->assertNotFalse($column, 'The generated_email_domain column is missing from the leads table.');

        // This is the assertion that actually protects ALGORITHM=INSTANT. MySQL and MariaDB report
        // "VIRTUAL GENERATED" or "STORED GENERATED" in Extra for generated columns; a plain column
        // reports an empty string.
        $this->assertSame(
            '',
            $column['Extra'],
            'generated_email_domain must not be a generated column: an indexed generated column blocks ALGORITHM=INSTANT on the leads table.'
        );

        $this->assertSame('varchar(255)', strtolower((string) $column['Type']));

        $index = $this->connection->fetchAssociative(
            sprintf('SHOW INDEX FROM %s WHERE Column_name = %s', $table, $this->connection->quote('generated_email_domain'))
        );

        $this->assertNotFalse($index, 'The generated_email_domain column is expected to be indexed.');
    }

    /**
     * The leads table must stay eligible for instant column additions - that is the entire point of
     * this change, and the regression is silent without an explicit check.
     */
    public function testLeadsTableStillAllowsInstantColumnAddition(): void
    {
        $table = $this->getTablePrefix().'leads';

        $this->connection->executeStatement(
            sprintf('ALTER TABLE %s ADD COLUMN instant_algorithm_probe VARCHAR(10) NULL, ALGORITHM=INSTANT', $table)
        );
        $this->connection->executeStatement(
            sprintf('ALTER TABLE %s DROP COLUMN instant_algorithm_probe', $table)
        );

        $this->addToAssertionCount(1);
    }

    public function testDomainIsStoredWhenContactIsFlushedThroughTheEntityManager(): void
    {
        $lead = new Lead();
        $lead->setEmail('someone@flushed.example.com');
        $this->em->persist($lead);
        $this->em->flush();

        $this->assertSame('flushed.example.com', $this->readStoredDomain((int) $lead->getId()));
    }

    public function testDomainIsStoredWhenContactIsSavedThroughTheRepository(): void
    {
        /** @var LeadRepository $repository */
        $repository = $this->em->getRepository(Lead::class);

        $lead = new Lead();
        $lead->setEmail('someone@saved.example.com');
        $repository->saveEntity($lead);

        $this->assertSame('saved.example.com', $this->readStoredDomain((int) $lead->getId()));
    }

    public function testDomainIsStoredWhenEmailIsSetThroughTheFieldValueApi(): void
    {
        /** @var LeadModel $leadModel */
        $leadModel = static::getContainer()->get(LeadModel::class);

        $lead = new Lead();
        $leadModel->setFieldValues($lead, ['email' => 'someone@fieldvalues.example.com']);
        $leadModel->saveEntity($lead);

        $this->assertSame('fieldvalues.example.com', $this->readStoredDomain((int) $lead->getId()));
    }

    public function testDomainIsUpdatedAndClearedAlongsideTheEmail(): void
    {
        /** @var LeadRepository $repository */
        $repository = $this->em->getRepository(Lead::class);

        $lead = new Lead();
        $lead->setEmail('someone@before.example.com');
        $repository->saveEntity($lead);

        $leadId = (int) $lead->getId();
        $this->assertSame('before.example.com', $this->readStoredDomain($leadId));

        $lead->setEmail('someone@after.example.com');
        $repository->saveEntity($lead);
        $this->assertSame('after.example.com', $this->readStoredDomain($leadId));

        $lead->setEmail(null);
        $repository->saveEntity($lead);
        $this->assertNull($this->readStoredDomain($leadId));
    }

    /**
     * The old virtual column returned the whole string when there was no '@' in the address, because
     * LOCATE() returned 0. Existing segments may rely on that, so the PHP implementation matches it.
     */
    public function testAddressWithoutAnAtSignIsStoredVerbatim(): void
    {
        /** @var LeadRepository $repository */
        $repository = $this->em->getRepository(Lead::class);

        $lead = new Lead();
        $lead->setEmail('not-an-email');
        $repository->saveEntity($lead);

        $this->assertSame('not-an-email', $this->readStoredDomain((int) $lead->getId()));
    }

    public function testSegmentCanFilterContactsByEmailDomain(): void
    {
        /** @var LeadRepository $repository */
        $repository = $this->em->getRepository(Lead::class);

        $matching = new Lead();
        $matching->setEmail('someone@wanted.example.com');

        $alsoMatching = new Lead();
        $alsoMatching->setEmail('another@wanted.example.com');

        $notMatching = new Lead();
        $notMatching->setEmail('someone@unwanted.example.com');

        $repository->saveEntities([$matching, $alsoMatching, $notMatching]);

        $segment = new LeadList();
        $segment->setName('Wanted domain')
            ->setPublicName('Wanted domain')
            ->setAlias('wanted-domain')
            ->setFilters([
                [
                    'glue'     => 'and',
                    'field'    => 'generated_email_domain',
                    'object'   => 'lead',
                    'type'     => 'text',
                    'filter'   => 'wanted.example.com',
                    'display'  => null,
                    'operator' => '=',
                ],
            ]);
        $this->em->persist($segment);
        $this->em->flush();

        $this->testSymfonyCommand('mautic:segments:update', ['-i' => $segment->getId()]);

        $memberIds = array_map(
            static fn (ListLead $listLead): int => (int) $listLead->getLead()->getId(),
            $this->em->getRepository(ListLead::class)->findBy(['list' => $segment])
        );
        sort($memberIds);

        $expected = [(int) $matching->getId(), (int) $alsoMatching->getId()];
        sort($expected);

        $this->assertSame($expected, $memberIds);
    }

    /**
     * Read the raw column rather than the entity, so the test proves what actually reached the
     * database instead of what the in-memory object happens to hold.
     */
    private function readStoredDomain(int $leadId): ?string
    {
        $value = $this->connection->fetchOne(
            sprintf('SELECT generated_email_domain FROM %sleads WHERE id = :id', $this->getTablePrefix()),
            ['id' => $leadId]
        );

        return false === $value || null === $value ? null : (string) $value;
    }
}
