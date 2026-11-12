<?php

declare(strict_types=1);

namespace Mautic\EmailBundle\Tests\Helper\Transport;

use Mautic\EmailBundle\Mailer\Message\MauticMessage;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;

/**
 * Records each recipient it sends to, and fails every send while failing is switched on.
 */
class RecordingTransport extends AbstractTransport
{
    /**
     * MailHelper::getTransport() reads the main transport from this property.
     *
     * @var array<string, self>
     */
    protected array $transports = [];

    /**
     * @var string[]
     */
    private array $recipients = [];

    private bool $failing = false;

    public function __construct()
    {
        $this->transports['main'] = $this;

        parent::__construct();
    }

    public function __toString(): string
    {
        return 'recording://';
    }

    public function setFailing(bool $failing): void
    {
        $this->failing = $failing;
    }

    /**
     * @return string[]
     */
    public function getRecipients(): array
    {
        return $this->recipients;
    }

    protected function doSend(SentMessage $message): void
    {
        if ($this->failing) {
            throw new TransportException('Transport unavailable');
        }

        $original = $message->getOriginalMessage();

        if ($original instanceof MauticMessage && [] !== $original->getMetadata()) {
            array_push($this->recipients, ...array_keys($original->getMetadata()));

            return;
        }

        array_push($this->recipients, ...array_map(
            fn (Address $address): string => $address->getAddress(),
            $message->getEnvelope()->getRecipients()
        ));
    }
}
