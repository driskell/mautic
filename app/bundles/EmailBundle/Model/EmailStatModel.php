<?php

declare(strict_types=1);

namespace Mautic\EmailBundle\Model;

use Doctrine\DBAL\Exception\RetryableException;
use Mautic\EmailBundle\EmailEvents;
use Mautic\EmailBundle\Entity\Stat;
use Mautic\EmailBundle\Entity\StatRepository;
use Mautic\EmailBundle\Event\EmailStatEvent;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class EmailStatModel
{
    public function __construct(
        private readonly EventDispatcherInterface $dispatcher,
        private readonly StatRepository $statRepository,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function saveEntity(Stat $stat): void
    {
        $this->saveEntities([$stat]);
    }

    /**
     * @param Stat[] $stats
     */
    public function saveEntities(array $stats): void
    {
        $event = new EmailStatEvent($stats);

        $this->dispatcher->dispatch($event, EmailEvents::ON_EMAIL_STAT_PRE_SAVE);

        $this->statRepository->saveEntities($stats);

        $this->dispatcher->dispatch($event, EmailEvents::ON_EMAIL_STAT_POST_SAVE);
    }

    /**
     * Saves a segment send stat as the contact's claim on its segment email.
     *
     * @return bool false when another sender already holds the claim, so the stat was not saved
     */
    public function claimSegmentStat(Stat $stat): bool
    {
        $event = new EmailStatEvent([$stat]);

        $this->dispatcher->dispatch($event, EmailEvents::ON_EMAIL_STAT_PRE_SAVE);

        try {
            $claimed = $this->statRepository->insertSegmentStat($stat);
        } catch (RetryableException) {
            try {
                $claimed = $this->statRepository->insertSegmentStat($stat);
            } catch (RetryableException $exception) {
                $this->logger->warning('Skipped contact as the segment email claim failed twice.', [
                    'segmentEmailId' => $stat->getSegmentEmailId(),
                    'contactId'      => $stat->getLead()?->getId(),
                    'exception'      => $exception,
                ]);

                return false;
            }
        }

        if (!$claimed) {
            $this->logger->debug('Skipped contact already claimed for the segment email by another sender.', [
                'segmentEmailId' => $stat->getSegmentEmailId(),
                'contactId'      => $stat->getLead()?->getId(),
            ]);

            return false;
        }

        $this->dispatcher->dispatch($event, EmailEvents::ON_EMAIL_STAT_POST_SAVE);

        return true;
    }

    public function getRepository(): StatRepository
    {
        return $this->statRepository;
    }
}
