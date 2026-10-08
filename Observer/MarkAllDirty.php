<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Observer;

use Fullspec\AnswerEng\Model\Sync\ChangeTracker;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;

/** Bulk changes (mass attribute/website updates, imports, category saves): mark every enabled view. */
class MarkAllDirty implements ObserverInterface
{
    public function __construct(
        private readonly ChangeTracker $tracker,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(Observer $observer): void
    {
        try {
            $this->tracker->markAll();
        } catch (\Throwable $e) {
            $this->logger->error('AnswerEng: change tracking failed', [
                'exception_class' => $e::class,
                'message' => mb_substr($e->getMessage(), 0, 300),
            ]);
        }
    }
}
