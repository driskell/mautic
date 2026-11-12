<?php

declare(strict_types=1);

namespace Mautic\LeadBundle\Tests\Functional\Controller;

use Mautic\CoreBundle\Test\MauticMysqlTestCase;
use Mautic\LeadBundle\Command\ContactScheduledExportCommand;
use Mautic\LeadBundle\Entity\ContactExportScheduler;
use Mautic\LeadBundle\Entity\ContactExportSchedulerRepository;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Model\LeadModel;
use Mautic\UserBundle\Entity\Role;
use Mautic\UserBundle\Entity\User;
use Mautic\UserBundle\Entity\UserRepository;
use Mautic\UserBundle\Model\RoleModel;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class ContactBackgroundExportFunctionalTest extends MauticMysqlTestCase
{
    private string $exportDir;

    protected function setUp(): void
    {
        $this->exportDir = sys_get_temp_dir().'/mautic_contact_exports_'.getmypid();

        $this->configParams['contact_export_in_background'] = true;
        $this->configParams['contact_export_dir']           = $this->exportDir;

        parent::setUp();
    }

    protected function beforeTearDown(): void
    {
        array_map(unlink(...), glob($this->exportDir.'/*') ?: []);
        if (is_dir($this->exportDir)) {
            rmdir($this->exportDir);
        }
    }

    public function testAFailedExportIsLoggedAndKeptForRetryWithoutBlockingOtherExports(): void
    {
        $this->createContacts();
        $broken = $this->requestBackgroundExport();
        $broken->setData(['orderBy' => 'l.no_such_column'] + $broken->getData());
        $this->em->flush();
        $this->requestBackgroundExport();

        $command = $this->testSymfonyCommand(ContactScheduledExportCommand::COMMAND_NAME);

        $this->assertSame(1, $command->getStatusCode());
        $this->assertStringContainsString('Contact export email(s) sent: 1', $command->getDisplay());
        $this->assertStringContainsString('Contact export(s) failed: 1', $command->getDisplay());
        $this->em->clear();
        $remaining = $this->getSchedulerRepository()->findAll();
        $this->assertCount(1, $remaining);
        $this->assertSame($broken->getId(), $remaining[0]->getId());
    }

    public function testAnExportRetriedAfterAnInterruptedAttemptCanStillBeDownloaded(): void
    {
        $this->createContacts();
        $scheduler = $this->requestBackgroundExport();
        $baseName  = $this->getExpectedBaseName($scheduler);
        mkdir($this->exportDir, 0777, true);
        file_put_contents($this->exportDir.'/'.$baseName.'.csv', 'partial');

        $this->testSymfonyCommand(ContactScheduledExportCommand::COMMAND_NAME);

        $zipName = $baseName.'_1.zip';
        $this->assertFileExists($this->exportDir.'/'.$zipName);
        $this->client->request(Request::METHOD_GET, '/s/contacts/export/download/'.$zipName);
        $this->assertResponseIsSuccessful();
    }

    public function testAnExportWithoutContactsContainsOnlyTheHeaderOfAnExportWithContacts(): void
    {
        $this->createContacts();
        $this->requestBackgroundExport();
        $this->testSymfonyCommand(ContactScheduledExportCommand::COMMAND_NAME);
        $withContacts = $this->readOnlyExport();
        $this->assertCount(3, $withContacts);

        array_map(unlink(...), glob($this->exportDir.'/*') ?: []);
        $this->requestBackgroundExport(['ids' => json_encode([PHP_INT_MAX])]);
        $this->testSymfonyCommand(ContactScheduledExportCommand::COMMAND_NAME);

        $this->assertSame([$withContacts[0]], $this->readOnlyExport());
    }

    public function testDownloadRequiresExportPermissionAndOwnership(): void
    {
        $admin     = self::getContainer()->get(UserRepository::class)->findOneBy(['username' => 'admin']);
        $adminFile = $this->createExportFile($admin->getId());

        $exporter = $this->createUser('exporter', ['lead:leads' => ['viewown', 'viewother'], 'lead:export' => ['enable']]);
        $ownFile  = $this->createExportFile($exporter->getId());
        $this->loginUser($exporter);

        $this->client->request(Request::METHOD_GET, '/s/contacts/export/download/'.$ownFile);
        $this->assertResponseIsSuccessful('A user can download their own export');

        $this->client->request(Request::METHOD_GET, '/s/contacts/export/download/'.$adminFile);
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN, 'Another user cannot download the export');

        foreach ([
            'contacts_export_2024_01_02_03_04_05.zip',
            'contacts_export_'.$exporter->getId().'_2024_01_02_03_04_05.zip',
            'contacts_export_'.$exporter->getId().'_2024_01_02_03_04_05_1.ZIP',
            'contacts_export_'.$exporter->getId().'_2024_01_02_03_04_05_1.csv',
            'contacts_export_'.$exporter->getId().'_2024_01_02_03_04_05_1.zip..',
        ] as $badName) {
            $this->client->request(Request::METHOD_GET, '/s/contacts/export/download/'.$badName);
            $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, $badName);
        }

        $viewer = $this->createUser('viewer', ['lead:leads' => ['viewown', 'viewother']]);
        $this->loginUser($viewer);
        $this->client->request(Request::METHOD_GET, '/s/contacts/export/download/'.$this->createExportFile($viewer->getId()));
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN, 'Export permission is required');

        $this->loginUser($admin);
        $this->client->request(Request::METHOD_GET, '/s/contacts/export/download/'.$ownFile);
        $this->assertResponseIsSuccessful('An administrator can download any export');
    }

    /**
     * @param array<string, string> $parameters
     */
    private function requestBackgroundExport(array $parameters = []): ContactExportScheduler
    {
        $this->client->request(Request::METHOD_POST, '/s/contacts/batchExport', ['filetype' => 'csv'] + $parameters);
        $this->assertResponseIsSuccessful();

        $scheduler = $this->getSchedulerRepository()->findOneBy([], ['id' => 'DESC']);
        $this->assertInstanceOf(ContactExportScheduler::class, $scheduler);

        return $scheduler;
    }

    private function getSchedulerRepository(): ContactExportSchedulerRepository
    {
        return self::getContainer()->get(ContactExportSchedulerRepository::class);
    }

    private function getExpectedBaseName(ContactExportScheduler $scheduler): string
    {
        return sprintf(
            'contacts_export_%d_%s_%d',
            $scheduler->getUser()->getId(),
            $scheduler->getScheduledDateTime()->format('Y_m_d_H_i_s'),
            $scheduler->getId()
        );
    }

    /**
     * @return array<int, array<int, string|null>>
     */
    private function readOnlyExport(): array
    {
        $files = glob($this->exportDir.'/contacts_export_*.zip') ?: [];
        $this->assertCount(1, $files);

        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($files[0]));
        $csv = (string) $zip->getFromName('contacts_export.csv');
        $zip->close();

        return array_map(str_getcsv(...), explode("\n", trim($csv)));
    }

    private function createContacts(): void
    {
        $contacts = [];
        for ($i = 1; $i <= 2; ++$i) {
            $contact = new Lead();
            $contact->setFirstname('First'.$i)->setLastname('Last'.$i)->setEmail('contact'.$i.'@example.com');
            $contacts[] = $contact;
        }

        self::getContainer()->get(LeadModel::class)->saveEntities($contacts);
    }

    private function createExportFile(int $userId): string
    {
        if (!is_dir($this->exportDir)) {
            mkdir($this->exportDir, 0777, true);
        }

        $fileName = sprintf('contacts_export_%d_2024_01_02_03_04_05_%d.zip', $userId, random_int(1, PHP_INT_MAX));
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
