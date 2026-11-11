<?php

declare(strict_types=1);

namespace Mautic\FormBundle\Command;

use Mautic\CoreBundle\Helper\ExitCode;
use Mautic\CoreBundle\ProcessSignal\Exception\SignalCaughtException;
use Mautic\CoreBundle\ProcessSignal\ProcessSignalService;
use Mautic\CoreBundle\Twig\Helper\FormatterHelper;
use Mautic\FormBundle\Entity\FormExportSchedulerRepository;
use Mautic\FormBundle\Event\FormExportSchedulerEvent;
use Mautic\FormBundle\FormEvents;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

#[AsCommand(
    name: FormScheduledExportCommand::COMMAND_NAME,
    description: 'Export form results which are scheduled in the `form_export_scheduler` table.'
)]
final class FormScheduledExportCommand extends Command
{
    public const COMMAND_NAME = 'mautic:forms:scheduled_export';

    private const PICK_SCHEDULED_EXPORTS_LIMIT = 10;

    public function __construct(
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly FormatterHelper $formatterHelper,
        private readonly ProcessSignalService $processSignalService,
        private readonly FormExportSchedulerRepository $formExportSchedulerRepository,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            '--ids',
            null,
            InputOption::VALUE_REQUIRED,
            'Comma separated form_export_scheduler ids.'
        );

        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->processSignalService->registerSignalHandler(
            fn (int $signal) => $output->writeln(sprintf('Signal %d caught.', $signal))
        );

        $ids = $this->formatterHelper->simpleCsvToArray($input->getOption('ids'), 'int');

        $formExportSchedulers = [] !== $ids
            ? $this->formExportSchedulerRepository->findBy(['id' => $ids])
            : $this->formExportSchedulerRepository->findBy([], ['id' => 'ASC'], self::PICK_SCHEDULED_EXPORTS_LIMIT);

        $count  = 0;
        $failed = 0;

        try {
            foreach ($formExportSchedulers as $formExportScheduler) {
                try {
                    $event = new FormExportSchedulerEvent($formExportScheduler);
                    $this->eventDispatcher->dispatch($event, FormEvents::FORM_EXPORT_PREPARE_FILE);
                    $this->eventDispatcher->dispatch($event, FormEvents::FORM_EXPORT_SEND_EMAIL);
                    $this->eventDispatcher->dispatch($event, FormEvents::POST_FORM_EXPORT_SEND_EMAIL);
                    ++$count;
                } catch (SignalCaughtException $e) {
                    throw $e;
                } catch (\Throwable $e) {
                    // One failed export must not stop the others; it stays scheduled and is retried on the next run
                    ++$failed;
                    $this->logger->error('Form results export failed', [
                        'schedulerId' => $formExportScheduler->getId(),
                        'formId'      => $formExportScheduler->getForm()?->getId(),
                        'userId'      => $formExportScheduler->getUser()?->getId(),
                        'exception'   => $e,
                    ]);
                }
            }

            $output->writeln('Form results export email(s) sent: '.$count);

            if ($failed > 0) {
                $output->writeln('<error>Form results export(s) failed: '.$failed.'</error>');

                return ExitCode::FAILURE;
            }

            return ExitCode::SUCCESS;
        } catch (SignalCaughtException) {
            return ExitCode::TERMINATED;
        }
    }
}
