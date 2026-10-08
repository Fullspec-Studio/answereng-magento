<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Console\Command;

use Fullspec\AnswerEng\Model\Config\SettingsProvider;
use Fullspec\AnswerEng\Model\Sync\SyncRunner;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class Sync extends Command
{
    /**
     * Constructor.
     *
     * @param SettingsProvider $settings
     * @param SyncRunner $runner
     * @param State $appState
     */
    public function __construct(
        private readonly SettingsProvider $settings,
        private readonly SyncRunner $runner,
        private readonly State $appState
    ) {
        parent::__construct();
    }

    /**
     * @inheritdoc
     */
    protected function configure(): void
    {
        $this->setName('answereng:sync')
            ->setDescription('Push the catalog of AnswerEng-enabled store views now.')
            ->addOption('store', null, InputOption::VALUE_REQUIRED, 'Store view code (default: every enabled view)');
    }

    /**
     * @inheritdoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // bin/magento runs without an area; the catalog export's store emulation needs one.
        // Use the cron area so the CLI takes exactly the code path the cron jobs take.
        try {
            $this->appState->setAreaCode(Area::AREA_CRONTAB);
        // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock.DetectedCatch -- area already set: keep it
        } catch (LocalizedException) {
            // area already set (e.g. invoked from another command): keep it
        }
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
