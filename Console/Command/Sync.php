<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Console\Command;

use Fullspec\AnswerEng\Model\Config\SettingsProvider;
use Fullspec\AnswerEng\Model\Sync\SyncRunner;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class Sync extends Command
{
    public function __construct(
        private readonly SettingsProvider $settings,
        private readonly SyncRunner $runner
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('answereng:sync')
            ->setDescription('Push the catalog of AnswerEng-enabled store views now.')
            ->addOption('store', null, InputOption::VALUE_REQUIRED, 'Store view code (default: every enabled view)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $code = $input->getOption('store');
        $enabled = $this->settings->enabledStoreIds();
        if ($code !== null) {
            $id = $this->settings->storeIdByCode((string) $code);
            if ($id === null || !in_array($id, $enabled, true)) {
                $output->writeln("<error>Store view \"" . OutputFormatter::escape((string) $code) . "\" does not exist or is not enabled for AnswerEng.</error>");
                return Command::FAILURE;
            }
            $enabled = [$id];
        }
        $status = Command::SUCCESS;
        foreach ($enabled as $storeId) {
            try {
                $result = $this->runner->sync($storeId, 'cli', true);
            } catch (\Throwable $e) {
                $output->writeln(sprintf(
                    '<error>%s: sync failed: %s</error>',
                    OutputFormatter::escape($this->settings->storeName($storeId)),
                    OutputFormatter::escape(mb_substr($e->getMessage(), 0, 300))
                ));
                $status = Command::FAILURE;
                continue;
            }
            $output->writeln(sprintf('%s: %s', $this->settings->storeName($storeId), $result->message));
            if ($result->isError()) {
                $status = Command::FAILURE;
            }
        }
        return $status;
    }
}
