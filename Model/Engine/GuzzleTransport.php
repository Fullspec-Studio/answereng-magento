<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Model\Engine;

use GuzzleHttp\ClientInterface;

/** Guzzle ships with Magento 2.4 and supports DELETE with a body. */
class GuzzleTransport implements TransportInterface
{
    /**
     * Constructor.
     *
     * @param ClientInterface $client
     */
    public function __construct(private readonly ClientInterface $client)
    {
    }

    /**
     * @inheritdoc
     */
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
            $lower = [];
            foreach ($response->getHeaders() as $name => $values) {
                $lower[strtolower((string) $name)] = implode(', ', $values);
            }
            return new HttpResponse($response->getStatusCode(), $lower, (string) $response->getBody());
        } catch (\Exception $e) {
            throw new TransportException($e->getMessage(), 0, $e);
        }
    }
}
