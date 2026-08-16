<?php

declare(strict_types=1);

namespace Mautic\LeadBundle\EventListener;

use Mautic\LeadBundle\Event\LeadListFiltersChoicesEvent;
use Mautic\LeadBundle\LeadEvents;
use Mautic\LeadBundle\Model\ListModel;
use Mautic\LeadBundle\Segment\SegmentFilterIconTrait;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Exposes `leads.generated_email_domain` as a segment filter.
 *
 * This used to also register the column as a database generated (virtual) column via
 * CoreEvents::ON_GENERATED_COLUMNS_BUILD. It no longer does: an indexed generated column stops
 * MySQL and MariaDB from using ALGORITHM=INSTANT for any ALTER TABLE on `leads`, which matters
 * because custom fields add columns to that table routinely. The column is now a plain indexed
 * column populated in PHP - see Lead::extractEmailDomain().
 */
final class EmailDomainSubscriber implements EventSubscriberInterface
{
    use SegmentFilterIconTrait;

    public function __construct(
        private ListModel $segmentModel,
        private TranslatorInterface $translator,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            LeadEvents::LIST_FILTERS_CHOICES_ON_GENERATE => ['onGenerateSegmentFilters', 0],
        ];
    }

    public function onGenerateSegmentFilters(LeadListFiltersChoicesEvent $event): void
    {
        $event->addChoice('lead', 'generated_email_domain', [
            'label'      => $this->translator->trans('mautic.email.segment.choice.generated_email_domain'),
            'properties' => ['type' => 'text'],
            'operators'  => $this->segmentModel->getOperatorsForFieldType(
                [
                    'include' => [
                        '=',
                        '!=',
                        'empty',
                        '!empty',
                        'like',
                        '!like',
                        'regexp',
                        '!regexp',
                        'startsWith',
                        'endsWith',
                        'contains',
                    ],
                ]
            ),
            'object'    => 'lead',
            'iconClass' => $this->getSegmentFilterIcon('generated_email_domain'),
        ]);
    }
}
