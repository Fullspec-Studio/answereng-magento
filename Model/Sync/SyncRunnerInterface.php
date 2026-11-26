<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Model\Sync;

interface SyncRunnerInterface
{
    /**
     * $manual = an explicit merchant action (Sync now / CLI), which bypasses cron selection.
     *
     * @param int $storeId
     * @param string $source
     * @param bool $manual
     */
    public function sync(int $storeId, string $source, bool $manual = false): SyncResult;
}
