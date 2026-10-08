<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Model\Engine;

interface Transport
{
    /**
     * @param array<string,string> $headers
     * @throws TransportException on connection/timeout failures (never on HTTP status)
     */
    public function send(string $method, string $url, array $headers, ?string $body, int $timeoutSeconds): HttpResponse;
}
