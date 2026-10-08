<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Model\Sync;

interface SyncRunner
{
    /** $manual = an explicit merchant action (Sync now / CLI), which bypasses cron selection. */
    public function sync(int $storeId, string $source, bool $manual = false): SyncResult;
}
