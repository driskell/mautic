<?php

declare(strict_types=1);

namespace Mautic\LeadBundle\Tests\Model;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\CoreBundle\Helper\ExportHelper;
use Mautic\CoreBundle\Helper\UserHelper;
use Mautic\CoreBundle\Security\Permissions\CorePermissions;
use Mautic\CoreBundle\Translation\Translator;
use Mautic\EmailBundle\Helper\MailHelper;
use Mautic\LeadBundle\Entity\ContactExportScheduler;
use Mautic\LeadBundle\Entity\ContactExportSchedulerRepository;
use Mautic\LeadBundle\Entity\LeadRepository;
use Mautic\LeadBundle\Model\ContactExportSchedulerModel;
use Mautic\LeadBundle\Model\LeadModel;
use Mautic\UserBundle\Entity\User;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class ContactExportSchedulerModelTest extends TestCase
{
    public function testSendEmailFailsWhenTheEmailIsNotSent(): void
    {
        $mailer = $this->createStub(MailHelper::class);
        $mailer->method('send')->willReturn(false);
        $mailHelper = $this->createStub(MailHelper::class);
        $mailHelper->method('getMailer')->willReturn($mailer);

        $model = new ContactExportSchedulerModel(
            new RequestStack(),
            $this->createStub(LeadModel::class),
            $this->createStub(ExportHelper::class),
            $mailHelper,
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(CorePermissions::class),
            $this->createStub(EventDispatcherInterface::class),
            $this->createStub(UrlGeneratorInterface::class),
            $this->createStub(Translator::class),
            $this->createStub(UserHelper::class),
            $this->createStub(LoggerInterface::class),
            $this->createStub(CoreParametersHelper::class),
            $this->createStub(ContactExportSchedulerRepository::class),
            $this->createStub(LeadRepository::class),
        );

        $user = new User();
        $user->setEmail('user@example.com');
        $scheduler = (new ContactExportScheduler())->setUser($user);

        $this->expectException(\RuntimeException::class);
        $model->sendEmail($scheduler, '/exports/contacts_export_1_2024_01_02_03_04_05_1.zip');
    }
}
