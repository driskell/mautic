<?php

declare(strict_types=1);

namespace Mautic\FormBundle\Event;

use Mautic\FormBundle\Entity\FormExportScheduler;
use Symfony\Contracts\EventDispatcher\Event;

final class FormExportSchedulerEvent extends Event
{
    private string $filePath;

    public function __construct(
        private readonly FormExportScheduler $formExportScheduler,
    ) {
    }

    public function getFormExportScheduler(): FormExportScheduler
    {
        return $this->formExportScheduler;
    }

    public function getFilePath(): string
    {
        return $this->filePath;
    }

    public function setFilePath(string $filePath): void
    {
        $this->filePath = $filePath;
    }
}
