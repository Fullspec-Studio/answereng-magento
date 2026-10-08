<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Test\Support;

use Fullspec\AnswerEng\Model\Sync\SyncResult;
use Fullspec\AnswerEng\Model\Sync\SyncRunner;

final class FakeRunner implements SyncRunner
{
    /** @var list<array{int,string,bool}> */
    public array $calls = [];
    public SyncResult $result;
    /** @var list<int> */
    public array $throwFor = [];

    public function __construct()
    {
        $this->result = new SyncResult(SyncResult::PUSHED, 'Sent 1 products to AnswerEng.');
    }

    public function sync(int $storeId, string $source, bool $manual = false): SyncResult
    {
        $this->calls[] = [$storeId, $source, $manual];
        if (in_array($storeId, $this->throwFor, true)) {
            throw new \RuntimeException('boom');
        }
        return $this->result;
    }
}
