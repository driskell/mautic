<?php

declare(strict_types=1);

namespace Mautic\LeadBundle\Command;

use Mautic\CoreBundle\Helper\ExitCode;
use Mautic\CoreBundle\ProcessSignal\Exception\SignalCaughtException;
use Mautic\CoreBundle\ProcessSignal\ProcessSignalService;
use Mautic\CoreBundle\Twig\Helper\FormatterHelper;
use Mautic\LeadBundle\Entity\ContactExportSchedulerRepository;
use Mautic\LeadBundle\Event\ContactExportSchedulerEvent;
use Mautic\LeadBundle\LeadEvents;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

#[AsCommand(
    name: ContactScheduledExportCommand::COMMAND_NAME,
    description: 'Export contacts which are scheduled in `contact_export_scheduler` table.'
)]
class ContactScheduledExportCommand extends Command
{
    private const PICK_SCHEDULED_EXPORTS_LIMIT = 10;

    public const COMMAND_NAME                  = 'mautic:contacts:scheduled_export';

    public function __construct(
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly FormatterHelper $formatterHelper,
        private readonly ProcessSignalService $processSignalService,
        private readonly ContactExportSchedulerRepository $contactExportSchedulerRepository,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                '--ids',
                null,
                InputOption::VALUE_REQUIRED,
                'Comma separated contact_export_scheduler ids.'
            );

        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->processSignalService->registerSignalHandler(
            fn (int $signal) => $output->writeln(sprintf('Signal %d caught.', $signal))
        );

        $ids = $this->formatterHelper->simpleCsvToArray($input->getOption('ids'), 'int');

        if ([] !== $ids) {
            $contactExportSchedulers = $this->contactExportSchedulerRepository->findBy(['id' => $ids]);
        } else {
            $contactExportSchedulers = $this->contactExportSchedulerRepository
                ->findBy([], ['id' => 'ASC'], self::PICK_SCHEDULED_EXPORTS_LIMIT);
        }

        $count  = 0;
        $failed = 0;

        try {
            foreach ($contactExportSchedulers as $contactExportScheduler) {
                // Captured first, as removing the scheduler clears its id
                $schedulerId = $contactExportScheduler->getId();
                try {
                    $contactExportSchedulerEvent = new ContactExportSchedulerEvent($contactExportScheduler);
                    $this->eventDispatcher->dispatch($contactExportSchedulerEvent, LeadEvents::CONTACT_EXPORT_PREPARE_FILE);
                    $this->eventDispatcher->dispatch($contactExportSchedulerEvent, LeadEvents::CONTACT_EXPORT_SEND_EMAIL);
                    $this->eventDispatcher->dispatch($contactExportSchedulerEvent, LeadEvents::POST_CONTACT_EXPORT_SEND_EMAIL);
                    ++$count;
                } catch (SignalCaughtException $e) {
                    throw $e;
                } catch (\Throwable $e) {
                    // One failed export must not stop the others; it stays scheduled and is retried on the next run
                    ++$failed;
                    $this->logger->error('Contact export failed', [
                        'schedulerId' => $schedulerId,
                        'userId'      => $contactExportScheduler->getUser()?->getId(),
                        'exception'   => $e,
                    ]);
                }
            }

            $output->writeln('Contact export email(s) sent: '.$count);

            if ($failed > 0) {
                $output->writeln('<error>Contact export(s) failed: '.$failed.'</error>');

                return ExitCode::FAILURE;
            }

            return ExitCode::SUCCESS;
        } catch (SignalCaughtException) {
            return ExitCode::TERMINATED;
        }
    }
}
