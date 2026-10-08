<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Test\Unit\Sync;

use Fullspec\AnswerEng\Model\Engine\EngineResult;
use Fullspec\AnswerEng\Model\Engine\HttpResponse;
use Fullspec\AnswerEng\Model\Sync\Classifier;
use Fullspec\AnswerEng\Model\Sync\OutcomeKind;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ClassifierTest extends TestCase
{
    private function res(int $status, string $body = '', array $headers = []): EngineResult
    {
        return EngineResult::fromResponse(new HttpResponse($status, $headers, $body));
    }

    /** @return array<string,array{0:EngineResult,1:OutcomeKind,2:?string}> */
    public static function cases(): array
    {
        $r = fn (int $s, string $b = '', array $h = []) =>
            EngineResult::fromResponse(new HttpResponse($s, $h, $b));
        return [
            'success' => [$r(200, '{"store_id":1}'), OutcomeKind::Success, null],
            'transport' => [EngineResult::transportFailure('timeout'), OutcomeKind::Transient, null],
            'html 502' => [$r(502, '<html>Bad Gateway</html>'), OutcomeKind::Transient, null],
            'run in progress' => [$r(409, '{"code":"run_in_progress"}'), OutcomeKind::Transient, 'run_in_progress'],
            'invalid key' => [$r(403, '{"code":"invalid_key"}'), OutcomeKind::Paused, 'invalid_key'],
            'gateway forbidden' => [$r(403, '{"error":"Forbidden","code":"invalid_key"}'), OutcomeKind::Paused, 'invalid_key'],
            'store limit' => [$r(403, '{"code":"store_limit","limit":1}'), OutcomeKind::Paused, 'store_limit'],
            'product limit' => [$r(403, '{"code":"product_limit","limit":5000}'), OutcomeKind::Paused, 'product_limit'],
            'owned elsewhere' => [$r(403, '{"code":"store_owned_elsewhere"}'), OutcomeKind::Paused, 'store_owned_elsewhere'],
            'pending deletion' => [$r(409, '{"code":"store_pending_deletion","purge_after":"2026-10-14T06:00:00+00:00"}'),
                OutcomeKind::Paused, 'store_pending_deletion'],
            'validation' => [$r(422, '{"code":"validation_failed","errors":[]}'), OutcomeKind::Paused, 'validation_failed'],
            'missing code' => [$r(403, '{"error":"nope"}'), OutcomeKind::Paused, 'unexpected'],
            'unknown 4xx' => [$r(418, '{}'), OutcomeKind::Paused, 'unexpected'],
        ];
    }

    #[DataProvider('cases')]
    public function testClassification(EngineResult $result, OutcomeKind $kind, ?string $reason): void
    {
        $o = (new Classifier())->classify($result);
        self::assertSame($kind, $o->kind);
        self::assertSame($reason, $o->reason);
    }

    public function testThrottleWaitsAtLeastFiveMinutes(): void
    {
        $c = new Classifier();
        $short = $c->classify($this->res(429, '{"code":"throttled"}', ['retry-after' => '30']));
        $long = $c->classify($this->res(429, '{"code":"throttled"}', ['retry-after' => '900']));
        $none = $c->classify($this->res(429, '{"code":"quota_exceeded"}'));
        self::assertSame(OutcomeKind::Throttled, $short->kind);
        self::assertSame([300, 900, 300], [$short->retryAfterSeconds, $long->retryAfterSeconds, $none->retryAfterSeconds]);
    }

    public function testUnknownStoreOnClearMeansAlreadyClear(): void
    {
        $c = new Classifier();
        self::assertSame(OutcomeKind::AlreadyClear,
            $c->classify($this->res(404, '{"code":"unknown_store"}'), true)->kind);
        self::assertSame(OutcomeKind::Paused,
            $c->classify($this->res(404, '{"code":"unknown_store"}'))->kind);
    }

    public function testLimitAndPurgeAfterAreCarried(): void
    {
        $c = new Classifier();
        self::assertSame(1, $c->classify($this->res(403, '{"code":"store_limit","limit":1}'))->limit);
        self::assertSame('2026-10-14T06:00:00+00:00',
            $c->classify($this->res(409, '{"code":"store_pending_deletion","purge_after":"2026-10-14T06:00:00+00:00"}'))->purgeAfter);
    }
}
