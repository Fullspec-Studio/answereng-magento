<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Test\Unit\Engine;

use Fullspec\AnswerEng\Model\Engine\GuzzleTransport;
use Fullspec\AnswerEng\Model\Engine\TransportException;
use GuzzleHttp\ClientInterface;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

final class GuzzleTransportTest extends TestCase
{
    public function testRequestExceptionsAreWrapped(): void
    {
        $client = $this->createMock(ClientInterface::class);
        $client->method('request')->willThrowException(new \InvalidArgumentException('bad url'));
        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('bad url');
        (new GuzzleTransport($client))->send('GET', 'x', [], null, 5);
    }

    public function testBodyReadExceptionsAreWrapped(): void
    {
        $stream = $this->createMock(StreamInterface::class);
        $stream->method('__toString')->willThrowException(new \RuntimeException('stream gone'));
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getHeaders')->willReturn([]);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getBody')->willReturn($stream);
        $client = $this->createMock(ClientInterface::class);
        $client->method('request')->willReturn($response);
        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('stream gone');
        (new GuzzleTransport($client))->send('GET', 'https://e.test/', [], null, 5);
    }

    public function testHappyPathLowercasesHeaders(): void
    {
        $stream = $this->createMock(StreamInterface::class);
        $stream->method('__toString')->willReturn('{"ok":true}');
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getHeaders')->willReturn(['X-AnswerEng-Account' => ['acct_1'], 'Retry-After' => ['1', '2']]);
        $response->method('getStatusCode')->willReturn(201);
        $response->method('getBody')->willReturn($stream);
        $client = $this->createMock(ClientInterface::class);
        $client->method('request')->willReturn($response);
        $r = (new GuzzleTransport($client))->send('POST', 'https://e.test/', [], '{}', 5);
        self::assertSame(201, $r->status);
        self::assertSame('{"ok":true}', $r->body);
        self::assertSame(['x-answereng-account' => 'acct_1', 'retry-after' => '1, 2'], $r->headers);
    }
}
