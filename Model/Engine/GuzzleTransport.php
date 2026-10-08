<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Model\Engine;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/** Guzzle ships with Magento 2.4 and supports DELETE with a body. */
class GuzzleTransport implements Transport
{
    public function __construct(private readonly ClientInterface $client)
    {
    }

    public function send(string $method, string $url, array $headers, ?string $body, int $timeoutSeconds): HttpResponse
    {
        try {
            $response = $this->client->request($method, $url, [
                'headers' => $headers,
                'body' => $body,
                'timeout' => $timeoutSeconds,
                'connect_timeout' => min(10, $timeoutSeconds),
                'http_errors' => false,
            ]);
        } catch (GuzzleException $e) {
            throw new TransportException($e->getMessage(), 0, $e);
        }
        $lower = [];
        foreach ($response->getHeaders() as $name => $values) {
            $lower[strtolower((string) $name)] = implode(', ', $values);
        }
        return new HttpResponse($response->getStatusCode(), $lower, (string) $response->getBody());
    }
}
