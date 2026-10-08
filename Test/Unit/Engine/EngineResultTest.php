<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Test\Unit\Engine;

use Fullspec\AnswerEng\Model\Engine\EngineResult;
use Fullspec\AnswerEng\Model\Engine\HttpResponse;
use PHPUnit\Framework\TestCase;

final class EngineResultTest extends TestCase
{
    public function testParsesCodeLimitAccountAndRetryAfter(): void
    {
        $r = EngineResult::fromResponse(new HttpResponse(403, [
            'x-answereng-account' => 'acct_' . str_repeat('a', 32),
            'retry-after' => '120',
        ], '{"error":"plan allows 1 store(s)","code":"store_limit","limit":1}'));
        self::assertFalse($r->isSuccess());
        self::assertSame('store_limit', $r->code());
        self::assertSame(1, $r->limit());
        self::assertSame('acct_' . str_repeat('a', 32), $r->account);
        self::assertSame(120, $r->retryAfter);
    }

    public function testNonJsonBodyIsNotAnError(): void
    {
        $r = EngineResult::fromResponse(new HttpResponse(502, [], '<html>Bad Gateway</html>'));
        self::assertSame(502, $r->status);
        self::assertNull($r->json);
        self::assertNull($r->code());
    }

    public function testNonNumericRetryAfterIsIgnored(): void
    {
        $r = EngineResult::fromResponse(new HttpResponse(429, ['retry-after' => 'Wed, 21 Oct 2026 07:28:00 GMT'], '{}'));
        self::assertNull($r->retryAfter);
    }

    public function testTransportFailure(): void
    {
        $r = EngineResult::transportFailure('timed out');
        self::assertNull($r->status);
        self::assertSame('timed out', $r->transportError);
        self::assertFalse($r->isSuccess());
    }
}
