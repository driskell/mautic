<?php

declare(strict_types=1);

namespace Mautic\FormBundle\EventListener;

use Mautic\FormBundle\Event\FormExportSchedulerEvent;
use Mautic\FormBundle\FormEvents;
use Mautic\FormBundle\Model\FormExportSchedulerModel;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final readonly class FormScheduledExportEventSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private FormExportSchedulerModel $formExportSchedulerModel,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            FormEvents::FORM_EXPORT_PREPARE_FILE    => 'onFormExportPrepareFile',
            FormEvents::FORM_EXPORT_SEND_EMAIL      => 'onFormExportSendEmail',
            // Last, as removing the scheduler clears the id other listeners record
            FormEvents::POST_FORM_EXPORT_SEND_EMAIL => ['onFormExportEmailSent', -255],
        ];
    }

    public function onFormExportPrepareFile(FormExportSchedulerEvent $event): void
    {
        $event->setFilePath($this->formExportSchedulerModel->processAndGetExportFilePath($event->getFormExportScheduler()));
    }

    public function onFormExportSendEmail(FormExportSchedulerEvent $event): void
    {
        $this->formExportSchedulerModel->sendEmail($event->getFormExportScheduler(), $event->getFilePath());
    }

    public function onFormExportEmailSent(FormExportSchedulerEvent $event): void
    {
        $this->formExportSchedulerModel->deleteEntity($event->getFormExportScheduler());
    }
}
