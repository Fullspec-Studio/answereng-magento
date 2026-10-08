<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Observer;

use Fullspec\AnswerEng\Model\Sync\ConfigChangeHandler;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;

class ConfigSaved implements ObserverInterface
{
    /**
     * Constructor.
     *
     * @param ConfigChangeHandler $handler
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly ConfigChangeHandler $handler,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @inheritdoc
     */
    public function execute(Observer $observer): void
    {
        try {
            $this->handler->handle();
        } catch (\Throwable $e) {
            $this->logger->error('AnswerEng: change tracking failed', [
                'exception_class' => $e::class,
                'message' => mb_substr($e->getMessage(), 0, 300),
            ]);
        }
    }
}
