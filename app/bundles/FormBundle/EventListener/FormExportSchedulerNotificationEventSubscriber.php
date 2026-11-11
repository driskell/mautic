<?php

declare(strict_types=1);

namespace Mautic\FormBundle\EventListener;

use Mautic\CoreBundle\Model\NotificationModel;
use Mautic\FormBundle\Event\FormExportSchedulerEvent;
use Mautic\FormBundle\FormEvents;
use Mautic\FormBundle\Notification\FormExportAdminNotification;
use Mautic\UserBundle\Entity\User;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class FormExportSchedulerNotificationEventSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private NotificationModel $notificationModel,
        private TranslatorInterface $translator,
        private FormExportAdminNotification $formExportAdminNotification,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            FormEvents::FORM_EXPORT_SCHEDULED       => 'onFormExportScheduled',
            // After the schedule is removed, so a failed admin email cannot resend the export
            FormEvents::POST_FORM_EXPORT_SEND_EMAIL => ['onFormExportEmailSent', -300],
        ];
    }

    public function onFormExportScheduled(FormExportSchedulerEvent $event): void
    {
        /** @var User $user */
        $user = $event->getFormExportScheduler()->getUser();

        $this->notificationModel->addNotification(
            $this->translator->trans('mautic.form.export.being.prepared', ['%user_email%' => $user->getEmail()]),
            'info',
            false,
            'mautic.form.export.being.prepared.header',
            null,
            null,
            $user
        );

        $this->formExportAdminNotification->notifyRequested($event->getFormExportScheduler());
    }

    public function onFormExportEmailSent(FormExportSchedulerEvent $event): void
    {
        $this->formExportAdminNotification->notifyCompleted($event->getFormExportScheduler());
    }
}
