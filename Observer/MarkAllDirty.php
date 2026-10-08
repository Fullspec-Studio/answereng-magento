<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Observer;

use Fullspec\AnswerEng\Model\Sync\ChangeTracker;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

/** Bulk changes (mass attribute/website updates, imports, category saves): mark every enabled view. */
class MarkAllDirty implements ObserverInterface
{
    public function __construct(private readonly ChangeTracker $tracker)
    {
    }

    public function execute(Observer $observer): void
    {
        $this->tracker->markAll();
    }
}
