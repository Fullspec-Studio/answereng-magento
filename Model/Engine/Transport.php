<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Model\Engine;

interface Transport
{
    /**
     * Send one HTTP request.
     *
     * @param string $method
     * @param string $url
     * @param array<string,string> $headers
     * @param string|null $body
     * @param int $timeoutSeconds
     * @throws TransportException on connection/timeout failures (never on HTTP status)
     */
    public function send(string $method, string $url, array $headers, ?string $body, int $timeoutSeconds): HttpResponse;
}
