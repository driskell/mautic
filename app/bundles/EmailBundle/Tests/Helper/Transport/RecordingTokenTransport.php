<?php

declare(strict_types=1);

namespace Mautic\EmailBundle\Tests\Helper\Transport;

use Mautic\EmailBundle\Mailer\Transport\TokenTransportInterface;
use Mautic\EmailBundle\Mailer\Transport\TokenTransportTrait;

/**
 * A recording transport that batches contacts with tokens, so MailHelper runs in tokenisation mode.
 */
final class RecordingTokenTransport extends RecordingTransport implements TokenTransportInterface
{
    use TokenTransportTrait;

    public function getMaxBatchLimit(): int
    {
        return 100;
    }
}
