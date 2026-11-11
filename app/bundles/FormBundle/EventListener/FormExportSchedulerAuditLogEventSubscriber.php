<?php

declare(strict_types=1);

namespace Mautic\FormBundle\EventListener;

use Mautic\CoreBundle\Helper\IpLookupHelper;
use Mautic\CoreBundle\Model\AuditLogModel;
use Mautic\FormBundle\Event\FormExportSchedulerEvent;
use Mautic\FormBundle\FormEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final readonly class FormExportSchedulerAuditLogEventSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private AuditLogModel $auditLogModel,
        private IpLookupHelper $ipLookupHelper,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            FormEvents::FORM_EXPORT_SCHEDULED       => 'onFormExportScheduled',
            FormEvents::POST_FORM_EXPORT_SEND_EMAIL => 'onFormExportEmailSent',
        ];
    }

    public function onFormExportScheduled(FormExportSchedulerEvent $event): void
    {
        $this->writeToLog($event, 'create');
    }

    public function onFormExportEmailSent(FormExportSchedulerEvent $event): void
    {
        $this->writeToLog($event, 'sendEmail');
    }

    private function writeToLog(FormExportSchedulerEvent $event, string $action): void
    {
        $this->auditLogModel->writeToLog(
            [
                'bundle'    => 'form',
                'object'    => 'FormExportScheduler',
                'objectId'  => $event->getFormExportScheduler()->getId(),
                'action'    => $action,
                'details'   => $event->getFormExportScheduler()->getChanges(),
                'ipAddress' => $this->ipLookupHelper->getIpAddressFromRequest(),
            ]
        );
    }
}
