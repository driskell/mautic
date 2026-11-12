<?php

declare(strict_types=1);

namespace Mautic\EmailBundle\Tests\Functional;

use Mautic\ChannelBundle\Entity\MessageQueue;
use Mautic\ChannelBundle\Model\MessageQueueModel;
use Mautic\CoreBundle\Test\MauticMysqlTestCase;
use Mautic\EmailBundle\EmailEvents;
use Mautic\EmailBundle\Entity\Email;
use Mautic\EmailBundle\Entity\Stat;
use Mautic\EmailBundle\Event\EmailSendEvent;
use Mautic\EmailBundle\Helper\MailHelper;
use Mautic\EmailBundle\Model\EmailModel;
use Mautic\EmailBundle\Model\EmailStatModel;
use Mautic\EmailBundle\Tests\Helper\Transport\RecordingTokenTransport;
use Mautic\EmailBundle\Tests\Helper\Transport\RecordingTransport;
use Mautic\LeadBundle\Deduplicate\ContactMerger;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Entity\LeadList;
use Mautic\LeadBundle\Entity\ListLead;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Mailer\Mailer;

final class SegmentSendClaimFunctionalTest extends MauticMysqlTestCase
{
    private RecordingTransport $transport;

    protected function setUp(): void
    {
        $this->configParams['email_frequency_number'] = 0;
        parent::setUp();
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function transportModes(): iterable
    {
        yield 'Standard transport' => [false];
        yield 'Tokenising transport' => [true];
    }

    #[DataProvider('transportModes')]
    public function testSegmentSendSkipsAContactAnotherSenderClaimsDuringTheBatch(bool $tokenised): void
    {
        $emailModel = $this->useTransport($tokenised);
        $first      = $this->createContact('first@example.com');
        $claimed    = $this->createContact('claimed@example.com');
        $third      = $this->createContact('third@example.com');
        $segment    = $this->createSegment([$first, $claimed, $third]);
        $email      = $this->createEmail('Newsletter', $segment);
        $email->setVariantStartDate(new \DateTime('-1 day'));
        $this->em->flush();

        $this->claimWhenSending($claimed, $email, $email);

        $emailModel->sendEmailToLists($email);

        $this->assertRecipients(['first@example.com', 'third@example.com']);
        $this->assertSame([$email->getId()], $this->fetchSegmentEmailIds($claimed), 'Only the other sender\'s claim may exist.');
        $this->assertSame(['sent_count' => 2, 'variant_sent_count' => 2], $this->fetchSentCounts($email));
    }

    #[DataProvider('transportModes')]
    public function testVariantsAndTheirTranslationsClaimContactsUnderTheRootEmail(bool $tokenised): void
    {
        $emailModel   = $this->useTransport($tokenised);
        $translated   = $this->createContact('translated@example.com', 'pt_PT');
        $claimed      = $this->createContact('claimed@example.com', 'pt_PT');
        $default      = $this->createContact('default@example.com');
        $segment      = $this->createSegment([$translated, $claimed, $default]);
        $root         = $this->createEmail('Root', $segment);
        $variant      = $this->createEmail('Variant', $segment, variantParent: $root);
        $translation  = $this->createEmail('Variant PT', $segment, translationParent: $variant, language: 'pt_PT');

        // The other sender works through the root email while this one sends the variant.
        $this->claimWhenSending($claimed, $root, $root);

        // Reload so the translation collections are indexed by ID, as they are when loaded for a send.
        $this->em->clear();
        $variantToSend = $this->em->find(Email::class, $variant->getId());
        $this->assertInstanceOf(Email::class, $variantToSend);

        $emailModel->sendEmailToLists($variantToSend);

        $this->assertRecipients(['default@example.com', 'translated@example.com']);
        $this->assertSame([$root->getId()], $this->fetchSegmentEmailIds($claimed));
        $this->assertSame(
            [
                ['email_id' => $variant->getId(), 'segment_email_id' => $root->getId()],
            ],
            $this->fetchStats($default)
        );
        $this->assertSame(
            [
                ['email_id' => $translation->getId(), 'segment_email_id' => $root->getId()],
            ],
            $this->fetchStats($translated)
        );
    }

    #[DataProvider('transportModes')]
    public function testClaimedStatStoresTheTokensAndCopy(bool $tokenised): void
    {
        $emailModel = $this->useTransport($tokenised);
        $contact    = $this->createContact('contact@example.com');
        $segment    = $this->createSegment([$contact]);
        $email      = $this->createEmail('Newsletter', $segment);

        $emailModel->sendEmailToLists($email);

        $this->em->clear();
        $stats = $this->em->getRepository(Stat::class)->findBy(['lead' => $contact->getId()]);
        $this->assertCount(1, $stats);
        $stat = $stats[0];

        $this->assertSame($email->getId(), $stat->getSegmentEmailId());
        $this->assertSame($email->getId(), $stat->getEmail()?->getId());
        $this->assertSame($segment->getId(), $stat->getList()?->getId());
        $this->assertSame('email', $stat->getSource());
        $this->assertSame($email->getId(), $stat->getSourceId());
        $this->assertSame('contact@example.com', $stat->getEmailAddress());
        $this->assertNotEmpty($stat->getTrackingHash());
        $this->assertArrayHasKey('{tracking_pixel}', $stat->getTokens());
        $this->assertNotNull($stat->getStoredCopy());
        $this->assertSame('Newsletter Subject', $stat->getStoredCopy()->getSubject());
    }

    #[DataProvider('transportModes')]
    public function testFailedSendReleasesTheClaimForALaterBatch(bool $tokenised): void
    {
        $emailModel = $this->useTransport($tokenised);
        $contact    = $this->createContact('contact@example.com');
        $segment    = $this->createSegment([$contact]);
        $email      = $this->createEmail('Newsletter', $segment);

        $this->transport->setFailing(true);
        $emailModel->sendEmailToLists($email);

        $this->assertSame([], $this->fetchStats($contact));

        $this->transport->setFailing(false);
        $emailModel->sendEmailToLists($email);

        $this->assertRecipients(['contact@example.com']);
        $this->assertSame(
            [
                ['email_id' => $email->getId(), 'segment_email_id' => $email->getId()],
            ],
            $this->fetchStats($contact)
        );
    }

    public function testApiSendOfASegmentEmailClaimsUnderTheRootEmail(): void
    {
        $emailModel = $this->useTransport(false);
        $claimed    = $this->createContact('claimed@example.com');
        $unclaimed  = $this->createContact('unclaimed@example.com');
        $segment    = $this->createSegment([$claimed, $unclaimed]);
        $root       = $this->createEmail('Root', $segment);
        $variant    = $this->createEmail('Variant', $segment, variantParent: $root);
        $this->insertStat($claimed, $root, $root->getId());

        $emailModel->sendEmail($variant, [$this->toContactArray($claimed)], ['source' => ['api', 0]]);
        $emailModel->sendEmail($variant, [$this->toContactArray($unclaimed)], ['source' => ['api', 0]]);
        $emailModel->sendEmail($variant, [$this->toContactArray($unclaimed)], ['source' => ['api', 0]]);

        $this->assertRecipients(['unclaimed@example.com']);
        $this->assertSame([['email_id' => $root->getId(), 'segment_email_id' => $root->getId()]], $this->fetchStats($claimed));
        $this->assertSame([['email_id' => $variant->getId(), 'segment_email_id' => $root->getId()]], $this->fetchStats($unclaimed));
    }

    public function testApiSendOfASegmentEmailSkipsAContactWhoReceivedItWithoutAClaim(): void
    {
        $emailModel = $this->useTransport(false);
        $contact    = $this->createContact('contact@example.com');
        $segment    = $this->createSegment([$contact]);
        $email      = $this->createEmail('Newsletter', $segment);
        $this->insertStat($contact, $email, null);

        $emailModel->sendEmail($email, [$this->toContactArray($contact)], ['source' => ['api', 0]]);

        $this->assertRecipients([]);
        $this->assertSame([['email_id' => $email->getId(), 'segment_email_id' => null]], $this->fetchStats($contact));
    }

    public function testBouncedStatReleasesItsClaimForAResend(): void
    {
        $emailModel = $this->useTransport(false);
        $contact    = $this->createContact('contact@example.com');
        $segment    = $this->createSegment([$contact]);
        $email      = $this->createEmail('Newsletter', $segment);
        $emailModel->sendEmailToLists($email);

        $stat = $this->em->getRepository(Stat::class)->findOneBy(['lead' => $contact->getId()]);
        $this->assertInstanceOf(Stat::class, $stat);
        $stat->setIsFailed(true);
        $emailStatModel = self::getContainer()->get(EmailStatModel::class);
        $this->assertInstanceOf(EmailStatModel::class, $emailStatModel);
        $emailStatModel->saveEntity($stat);

        $emailModel->sendEmail($email, [$this->toContactArray($contact)], ['source' => ['api', 0]]);

        $this->assertRecipients(['contact@example.com', 'contact@example.com']);
        $this->assertSame(
            [
                ['email_id' => $email->getId(), 'segment_email_id' => null],
                ['email_id' => $email->getId(), 'segment_email_id' => $email->getId()],
            ],
            $this->fetchStats($contact)
        );
    }

    public function testQueuedResendOfASegmentEmailSkipsAContactClaimedSinceItWasQueued(): void
    {
        $this->useTransport(false);
        $claimed   = $this->createContact('claimed@example.com');
        $unclaimed = $this->createContact('unclaimed@example.com');
        $segment   = $this->createSegment([$claimed, $unclaimed]);
        $email     = $this->createEmail('Newsletter', $segment);
        $messages  = [];
        foreach ([$claimed, $unclaimed] as $contact) {
            $message                     = $this->createQueuedMessage($email, $contact);
            $messages[$message->getId()] = $message;
        }
        $this->insertStat($claimed, $email, $email->getId());

        $messageQueueModel = self::getContainer()->get(MessageQueueModel::class);
        $this->assertInstanceOf(MessageQueueModel::class, $messageQueueModel);
        $messageQueueModel->processMessageQueue($messages);

        $this->assertRecipients(['unclaimed@example.com']);
        $this->assertCount(1, $this->fetchStats($claimed));
        $this->assertSame([['email_id' => $email->getId(), 'segment_email_id' => $email->getId()]], $this->fetchStats($unclaimed));
    }

    public function testCampaignSendOfATemplateEmailRepeats(): void
    {
        $emailModel = $this->useTransport(false);
        $contact    = $this->createContact('contact@example.com');
        $email      = $this->createEmail('Template', null, type: 'template');

        $emailModel->sendEmail($email, [$this->toContactArray($contact)], ['source' => ['campaign.event', 1]]);
        $emailModel->sendEmail($email, [$this->toContactArray($contact)], ['source' => ['campaign.event', 1]]);

        $this->assertRecipients(['contact@example.com', 'contact@example.com']);
        $this->assertSame(
            [
                ['email_id' => $email->getId(), 'segment_email_id' => null],
                ['email_id' => $email->getId(), 'segment_email_id' => null],
            ],
            $this->fetchStats($contact)
        );
    }

    public function testMergingContactsClaimedForTheSameEmailKeepsBothStats(): void
    {
        $winner      = $this->createContact('winner@example.com');
        $loser       = $this->createContact('loser@example.com');
        $segment     = $this->createSegment([$winner, $loser]);
        $sharedEmail = $this->createEmail('Shared', $segment);
        $loserEmail  = $this->createEmail('Loser only', $segment);
        $this->insertStat($winner, $sharedEmail, $sharedEmail->getId());
        $this->insertStat($loser, $sharedEmail, $sharedEmail->getId());
        $this->insertStat($loser, $loserEmail, $loserEmail->getId());

        $contactMerger = self::getContainer()->get(ContactMerger::class);
        $this->assertInstanceOf(ContactMerger::class, $contactMerger);
        $contactMerger->merge($winner, $loser);

        $this->assertSame(
            [
                ['email_id' => $sharedEmail->getId(), 'segment_email_id' => $sharedEmail->getId()],
                ['email_id' => $sharedEmail->getId(), 'segment_email_id' => null],
                ['email_id' => $loserEmail->getId(), 'segment_email_id' => $loserEmail->getId()],
            ],
            $this->fetchStats($winner)
        );
    }

    private function useTransport(bool $tokenised): EmailModel
    {
        $this->transport = $tokenised ? new RecordingTokenTransport() : new RecordingTransport();
        self::getContainer()->set('mailer.mailer', new Mailer($this->transport));

        $mailHelper = self::getContainer()->get(MailHelper::class);
        $this->assertInstanceOf(MailHelper::class, $mailHelper);
        $this->assertSame($tokenised, $mailHelper->inTokenizationMode(), 'The test transport must be the one in use.');

        $emailModel = self::getContainer()->get(EmailModel::class);
        $this->assertInstanceOf(EmailModel::class, $emailModel);

        return $emailModel;
    }

    /**
     * Claims the contact as another sender would, at the moment this sender reaches it.
     */
    private function claimWhenSending(Lead $contact, Email $sentEmail, Email $segmentEmail): void
    {
        $claimed    = false;
        $dispatcher = self::getContainer()->get(EventDispatcherInterface::class);
        $dispatcher->addListener(
            EmailEvents::EMAIL_ON_SEND,
            function (EmailSendEvent $event) use ($contact, $sentEmail, $segmentEmail, &$claimed): void {
                if ($claimed || (int) ($event->getLead()['id'] ?? 0) !== $contact->getId()) {
                    return;
                }

                $claimed = true;
                $this->insertStat($contact, $sentEmail, $segmentEmail->getId());
            }
        );
    }

    private function createQueuedMessage(Email $email, Lead $contact): MessageQueue
    {
        $message = new MessageQueue();
        $message->setScheduledDate(new \DateTime('-1 minute'));
        $message->setDatePublished(new \DateTime('-1 hour'));
        $message->setChannel('email');
        $message->setChannelId($email->getId());
        $message->setLead($contact);
        $message->setPriority(MessageQueue::PRIORITY_NORMAL);
        $message->setMaxAttempts(3);
        $message->setAttempts(0);
        $message->setStatus(MessageQueue::STATUS_PENDING);
        $this->em->persist($message);
        $this->em->flush();

        return $message;
    }

    private function insertStat(Lead $contact, Email $email, ?int $segmentEmailId): void
    {
        $this->connection->insert(MAUTIC_TABLE_PREFIX.Stat::TABLE_NAME, [
            'email_id'          => $email->getId(),
            'lead_id'           => $contact->getId(),
            'email_address'     => $contact->getEmail(),
            'date_sent'         => (new \DateTime())->format('Y-m-d H:i:s'),
            'is_read'           => 0,
            'is_failed'         => 0,
            'viewed_in_browser' => 0,
            'segment_email_id'  => $segmentEmailId,
        ]);
    }

    /**
     * @param string[] $expected
     */
    private function assertRecipients(array $expected): void
    {
        $recipients = $this->transport->getRecipients();
        sort($recipients);

        $this->assertSame($expected, $recipients);
    }

    /**
     * @return int[]
     */
    private function fetchSegmentEmailIds(Lead $contact): array
    {
        return array_map('intval', $this->connection->fetchFirstColumn(
            'SELECT segment_email_id FROM '.MAUTIC_TABLE_PREFIX.Stat::TABLE_NAME.' WHERE lead_id = ? ORDER BY id',
            [$contact->getId()]
        ));
    }

    /**
     * @return array<int, array{email_id: int, segment_email_id: int|null}>
     */
    private function fetchStats(Lead $contact): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT email_id, segment_email_id FROM '.MAUTIC_TABLE_PREFIX.Stat::TABLE_NAME.' WHERE lead_id = ? ORDER BY id',
            [$contact->getId()]
        );

        return array_map(fn (array $row): array => [
            'email_id'         => (int) $row['email_id'],
            'segment_email_id' => null === $row['segment_email_id'] ? null : (int) $row['segment_email_id'],
        ], $rows);
    }

    /**
     * @return array{sent_count: int, variant_sent_count: int}
     */
    private function fetchSentCounts(Email $email): array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT sent_count, variant_sent_count FROM '.MAUTIC_TABLE_PREFIX.'emails WHERE id = ?',
            [$email->getId()]
        );
        $this->assertIsArray($row);

        return ['sent_count' => (int) $row['sent_count'], 'variant_sent_count' => (int) $row['variant_sent_count']];
    }

    /**
     * @return array<string, mixed>
     */
    private function toContactArray(Lead $contact): array
    {
        return [
            'id'        => $contact->getId(),
            'email'     => $contact->getEmail(),
            'firstname' => '',
            'lastname'  => '',
        ];
    }

    private function createContact(string $emailAddress, ?string $preferredLocale = null): Lead
    {
        $contact = new Lead();
        $contact->setEmail($emailAddress);
        $this->em->persist($contact);
        $this->em->flush();

        if (null !== $preferredLocale) {
            $this->connection->update(MAUTIC_TABLE_PREFIX.'leads', ['preferred_locale' => $preferredLocale], ['id' => $contact->getId()]);
        }

        return $contact;
    }

    /**
     * @param Lead[] $contacts
     */
    private function createSegment(array $contacts): LeadList
    {
        $segment = new LeadList();
        $segment->setName('Segment');
        $segment->setPublicName('Segment');
        $segment->setAlias('segment');
        $this->em->persist($segment);

        foreach ($contacts as $contact) {
            $membership = new ListLead();
            $membership->setLead($contact);
            $membership->setList($segment);
            // Membership must predate the email's publish up date for the contact to be pending.
            $membership->setDateAdded(new \DateTime('-1 week'));
            $this->em->persist($membership);
        }

        $this->em->flush();

        return $segment;
    }

    private function createEmail(
        string $name,
        ?LeadList $segment,
        ?Email $variantParent = null,
        ?Email $translationParent = null,
        string $language = 'en',
        string $type = 'list',
    ): Email {
        $email = new Email();
        $email->setName($name);
        $email->setSubject($name.' Subject');
        $email->setCustomHtml('<html><body>'.$name.' content</body></html>');
        $email->setEmailType($type);
        $email->setLanguage($language);
        $email->setPublishUp(new \DateTime('-1 day'));
        $email->setIsPublished(true);

        if (null !== $segment) {
            $email->addList($segment);
        }

        if (null !== $variantParent) {
            $email->setVariantParent($variantParent);
            $variantParent->addVariantChild($email);
        }

        if (null !== $translationParent) {
            $email->setTranslationParent($translationParent);
            $translationParent->addTranslationChild($email);
        }

        $this->em->persist($email);
        $this->em->flush();

        return $email;
    }
}
