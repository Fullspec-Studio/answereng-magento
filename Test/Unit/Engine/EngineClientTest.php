<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Test\Unit\Engine;

use Fullspec\AnswerEng\Model\Engine\Connection;
use Fullspec\AnswerEng\Model\Engine\EngineClient;
use Fullspec\AnswerEng\Model\Engine\ReadBudget;
use Fullspec\AnswerEng\Model\Engine\TransportException;
use Fullspec\AnswerEng\Model\Support\Clock;
use Fullspec\AnswerEng\Test\Support\FixedClock;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class EngineClientTest extends TestCase
{
    private FakeTransport $transport;
    private EngineClient $client;
    private Connection $conn;

    protected function setUp(): void
    {
        $this->transport = new FakeTransport();
        $this->client = new EngineClient($this->transport, new NullLogger());
        $this->conn = new Connection('https://engine.test/', 'ae_secret');
    }

    public function testPostCatalogSendsKeyJsonAndTrimsTheBaseUrl(): void
    {
        $this->transport->enqueue(FakeTransport::json(200, ['store_id' => 7]));
        $r = $this->client->postCatalog($this->conn, '{"store_key":"s"}');
        self::assertTrue($r->isSuccess());
        $req = $this->transport->requests[0];
        self::assertSame(['POST', 'https://engine.test/catalog'], [$req['method'], $req['url']]);
        self::assertSame('ae_secret', $req['headers']['x-api-key']);
        self::assertSame('application/json', $req['headers']['Content-Type']);
        self::assertSame('{"store_key":"s"}', $req['body']);
    }

    public function testStoreKeysAreUrlEncodedInPaths(): void
    {
        $this->transport->enqueue(FakeTransport::json(200, []))
            ->enqueue(FakeTransport::json(202, []))
            ->enqueue(FakeTransport::json(200, []));
        $this->client->clearCatalog($this->conn, 'shop.example.com');
        $this->client->deleteStore($this->conn, 'a b/c');
        $this->client->restoreStore($this->conn, 'shop.example.com');
        $urls = array_column($this->transport->requests, 'url');
        self::assertSame([
            'https://engine.test/stores/shop.example.com/catalog',
            'https://engine.test/stores/a%20b%2Fc',
            'https://engine.test/stores/shop.example.com/restore',
        ], $urls);
        self::assertSame('DELETE', $this->transport->requests[1]['method']);
        self::assertSame('{"confirm_store_key":"a b\/c"}', $this->transport->requests[1]['body']);
    }

    public function testGetsRetryOnceOn5xxAndTransportErrors(): void
    {
        $this->transport->enqueue(FakeTransport::json(504, null))
            ->enqueue(FakeTransport::json(200, [['store_key' => 's']]));
        self::assertTrue($this->client->listStores($this->conn)->isSuccess());

        $this->transport->enqueue(new TransportException('reset'))
            ->enqueue(FakeTransport::json(200, ['store_key' => 's']));
        self::assertTrue($this->client->summary($this->conn, 's')->isSuccess());
        self::assertCount(4, $this->transport->requests);
    }

    public function testWritesNeverRetry(): void
    {
        $this->transport->enqueue(FakeTransport::json(504, null));
        self::assertSame(504, $this->client->postCatalog($this->conn, '{}')->status);
        self::assertCount(1, $this->transport->requests);
    }

    public function testTransportErrorBecomesAResult(): void
    {
        $this->transport->enqueue(new TransportException('connection refused'));
        $r = $this->client->postCatalog($this->conn, '{}');
        self::assertSame('connection refused', $r->transportError);
    }

    public function testTransportErrorTextNeverCarriesTheKey(): void
    {
        $logger = new class extends \Psr\Log\AbstractLogger {
            public string $all = '';

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->all .= $message . json_encode($context);
            }
        };
        $client = new EngineClient($this->transport, $logger);
        $this->transport->enqueue(new TransportException('"ae_secret" is not valid header value.'));
        $r = $client->postCatalog($this->conn, '{}');
        self::assertSame('"[api key]" is not valid header value.', $r->transportError);
        self::assertStringNotContainsString('ae_secret', $logger->all);
    }

    public function testHangingEngineStopsWithinTheBudget(): void
    {
        $clock = new FixedClock();
        $budget = new ReadBudget($clock);
        $this->transport->onSend = function (string $method, string $url, int $timeout) use ($clock): void {
            $clock->advance($timeout);
        };
        $this->transport->enqueue(new TransportException('timed out'))
            ->enqueue(new TransportException('timed out'));
        $summary = $this->client->summary($this->conn, 'shop.example.com', $budget);
        $fixes = $this->client->fixes($this->conn, 'shop.example.com', 'suggested', $budget);
        $again = $this->client->fixes($this->conn, 'shop.example.com', 'applied', $budget);
        self::assertTrue($summary->isTransient());
        self::assertTrue($fixes->isTransient());
        self::assertTrue($again->isTransient());
        self::assertSame([15, 5], array_column($this->transport->requests, 'timeout'));
        self::assertLessThanOrEqual(ReadBudget::TOTAL, array_sum(array_column($this->transport->requests, 'timeout')));
    }

    public function testRetryOnlyWhenFiveSecondsRemain(): void
    {
        $clock = new FixedClock();
        $budget = new ReadBudget($clock);
        $clock->advance(6);
        $this->transport->onSend = function (string $method, string $url, int $timeout) use ($clock): void {
            $clock->advance(10);
        };
        $this->transport->enqueue(FakeTransport::json(503, null));
        $r = $this->client->summary($this->conn, 's', $budget);
        self::assertSame(503, $r->status);
        self::assertCount(1, $this->transport->requests);
        self::assertSame(14, $this->transport->requests[0]['timeout']);
        self::assertTrue($budget->stopped());

        $clock2 = new FixedClock();
        $budget2 = new ReadBudget($clock2);
        $transport2 = new FakeTransport();
        $client2 = new EngineClient($transport2, new NullLogger());
        $transport2->onSend = function (string $method, string $url, int $timeout) use ($clock2): void {
            $clock2->advance(6);
        };
        $transport2->enqueue(FakeTransport::json(503, null))->enqueue(FakeTransport::json(200, ['ok' => true]));
        self::assertTrue($client2->summary($this->conn, 's', $budget2)->isSuccess());
        self::assertSame([15, 14], array_column($transport2->requests, 'timeout'));
        self::assertFalse($budget2->stopped());
    }

    public function testNonTransientReadDoesNotStopTheBudget(): void
    {
        $budget = new ReadBudget(new FixedClock());
        $this->transport->enqueue(FakeTransport::json(404, ['code' => 'store_not_found']));
        $r = $this->client->summary($this->conn, 's', $budget);
        self::assertFalse($r->isTransient());
        self::assertFalse($budget->stopped());
        self::assertCount(1, $this->transport->requests);
    }

    public function testRateLimitIsTransient(): void
    {
        $budget = new ReadBudget(new FixedClock());
        $this->transport->enqueue(FakeTransport::json(429, null))->enqueue(FakeTransport::json(429, null));
        self::assertTrue($this->client->fixes($this->conn, 's', 'suggested', $budget)->isTransient());
        self::assertTrue($budget->stopped());
    }

    public function testFixesPathAndStatus(): void
    {
        $this->transport->enqueue(FakeTransport::json(200, ['fixes' => []]));
        $this->client->fixes($this->conn, 'shop.example.com', 'applied');
        self::assertSame(
            'https://engine.test/stores/shop.example.com/fixes?status=applied',
            $this->transport->requests[0]['url']
        );
    }

    public function testMarkAndUnmarkAppliedAreSingleWrites(): void
    {
        $this->transport->enqueue(FakeTransport::json(503, null))->enqueue(FakeTransport::json(503, null));
        $a = $this->client->markApplied($this->conn, 'shop.example.com', 118);
        $b = $this->client->unmarkApplied($this->conn, 'shop.example.com', 118);
        self::assertSame(503, $a->status);
        self::assertSame(503, $b->status);
        self::assertCount(2, $this->transport->requests);
        self::assertSame(['POST', 'https://engine.test/stores/shop.example.com/fixes/118/applied', 30],
            [$this->transport->requests[0]['method'], $this->transport->requests[0]['url'],
                $this->transport->requests[0]['timeout']]);
        self::assertSame(['DELETE', 'https://engine.test/stores/shop.example.com/fixes/118/applied'],
            [$this->transport->requests[1]['method'], $this->transport->requests[1]['url']]);
    }

    public function testNothingIsSentWhenTheBudgetIsSpentAtSendTime(): void
    {
        $clock = new FixedClock();
        $budget = new ReadBudget($clock);
        $clock->advance(20);
        $r = $this->client->summary($this->conn, 's', $budget);
        self::assertTrue($r->isTransient());
        self::assertSame([], $this->transport->requests);
        self::assertTrue($budget->stopped());
    }

    public function testNoZeroTimeoutReachesTheTransportWhenTheSecondTicksOver(): void
    {
        // stopped() sees 1 s left, attemptTimeout() then sees 0: the attempt must not be sent.
        $clock = new class implements Clock {
            private int $reads = 0;
            public function now(): \DateTimeImmutable
            {
                $this->reads++;
                // construction, stopped(): the start; attemptTimeout(): 20 s later
                return (new \DateTimeImmutable('2026-10-07 12:00:00', new \DateTimeZone('UTC')))
                    ->modify($this->reads > 2 ? '+20 seconds' : '+0 seconds');
            }
        };
        $budget = new ReadBudget($clock);
        $r = $this->client->summary($this->conn, 's', $budget);
        self::assertTrue($r->isTransient());
        self::assertSame([], $this->transport->requests);
    }
}
