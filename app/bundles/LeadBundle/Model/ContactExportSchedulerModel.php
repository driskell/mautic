<?php

declare(strict_types=1);

namespace Mautic\LeadBundle\Model;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\CoreBundle\Exception\FilePathException;
use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\CoreBundle\Helper\CsvHelper;
use Mautic\CoreBundle\Helper\ExportHelper;
use Mautic\CoreBundle\Helper\UserHelper;
use Mautic\CoreBundle\Model\AbstractCommonModel;
use Mautic\CoreBundle\Model\IteratorExportDataModel;
use Mautic\CoreBundle\Security\Permissions\CorePermissions;
use Mautic\CoreBundle\Translation\Translator;
use Mautic\EmailBundle\Helper\MailHelper;
use Mautic\LeadBundle\Entity\ContactExportScheduler;
use Mautic\LeadBundle\Entity\ContactExportSchedulerRepository;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Entity\LeadRepository;
use Mautic\UserBundle\Entity\User;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * @extends AbstractCommonModel<ContactExportScheduler>
 */
final class ContactExportSchedulerModel extends AbstractCommonModel
{
    // Captures the requesting user id; the optional suffix is added when a retry finds the file already exists
    public const EXPORT_FILE_NAME_PATTERN = '/^contacts_export_(\d+)_\d{4}(?:_\d{2}){5}_\d+(?:_\d+)?\.zip\z/';

    private const EXPORT_FILE_NAME_DATE_FORMAT = 'Y_m_d_H_i_s';

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly LeadModel $leadModel,
        private readonly ExportHelper $exportHelper,
        private readonly MailHelper $mailHelper,
        EntityManagerInterface $em,
        CorePermissions $security,
        EventDispatcherInterface $dispatcher,
        UrlGeneratorInterface $router,
        Translator $translator,
        UserHelper $userHelper,
        LoggerInterface $mauticLogger,
        CoreParametersHelper $coreParametersHelper,
        private readonly ContactExportSchedulerRepository $contactExportSchedulerRepository,
        private readonly LeadRepository $leadRepository,
    ) {
        parent::__construct($em, $security, $dispatcher, $router, $translator, $userHelper, $mauticLogger, $coreParametersHelper);
    }

    public function getRepository(): ContactExportSchedulerRepository
    {
        return $this->contactExportSchedulerRepository;
    }

    /**
     * @param array<mixed> $permissions
     *
     * @return array<string, mixed>
     */
    public function prepareData(array $permissions): array
    {
        $search     = $this->requestStack->getSession()->get('mautic.lead.filter', '');
        $orderBy    = $this->requestStack->getSession()->get('mautic.lead.orderby', 'l.last_active');
        $orderByDir = $this->requestStack->getSession()->get('mautic.lead.orderbydir', 'DESC');
        $indexMode  = $this->requestStack->getSession()->get('mautic.lead.indexmode', 'list');

        $anonymous = $this->translator->trans('mautic.lead.lead.searchcommand.isanonymous');

        /** @var Request $request */
        $request = $this->getRequest();

        $ids      = $request->get('ids');
        $fileType = $request->get('filetype', 'csv');

        $filter = ['string' => $search, 'force' => []];

        if (!empty($ids)) {
            $filter['force'] = [
                [
                    'column' => 'l.id',
                    'expr'   => 'in',
                    'value'  => json_decode($ids, true, 512, JSON_THROW_ON_ERROR),
                ],
            ];
        } else {
            if ('list' !== $indexMode || (!str_contains($search, $anonymous))) {
                // Remove anonymous leads unless requested to prevent clutter.
                $filter['force'] = [
                    [
                        'column' => 'l.dateIdentified',
                        'expr'   => 'isNotNull',
                    ],
                ];
            }

            if (!$permissions['lead:leads:viewother']) {
                // Show only owner's contacts.
                $filter['force'] = [
                    [
                        'column' => 'l.owner',
                        'expr'   => 'eq',
                    ],
                ];
            }
        }

        return [
            'start'          => 0,
            'limit'          => $this->coreParametersHelper->get('contact_export_batch_size', 1000),
            'filter'         => $filter,
            'orderBy'        => $orderBy,
            'orderByDir'     => $orderByDir,
            'withTotalCount' => true,
            'fileType'       => $fileType,
        ];
    }

    /**
     * @param array<mixed> $data
     */
    public function saveEntity(array $data): ContactExportScheduler
    {
        $contactExportScheduler = new ContactExportScheduler();
        $contactExportScheduler
            ->setUser($this->userHelper->getUser())
            ->setScheduledDateTime(new \DateTimeImmutable())
            ->setData($data);

        $this->em->persist($contactExportScheduler);
        $this->em->flush();

        return $contactExportScheduler;
    }

    public function processAndGetExportFilePath(ContactExportScheduler $contactExportScheduler): string
    {
        $data            = $contactExportScheduler->getData();
        $fileType        = $data['fileType'];
        $resultsCallback = fn ($contact) => $contact->getProfileFields();
        $iterator        = new IteratorExportDataModel(
            $this->leadModel,
            $contactExportScheduler->getData(),
            $resultsCallback,
            true
        );
        /** @var \DateTimeImmutable $scheduledDateTime */
        $scheduledDateTime = $contactExportScheduler->getScheduledDateTime();
        /** @var User $user */
        $user     = $contactExportScheduler->getUser();
        $fileName = sprintf(
            'contacts_export_%d_%s_%d',
            $user->getId(),
            $scheduledDateTime->format(self::EXPORT_FILE_NAME_DATE_FORMAT),
            $contactExportScheduler->getId()
        );

        return $this->exportResultsAs($iterator, $fileType, $fileName);
    }

    private function getEmailMessageWithLink(User $user, string $filePath): string
    {
        return $this->translator->trans(
            'mautic.lead.export.email',
            [
                '%user_name%' => $user->getName(),
                '%link%'      => $this->getDownloadLink($filePath),
                '%label%'     => basename($filePath),
            ]
        );
    }

    public function sendEmail(ContactExportScheduler $contactExportScheduler, string $filePath): void
    {
        /** @var User $user */
        $user    = $contactExportScheduler->getUser();
        $mailer  = $this->mailHelper->getMailer(true);
        $message = $this->getEmailMessageWithLink($user, $filePath);

        $mailer->setTo([$user->getEmail() => $user->getName()]);
        $mailer->setSubject(
            $this->translator->trans('mautic.lead.export.email_subject')
        );
        $mailer->setBody($message);
        $mailer->parsePlainText($message);

        if (!$mailer->send(true)) {
            throw new \RuntimeException(sprintf('The contact export email for schedule %d could not be sent.', $contactExportScheduler->getId()));
        }
    }

    private function getDownloadLink(string $filePath): string
    {
        return $this->router->generate(
            'mautic_contact_export_download',
            ['fileName' => basename($filePath)],
            UrlGeneratorInterface::ABSOLUTE_URL
        );
    }

    public function deleteEntity(ContactExportScheduler $contactExportScheduler): void
    {
        $this->em->remove($contactExportScheduler);
        $this->em->flush();
    }

    /**
     * @return array{userId: int}|null null when the name is not a contact export
     */
    public function parseExportFileName(string $fileName): ?array
    {
        if (!preg_match(self::EXPORT_FILE_NAME_PATTERN, $fileName, $matches)) {
            return null;
        }

        return ['userId' => (int) $matches[1]];
    }

    /**
     * @throws \Symfony\Component\HttpFoundation\File\Exception\FileNotFoundException
     * @throws \InvalidArgumentException                                              when the name is not a contact export
     */
    public function getExportFileToDownload(string $fileName): BinaryFileResponse
    {
        if (null === $this->parseExportFileName($fileName)) {
            throw new \InvalidArgumentException('Not a contact export file name.');
        }

        $filePath    = $this->coreParametersHelper->get('contact_export_dir').'/'.$fileName;
        $contentType = $this->getContactExportFileContentType($fileName);

        return new BinaryFileResponse(
            $filePath,
            Response::HTTP_OK,
            [
                'Content-Type'        => $contentType,
                'Content-Disposition' => 'attachment; filename="'.$fileName.'"',
                'Expires'             => 0,
                'Cache-Control'       => 'must-revalidate',
                'Pragma'              => 'public',
            ]
        );
    }

    private function getRequest(): ?Request
    {
        return $this->requestStack->getCurrentRequest();
    }

    private function exportResultsAs(IteratorExportDataModel $iterator, string $fileType, string $fileName): string
    {
        if (!in_array($fileType, $this->exportHelper->getSupportedExportTypes(), true)) {
            throw new BadRequestHttpException($this->translator->trans('mautic.error.invalid.export.type', ['%type%' => $fileType]));
        }

        $csvFilePath = $this->exportHelper
            ->exportDataIntoFile($iterator, $fileType, strtolower($fileName.'.'.$fileType));

        if (0 === $iterator->getTotal()) {
            $this->writeEmptyExportHeader($csvFilePath);
        }

        return $this->exportHelper->zipFile($csvFilePath, 'contacts_export.csv');
    }

    private function writeEmptyExportHeader(string $csvFilePath): void
    {
        // Exported rows group their fields in leads table column order, so the header must too
        $fieldList = $this->leadRepository->getCustomFieldList('lead')[0];
        $columns   = $this->em->getConnection()->createSchemaManager()->listTableColumns(MAUTIC_TABLE_PREFIX.'leads');
        $fields    = [];
        foreach ($columns as $column) {
            $field = $fieldList[$column->getName()] ?? null;
            if (null !== $field) {
                $fields[$field['group']][$field['alias']] = ['value' => null];
            }
        }

        // getProfileFields() needs the core group to include the id
        $contact = new Lead();
        $contact->setFields($fields + ['core' => []]);

        $handle = fopen($csvFilePath, 'ab');
        if (false === $handle) {
            throw new FilePathException("Could not open {$csvFilePath} to write the export header.");
        }

        CsvHelper::putCsv($handle, array_keys($contact->getProfileFields()));
        fclose($handle);
    }

    private function getContactExportFileContentType(string $fileName): string
    {
        $ext = pathinfo($fileName, PATHINFO_EXTENSION);

        if ('zip' === $ext) {
            return 'application/zip';
        }

        throw new BadRequestHttpException($this->translator->trans('mautic.error.invalid.specific.export.type', ['%type%' => $ext, '%expected_type%' => 'zip']));
    }
}
