<?php

declare(strict_types=1);

namespace Mautic\LeadBundle\EventListener;

use Mautic\LeadBundle\Event\ContactExportSchedulerEvent;
use Mautic\LeadBundle\LeadEvents;
use Mautic\LeadBundle\Model\ContactExportSchedulerModel;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final readonly class ContactScheduledExportSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private ContactExportSchedulerModel $contactExportSchedulerModel,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            LeadEvents::CONTACT_EXPORT_PREPARE_FILE    => 'onContactExportPrepareFile',
            LeadEvents::CONTACT_EXPORT_SEND_EMAIL      => 'onContactExportSendEmail',
            // After listeners that record the scheduler id, as removing the scheduler clears it
            LeadEvents::POST_CONTACT_EXPORT_SEND_EMAIL => ['onContactExportEmailSent', -255],
        ];
    }

    public function onContactExportPrepareFile(ContactExportSchedulerEvent $event): void
    {
        $contactExportScheduler = $event->getContactExportScheduler();
        $filePath               = $this->contactExportSchedulerModel->processAndGetExportFilePath($contactExportScheduler);
        $event->setFilePath($filePath);
    }

    public function onContactExportSendEmail(ContactExportSchedulerEvent $event): void
    {
        $contactExportScheduler = $event->getContactExportScheduler();
        $this->contactExportSchedulerModel->sendEmail($contactExportScheduler, $event->getFilePath());
    }

    public function onContactExportEmailSent(ContactExportSchedulerEvent $event): void
    {
        $contactExportScheduler = $event->getContactExportScheduler();
        $this->contactExportSchedulerModel->deleteEntity($contactExportScheduler);
    }
}
