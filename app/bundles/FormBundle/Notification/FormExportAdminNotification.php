<?php

declare(strict_types=1);

namespace Mautic\FormBundle\Notification;

use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\CoreBundle\Model\NotificationModel;
use Mautic\CoreBundle\Twig\Helper\DateHelper;
use Mautic\EmailBundle\Helper\MailHelper;
use Mautic\FormBundle\Entity\Form;
use Mautic\FormBundle\Entity\FormExportScheduler;
use Mautic\UserBundle\Entity\User;
use Mautic\UserBundle\Entity\UserRepository;
use Symfony\Contracts\Translation\TranslatorInterface;

class FormExportAdminNotification
{
    public function __construct(
        private readonly NotificationModel $notificationModel,
        private readonly TranslatorInterface $translator,
        private readonly UserRepository $userRepository,
        private readonly MailHelper $mailHelper,
        private readonly CoreParametersHelper $coreParametersHelper,
        private readonly DateHelper $dateHelper,
    ) {
    }

    public function notifyRequested(FormExportScheduler $formExportScheduler): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        /** @var User $requestingUser */
        $requestingUser = $formExportScheduler->getUser();
        $requestedAt    = $this->getScheduledDateTime($formExportScheduler);

        foreach ($this->getAdminUsersToNotify($requestingUser) as $adminUser) {
            $this->notificationModel->addNotification(
                $this->translator->trans(
                    'mautic.form.export.admin.notification',
                    [
                        '%requesting_user_name%'  => $requestingUser->getName(),
                        '%requesting_user_email%' => $requestingUser->getEmail(),
                        '%form_name%'             => $this->getFormName($formExportScheduler),
                        '%requested_at%'          => $this->formatForDisplay($requestedAt),
                    ]
                ),
                'info',
                false,
                'mautic.form.export.admin.notification.header',
                null,
                \DateTime::createFromImmutable($requestedAt),
                $adminUser
            );
        }
    }

    public function notifyCompleted(FormExportScheduler $formExportScheduler): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        /** @var User $requestingUser */
        $requestingUser = $formExportScheduler->getUser();
        $adminUsers     = $this->getAdminUsersToNotify($requestingUser);

        if ([] === $adminUsers) {
            return;
        }

        $message = $this->translator->trans(
            'mautic.form.export.admin.email',
            [
                '%requesting_user_name%'  => $requestingUser->getName(),
                '%requesting_user_email%' => $requestingUser->getEmail(),
                '%form_name%'             => $this->getFormName($formExportScheduler),
                '%requested_at%'          => $this->formatForDisplay($this->getScheduledDateTime($formExportScheduler)),
                '%completed_at%'          => $this->formatForDisplay(new \DateTimeImmutable()),
            ]
        );

        $primaryAdmin = array_shift($adminUsers);

        $mailer = $this->mailHelper->getMailer(true);
        $mailer->setTo([$primaryAdmin->getEmail() => $primaryAdmin->getName()]);

        if ([] !== $adminUsers) {
            $mailer->setCc(
                array_reduce(
                    $adminUsers,
                    static function (array $recipients, User $adminUser): array {
                        $recipients[$adminUser->getEmail()] = $adminUser->getName();

                        return $recipients;
                    },
                    []
                )
            );
        }

        $mailer->setSubject($this->translator->trans('mautic.form.export.admin.email_subject'));
        $mailer->setBody($message);
        $mailer->parsePlainText($message);
        $mailer->send(true);
    }

    /**
     * @return User[]
     */
    private function getAdminUsersToNotify(User $requestingUser): array
    {
        return array_values(
            array_filter(
                $this->userRepository->getAllAdminUsers(),
                static fn (User $adminUser): bool => $adminUser->isPublished()
                    && $adminUser->getId() !== $requestingUser->getId()
            )
        );
    }

    private function isEnabled(): bool
    {
        return (bool) $this->coreParametersHelper->get('form_export_notify_admins');
    }

    private function getFormName(FormExportScheduler $formExportScheduler): string
    {
        /** @var Form $form */
        $form = $formExportScheduler->getForm();

        return (string) $form->getName();
    }

    private function getScheduledDateTime(FormExportScheduler $formExportScheduler): \DateTimeImmutable
    {
        /** @var \DateTimeImmutable $scheduledDateTime */
        $scheduledDateTime = $formExportScheduler->getScheduledDateTime();

        return $scheduledDateTime;
    }

    private function formatForDisplay(\DateTimeImmutable $dateTime): string
    {
        return $this->dateHelper->toFull(\DateTime::createFromInterface($dateTime));
    }
}
