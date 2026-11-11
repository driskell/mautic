<?php

declare(strict_types=1);

namespace Mautic\FormBundle\EventListener;

use Mautic\CoreBundle\Helper\DateTimeHelper;
use Mautic\FormBundle\Event\FormExportSchedulerEvent;
use Mautic\FormBundle\FormEvents;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final readonly class FormExportSchedulerLoggerEventSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private LoggerInterface $logger,
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
        $this->logger->info('Form results export scheduled', $this->getContext($event));
    }

    public function onFormExportEmailSent(FormExportSchedulerEvent $event): void
    {
        $this->logger->info('Form results export processed and emailed', $this->getContext($event) + ['file' => basename($event->getFilePath())]);
    }

    /**
     * @return array<string, int|string|null>
     */
    private function getContext(FormExportSchedulerEvent $event): array
    {
        $formExportScheduler = $event->getFormExportScheduler();

        return [
            'schedulerId'      => $formExportScheduler->getId(),
            'formId'           => $formExportScheduler->getForm()?->getId(),
            'userId'           => $formExportScheduler->getUser()?->getId(),
            'scheduledAtUtc'   => $formExportScheduler->getScheduledDateTime()
                ?->setTimezone(new \DateTimeZone('UTC'))
                ->format(DateTimeHelper::FORMAT_DB),
        ];
    }
}
