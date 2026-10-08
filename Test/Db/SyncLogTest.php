<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Test\Db;

use Fullspec\AnswerEng\Model\Sync\SyncLog;

final class SyncLogTest extends DbTestCase
{
    public function testKeepsTheNewestTwentyPerViewNewestFirst(): void
    {
        $log = new SyncLog($this->db);
        $t = new \DateTimeImmutable('2026-10-07 12:00:00', new \DateTimeZone('UTC'));
        for ($i = 1; $i <= 25; $i++) {
            $log->append(1, $t->modify("+$i seconds"), 'debounce', "pushed-$i");
        }
        $log->append(2, $t, 'manual', 'pushed', null, 'admin');
        $rows = $log->recent(1);
        self::assertCount(20, $rows);
        self::assertSame('pushed-25', $rows[0]['outcome']);
        self::assertSame('pushed-6', $rows[19]['outcome']);
        self::assertSame('admin', $log->recent(2)[0]['admin_user']);
    }

    public function testDetailIsTruncatedTo1000Characters(): void
    {
        $log = new SyncLog($this->db);
        $log->append(1, new \DateTimeImmutable('2026-10-07 12:00:00'), 'debounce', 'paused', str_repeat('x', 1500));
        self::assertSame(1000, mb_strlen((string) $log->recent(1)[0]['detail']));
    }
}
