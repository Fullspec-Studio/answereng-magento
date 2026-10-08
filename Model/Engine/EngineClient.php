<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Model\Engine;

use Psr\Log\LoggerInterface;

/**
 * The engine's connector API. Writes are sent once; GETs retry once on a
 * 5xx or transport failure (the engine's database auto-pauses). Logs carry
 * method, path and status only — never the key or a body.
 */
class EngineClient
{
    private const WRITE_TIMEOUT = 30;
    private const READ_TIMEOUT = 15;

    public function __construct(
        private readonly Transport $transport,
        private readonly LoggerInterface $logger
    ) {
    }

    public function postCatalog(Connection $c, string $json): EngineResult
    {
        return $this->once('POST', $c, '/catalog', $json, self::WRITE_TIMEOUT);
    }

    public function clearCatalog(Connection $c, string $storeKey): EngineResult
    {
        return $this->once('DELETE', $c, '/stores/' . rawurlencode($storeKey) . '/catalog', null, self::WRITE_TIMEOUT);
    }

    public function deleteStore(Connection $c, string $storeKey): EngineResult
    {
        $body = json_encode(['confirm_store_key' => $storeKey], JSON_THROW_ON_ERROR);
        return $this->once('DELETE', $c, '/stores/' . rawurlencode($storeKey), $body, self::WRITE_TIMEOUT);
    }

    public function restoreStore(Connection $c, string $storeKey): EngineResult
    {
        return $this->once('POST', $c, '/stores/' . rawurlencode($storeKey) . '/restore', null, self::WRITE_TIMEOUT);
    }

    public function listStores(Connection $c): EngineResult
    {
        return $this->read($c, '/stores');
    }

    public function summary(Connection $c, string $storeKey): EngineResult
    {
        return $this->read($c, '/stores/' . rawurlencode($storeKey) . '/summary');
    }

    private function read(Connection $c, string $path): EngineResult
    {
        $first = $this->once('GET', $c, $path, null, self::READ_TIMEOUT);
        if ($first->status !== null && $first->status < 500) {
            return $first;
        }
        return $this->once('GET', $c, $path, null, self::READ_TIMEOUT);
    }

    private function once(string $method, Connection $c, string $path, ?string $body, int $timeout): EngineResult
    {
        $headers = ['x-api-key' => $c->apiKey, 'Accept' => 'application/json'];
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
        }
        try {
            $result = EngineResult::fromResponse(
                $this->transport->send($method, $c->engineUrl . $path, $headers, $body, $timeout)
            );
        } catch (TransportException $e) {
            $this->logger->warning('AnswerEng request failed', ['method' => $method, 'path' => $path,
                'error' => mb_substr($e->getMessage(), 0, 200)]);
            return EngineResult::transportFailure(mb_substr($e->getMessage(), 0, 200));
        }
        $this->logger->info('AnswerEng request', ['method' => $method, 'path' => $path,
            'status' => $result->status, 'code' => $result->code()]);
        return $result;
    }
}
