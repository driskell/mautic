<?php

declare(strict_types=1);

namespace Mautic\FormBundle\Tests\Controller;

use Mautic\FormBundle\Command\FormScheduledExportCommand;
use Mautic\FormBundle\Entity\FormExportScheduler;
use Mautic\FormBundle\Entity\FormExportSchedulerRepository;
use Mautic\FormBundle\Entity\FormRepository;
use Mautic\LeadBundle\Command\CleanupExportedFilesCommand;
use Mautic\UserBundle\Entity\Role;
use Mautic\UserBundle\Entity\User;
use Mautic\UserBundle\Entity\UserRepository;
use Mautic\UserBundle\Model\RoleModel;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mime\Email;

final class ResultBackgroundExportFunctionalTest extends AbstractFormResultsExportTestCase
{
    private const HEADER = ['Submission ID', 'Contact ID', 'Date Submitted', 'IP address', 'Referrer', 'Full name', 'Colour'];

    private string $exportDir;

    protected function setUp(): void
    {
        $this->exportDir = sys_get_temp_dir().'/mautic_form_exports_'.getmypid();

        $this->configParams['form_export_in_background']     = true;
        $this->configParams['form_export_dir']               = $this->exportDir;
        $this->configParams['form_export_batch_size']        = 1000;
        $this->configParams['clear_export_files_after_days'] = 0;

        parent::setUp();
    }

    protected function beforeTearDown(): void
    {
        array_map(unlink(...), glob($this->exportDir.'/*') ?: []);
        if (is_dir($this->exportDir)) {
            rmdir($this->exportDir);
        }
    }

    public function testCsvExportIsScheduledWithTheResultsSortAndFilters(): void
    {
        $formId = $this->createFormWithSubmissions(3);

        $this->requestBackgroundExport($formId);

        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('File is queued for processing', (string) $this->client->getResponse()->getContent());

        $schedulers = $this->getSchedulerRepository()->findAll();
        $this->assertCount(1, $schedulers);
        $this->assertSame($formId, $schedulers[0]->getForm()->getId());
        $this->assertSame('admin', $schedulers[0]->getUser()->getUserIdentifier());
        $this->assertSame(
            ['filter' => ['force' => []], 'orderBy' => 's.date_submitted', 'orderByDir' => 'DESC'],
            $schedulers[0]->getData()
        );
    }

    public function testExcelExportDownloadsImmediately(): void
    {
        $formId = $this->createFormWithSubmissions(3);

        $this->client->request(Request::METHOD_GET, "/s/forms/results/{$formId}/export/xlsx");

        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('.xlsx', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
        $this->assertCount(0, $this->getSchedulerRepository()->findAll());
    }

    public function testScheduledExportEmailsTheRequesterADownloadOfEveryResultAcrossBatches(): void
    {
        $formId = $this->createFormWithSubmissions(1050);
        $this->requestBackgroundExport($formId);
        $schedulerId = $this->getSchedulerRepository()->findAll()[0]->getId();

        $command = $this->testSymfonyCommand(FormScheduledExportCommand::COMMAND_NAME, ['--ids' => (string) $schedulerId]);

        $this->assertStringContainsString('Form results export email(s) sent: 1', $command->getDisplay());
        $this->em->clear();
        $this->assertNotInstanceOf(FormExportScheduler::class, $this->getSchedulerRepository()->find($schedulerId), 'The processed schedule is removed');

        $email = $this->getEmailTo('Admin User <admin@yoursite.com>');
        $this->assertSame('Your form results export is ready', $email->getSubject());
        $this->assertSame(1, preg_match("#href='[^']*(/s/forms/results/export/download/form_results_export_\\d+_\\d+_[\\d_]+\\.zip)'#", (string) $email->getHtmlBody(), $link), 'The email links straight to the download');

        $this->client->request(Request::METHOD_GET, $link[1]);
        $this->assertResponseIsSuccessful();
        $this->assertSame('application/zip', $this->client->getResponse()->headers->get('Content-Type'));

        $rows = $this->readExport(basename($link[1]), $formId);
        $this->assertSame(self::HEADER, array_shift($rows));
        $this->assertSame($this->getSubmissionIds($formId, 'DESC'), array_map(fn (array $row): int => (int) $row[0], $rows));
    }

    public function testScheduledExportOfAFormWithoutResultsContainsOnlyTheHeader(): void
    {
        $formId = $this->createFormWithSubmissions(0);
        $this->requestBackgroundExport($formId);

        $this->testSymfonyCommand(FormScheduledExportCommand::COMMAND_NAME);

        $files = glob($this->exportDir.'/form_results_export_*.zip');
        $this->assertCount(1, $files);
        $this->assertSame([self::HEADER], $this->readExport(basename($files[0]), $formId));
    }

    public function testAFailedExportIsLoggedAndKeptForRetryWithoutBlockingOtherExports(): void
    {
        $brokenFormId = $this->createFormWithSubmissions(1);
        $this->requestBackgroundExport($brokenFormId);
        $form = self::getContainer()->get(FormRepository::class)->find($brokenFormId);
        $this->em->getConnection()->executeStatement('DROP TABLE '.MAUTIC_TABLE_PREFIX.'form_results_'.$brokenFormId.'_'.$form->getAlias());

        $workingFormId = $this->createFormWithSubmissions(2);
        $this->requestBackgroundExport($workingFormId);

        $command = $this->testSymfonyCommand(FormScheduledExportCommand::COMMAND_NAME);

        $this->assertSame(1, $command->getStatusCode());
        $this->assertStringContainsString('Form results export email(s) sent: 1', $command->getDisplay());
        $this->assertStringContainsString('Form results export(s) failed: 1', $command->getDisplay());
        $this->em->clear();
        $remaining = $this->getSchedulerRepository()->findAll();
        $this->assertCount(1, $remaining);
        $this->assertSame($brokenFormId, $remaining[0]->getForm()->getId());
    }

    public function testDownloadIsRefusedToOtherUsersAndForNamesThatAreNotFormExports(): void
    {
        $formId   = $this->createFormWithSubmissions(1);
        $admin    = self::getContainer()->get(UserRepository::class)->findOneBy(['username' => 'admin']);
        $fileName = $this->createExportFile($formId, $admin->getId());

        $otherUser = $this->createUser('other', ['form:forms' => ['viewown', 'viewother'], 'form:export' => ['enable']]);
        $this->loginUser($otherUser);
        $this->client->request(Request::METHOD_GET, '/s/forms/results/export/download/'.$fileName);
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN, 'Another user cannot download the export');

        $ownFile = $this->createExportFile($formId, $otherUser->getId());
        $this->client->request(Request::METHOD_GET, '/s/forms/results/export/download/'.$ownFile);
        $this->assertResponseIsSuccessful('A user can download their own export');

        $noExportUser = $this->createUser('noexport', ['form:forms' => ['viewown', 'viewother']]);
        $this->loginUser($noExportUser);
        $this->client->request(Request::METHOD_GET, '/s/forms/results/export/download/'.$this->createExportFile($formId, $noExportUser->getId()));
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN, 'Export permission is required');

        $this->loginUser($admin);
        foreach (['contacts_export_2024_01_02_03_04_05.zip', 'form_results_export_1_1_x.zip', 'form_results_export_'.$formId.'_1_2024.csv', 'form_results_export_999999_1_2024.zip'] as $badName) {
            $this->client->request(Request::METHOD_GET, '/s/forms/results/export/download/'.$badName);
            $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, $badName);
        }
    }

    public function testCleanupRemovesExpiredFormExports(): void
    {
        $formId   = $this->createFormWithSubmissions(1);
        $fileName = $this->createExportFile($formId, 1);

        $this->testSymfonyCommand(CleanupExportedFilesCommand::COMMAND_NAME);

        $this->assertFileDoesNotExist($this->exportDir.'/'.$fileName);
    }

    private function getSchedulerRepository(): FormExportSchedulerRepository
    {
        return self::getContainer()->get(FormExportSchedulerRepository::class);
    }

    private function requestBackgroundExport(int $formId): void
    {
        $this->client->request(Request::METHOD_POST, "/s/forms/results/{$formId}/export/csv", [], [], [
            'HTTP_X-Requested-With' => 'XMLHttpRequest',
            'HTTP_X-CSRF-Token'     => $this->getCsrfToken('mautic_ajax_post'),
        ]);
        $this->assertResponseIsSuccessful();
    }

    private function getEmailTo(string $address): Email
    {
        $messages = self::getMailerMessagesByToAddress($address);
        $this->assertCount(1, $messages);
        $this->assertInstanceOf(Email::class, $messages[0]);

        return $messages[0];
    }

    /**
     * @return array<int, array<int, string|null>>
     */
    private function readExport(string $fileName, int $formId): array
    {
        $form = self::getContainer()->get(FormRepository::class)->find($formId);

        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($this->exportDir.'/'.$fileName));
        $this->assertSame([$form->getAlias().'.csv'], [$zip->getNameIndex(0)], 'The zip holds one CSV named after the form');
        $csv = (string) $zip->getFromIndex(0);
        $zip->close();

        return array_map(str_getcsv(...), explode("\n", trim($csv)));
    }

    private function createExportFile(int $formId, int $userId): string
    {
        if (!is_dir($this->exportDir)) {
            mkdir($this->exportDir, 0777, true);
        }

        $fileName = sprintf('form_results_export_%d_%d_2024_01_02_03_04_05.zip', $formId, $userId);
        file_put_contents($this->exportDir.'/'.$fileName, 'zip');

        return $fileName;
    }

    /**
     * @param array<string, string[]> $permissions
     */
    private function createUser(string $username, array $permissions): User
    {
        $role = new Role();
        $role->setName($username.' role');
        $role->setIsAdmin(false);
        self::getContainer()->get(RoleModel::class)->setRolePermissions($role, $permissions);
        $this->em->persist($role);

        $user = new User();
        $user->setUsername($username);
        $user->setEmail($username.'@example.com');
        $user->setFirstName($username);
        $user->setLastName('User');
        $user->setPassword('not-used');
        $user->setRole($role);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
