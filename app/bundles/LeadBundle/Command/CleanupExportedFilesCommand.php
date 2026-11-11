<?php

declare(strict_types=1);

namespace Mautic\LeadBundle\Command;

use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\CoreBundle\Helper\DateTimeHelper;
use Mautic\CoreBundle\Helper\ExitCode;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: self::COMMAND_NAME,
    description: 'Remove contact and form result export files older than clear_export_files_after_days'
)]
final class CleanupExportedFilesCommand extends Command
{
    public const COMMAND_NAME = 'mautic:contacts:cleanup_exported_files';

    /**
     * @var string
     */
    private const CLEANUP_DAYS = 'cleanupAfterDays';

    public function __construct(
        private readonly CoreParametersHelper $coreParametersHelper,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument(self::CLEANUP_DAYS, InputArgument::OPTIONAL, 'Remove exported files after days');

        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $days = $input->getArgument(self::CLEANUP_DAYS);
        if (!$days) {
            $days = $this->coreParametersHelper->get('clear_export_files_after_days');
        }

        $dateHelper       = new DateTimeHelper();
        $date             = $dateHelper->getUtcDateTime()->modify('-'.(int) $days.' days');
        $cleanUpTimestamp = $date->getTimestamp();

        $downloadFolder          = $this->coreParametersHelper->get('contact_export_dir');
        $contactExportedAllFiles = glob($downloadFolder.'/contacts_export_*');
        $formExportedAllFiles    = glob($this->coreParametersHelper->get('form_export_dir').'/form_results_export_*') ?: [];

        foreach (array_merge($contactExportedAllFiles, $formExportedAllFiles) as $file) {
            if (filectime($file) <= $cleanUpTimestamp) {
                @unlink($file);
            }
        }

        return ExitCode::SUCCESS;
    }
}
