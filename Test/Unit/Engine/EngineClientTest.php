<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Test\Unit\Engine;

use Fullspec\AnswerEng\Model\Engine\Connection;
use Fullspec\AnswerEng\Model\Engine\EngineClient;
use Fullspec\AnswerEng\Model\Engine\TransportException;
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
}
