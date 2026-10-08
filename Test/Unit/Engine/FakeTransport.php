<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Test\Unit\Engine;

use Fullspec\AnswerEng\Model\Engine\HttpResponse;
use Fullspec\AnswerEng\Model\Engine\Transport;
use Fullspec\AnswerEng\Model\Engine\TransportException;

/** Records requests and replays queued responses (or TransportException). */
final class FakeTransport implements Transport
{
    /** @var list<array{method:string,url:string,headers:array<string,string>,body:?string,timeout:int}> */
    public array $requests = [];
    /** @var list<HttpResponse|TransportException> */
    private array $queue = [];

    public function enqueue(HttpResponse|TransportException $next): self
    {
        $this->queue[] = $next;
        return $this;
    }

    /** @param array<string,mixed>|null $json */
    public static function json(int $status, ?array $json, array $headers = []): HttpResponse
    {
        return new HttpResponse($status, array_change_key_case($headers), $json === null ? '' : json_encode($json));
    }

    public function send(string $method, string $url, array $headers, ?string $body, int $timeoutSeconds): HttpResponse
    {
        $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers,
            'body' => $body, 'timeout' => $timeoutSeconds];
        if ($this->queue === []) {
            throw new \LogicException("unexpected request $method $url");
        }
        $next = array_shift($this->queue);
        if ($next instanceof TransportException) {
            throw $next;
        }
        return $next;
    }
}
