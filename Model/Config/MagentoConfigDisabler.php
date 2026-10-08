<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Model\Config;

use Fullspec\AnswerEng\Model\Sync\ConfigChangeHandler;
use Magento\Framework\App\Config\ReinitableConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Store\Model\ScopeInterface;

class MagentoConfigDisabler implements ConfigDisabler
{
    /**
     * Constructor.
     *
     * @param WriterInterface $writer
     * @param ReinitableConfigInterface $config
     * @param ConfigChangeHandler $changes
     */
    public function __construct(
        private readonly WriterInterface $writer,
        private readonly ReinitableConfigInterface $config,
        private readonly ConfigChangeHandler $changes
    ) {
    }

    /**
     * @inheritdoc
     */
    public function disable(int $storeId): void
    {
        $this->writer->save(ConfigSettingsProvider::PATH_ENABLED, '0', ScopeInterface::SCOPE_STORES, $storeId);
        $this->config->reinit();
        $this->changes->handle();
    }
}
