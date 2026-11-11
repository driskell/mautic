<?php

declare(strict_types=1);

namespace Mautic\FormBundle\Model;

use Mautic\CoreBundle\Helper\ExportHelper;
use Mautic\CoreBundle\Model\AbstractCommonModel;
use Mautic\EmailBundle\Helper\MailHelper;
use Mautic\FormBundle\Entity\Form;
use Mautic\FormBundle\Entity\FormExportScheduler;
use Mautic\FormBundle\Entity\FormExportSchedulerRepository;
use Mautic\UserBundle\Entity\User;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * @extends AbstractCommonModel<FormExportScheduler>
 */
final class FormExportSchedulerModel extends AbstractCommonModel
{
    // Captures the form id and the requesting user id; \z rejects a trailing newline
    public const EXPORT_FILE_NAME_PATTERN = '/^form_results_export_(\d+)_(\d+)_[\d_]+\.zip\z/';

    private const EXPORT_FILE_NAME_DATE_FORMAT = 'Y_m_d_H_i_s';

    private SubmissionModel $submissionModel;

    private ExportHelper $exportHelper;

    private MailHelper $mailHelper;

    private FormExportSchedulerRepository $formExportSchedulerRepository;

    #[Required]
    public function autowireFormExportSchedulerModel(
        SubmissionModel $submissionModel,
        ExportHelper $exportHelper,
        MailHelper $mailHelper,
        FormExportSchedulerRepository $formExportSchedulerRepository,
    ): void {
        $this->submissionModel               = $submissionModel;
        $this->exportHelper                  = $exportHelper;
        $this->mailHelper                    = $mailHelper;
        $this->formExportSchedulerRepository = $formExportSchedulerRepository;
    }

    public function getRepository(): FormExportSchedulerRepository
    {
        return $this->formExportSchedulerRepository;
    }

    /**
     * @param array{filter: array<mixed>, orderBy: string, orderByDir: string} $queryArgs scalar values only, as they are serialised
     */
    public function saveEntity(Form $form, array $queryArgs): FormExportScheduler
    {
        $formExportScheduler = new FormExportScheduler();
        $formExportScheduler
            ->setUser($this->userHelper->getUser())
            ->setForm($form)
            ->setScheduledDateTime(new \DateTimeImmutable())
            ->setData($queryArgs);

        $this->em->persist($formExportScheduler);
        $this->em->flush();

        return $formExportScheduler;
    }

    public function processAndGetExportFilePath(FormExportScheduler $formExportScheduler): string
    {
        /** @var Form $form */
        $form = $formExportScheduler->getForm();
        /** @var User $user */
        $user = $formExportScheduler->getUser();
        /** @var \DateTimeImmutable $scheduledDateTime */
        $scheduledDateTime = $formExportScheduler->getScheduledDateTime();

        $iterator = $this->submissionModel->getResultsExportIterator(
            ['form' => $form] + $formExportScheduler->getData(),
            (int) $this->coreParametersHelper->get('form_export_batch_size', SubmissionModel::EXPORT_BATCH_SIZE)
        );

        $fileName = sprintf(
            'form_results_export_%d_%d_%s_%d.csv',
            $form->getId(),
            $user->getId(),
            $scheduledDateTime->format(self::EXPORT_FILE_NAME_DATE_FORMAT),
            $formExportScheduler->getId()
        );

        $csvFilePath = $this->exportHelper->exportDataIntoFile(
            $iterator,
            ExportHelper::EXPORT_TYPE_CSV,
            $fileName,
            $this->submissionModel->getResultsExportHeader($form),
            $this->getExportDir()
        );

        return $this->exportHelper->zipFile($csvFilePath, $form->getAlias().'.csv');
    }

    public function sendEmail(FormExportScheduler $formExportScheduler, string $filePath): void
    {
        /** @var User $user */
        $user = $formExportScheduler->getUser();
        /** @var Form $form */
        $form    = $formExportScheduler->getForm();
        $message = $this->translator->trans(
            'mautic.form.export.email',
            [
                '%user_name%' => $user->getName(),
                '%form_name%' => $form->getName(),
                '%link%'      => $this->router->generate(
                    'mautic_form_export_download',
                    ['fileName' => basename($filePath)],
                    UrlGeneratorInterface::ABSOLUTE_URL
                ),
                '%label%'     => basename($filePath),
            ]
        );

        $mailer = $this->mailHelper->getMailer(true);
        $mailer->setTo([$user->getEmail() => $user->getName()]);
        $mailer->setSubject($this->translator->trans('mautic.form.export.email_subject'));
        $mailer->setBody($message);
        $mailer->parsePlainText($message);

        if (!$mailer->send(true)) {
            throw new \RuntimeException(sprintf('The form results export email for schedule %d could not be sent.', $formExportScheduler->getId()));
        }
    }

    public function deleteEntity(FormExportScheduler $formExportScheduler): void
    {
        $this->em->remove($formExportScheduler);
        $this->em->flush();
    }

    /**
     * @return array{formId: int, userId: int}|null null when the name is not a form results export
     */
    public function parseExportFileName(string $fileName): ?array
    {
        if (!preg_match(self::EXPORT_FILE_NAME_PATTERN, $fileName, $matches)) {
            return null;
        }

        return ['formId' => (int) $matches[1], 'userId' => (int) $matches[2]];
    }

    /**
     * @throws \Symfony\Component\HttpFoundation\File\Exception\FileNotFoundException
     */
    public function getExportFileToDownload(string $fileName): BinaryFileResponse
    {
        if (null === $this->parseExportFileName($fileName)) {
            throw new \InvalidArgumentException('Not a form results export file name.');
        }

        return new BinaryFileResponse(
            $this->getExportDir().'/'.$fileName,
            Response::HTTP_OK,
            [
                'Content-Type'        => 'application/zip',
                'Content-Disposition' => 'attachment; filename="'.$fileName.'"',
                'Expires'             => 0,
                'Cache-Control'       => 'must-revalidate',
                'Pragma'              => 'public',
            ]
        );
    }

    private function getExportDir(): string
    {
        return $this->coreParametersHelper->get('form_export_dir');
    }
}
