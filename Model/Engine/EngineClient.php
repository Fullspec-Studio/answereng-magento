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

    /**
     * Constructor.
     *
     * @param TransportInterface $transport
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly TransportInterface $transport,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Post catalog.
     *
     * @param Connection $c
     * @param string $json
     * @return EngineResult
     */
    public function postCatalog(Connection $c, string $json): EngineResult
    {
        return $this->once('POST', $c, '/catalog', $json, self::WRITE_TIMEOUT);
    }

    /**
     * Clear catalog.
     *
     * @param Connection $c
     * @param string $storeKey
     * @return EngineResult
     */
    public function clearCatalog(Connection $c, string $storeKey): EngineResult
    {
        return $this->once('DELETE', $c, '/stores/' . rawurlencode($storeKey) . '/catalog', null, self::WRITE_TIMEOUT);
    }

    /**
     * Delete store.
     *
     * @param Connection $c
     * @param string $storeKey
     * @return EngineResult
     */
    public function deleteStore(Connection $c, string $storeKey): EngineResult
    {
        $body = json_encode(['confirm_store_key' => $storeKey], JSON_THROW_ON_ERROR);
        return $this->once('DELETE', $c, '/stores/' . rawurlencode($storeKey), $body, self::WRITE_TIMEOUT);
    }

    /**
     * Restore store.
     *
     * @param Connection $c
     * @param string $storeKey
     * @return EngineResult
     */
    public function restoreStore(Connection $c, string $storeKey): EngineResult
    {
        return $this->once('POST', $c, '/stores/' . rawurlencode($storeKey) . '/restore', null, self::WRITE_TIMEOUT);
    }

    /**
     * List stores.
     *
     * @param Connection $c
     * @param ReadBudget|null $budget the page's shared read budget, when rendering
     * @return EngineResult
     */
    public function listStores(Connection $c, ?ReadBudget $budget = null): EngineResult
    {
        return $this->read($c, '/stores', $budget);
    }

    /**
     * Summary.
     *
     * @param Connection $c
     * @param string $storeKey
     * @param ReadBudget|null $budget
     * @return EngineResult
     */
    public function summary(Connection $c, string $storeKey, ?ReadBudget $budget = null): EngineResult
    {
        return $this->read($c, '/stores/' . rawurlencode($storeKey) . '/summary', $budget);
    }

    /**
     * Fixes with the given status.
     *
     * @param Connection $c
     * @param string $storeKey
     * @param string $status
     * @param ReadBudget|null $budget
     * @return EngineResult
     */
    public function fixes(Connection $c, string $storeKey, string $status, ?ReadBudget $budget = null): EngineResult
    {
        return $this->read(
            $c,
            '/stores/' . rawurlencode($storeKey) . '/fixes?status=' . rawurlencode($status),
            $budget
        );
    }

    /**
     * Mark a fix applied (sent once).
     *
     * @param Connection $c
     * @param string $storeKey
     * @param int $fixId
     * @return EngineResult
     */
    public function markApplied(Connection $c, string $storeKey, int $fixId): EngineResult
    {
        return $this->once('POST', $c, $this->appliedPath($storeKey, $fixId), null, self::WRITE_TIMEOUT);
    }

    /**
     * Unmark a fix applied (sent once).
     *
     * @param Connection $c
     * @param string $storeKey
     * @param int $fixId
     * @return EngineResult
     */
    public function unmarkApplied(Connection $c, string $storeKey, int $fixId): EngineResult
    {
        return $this->once('DELETE', $c, $this->appliedPath($storeKey, $fixId), null, self::WRITE_TIMEOUT);
    }

    /**
     * Applied-marker path.
     *
     * @param string $storeKey
     * @param int $fixId
     * @return string
     */
    private function appliedPath(string $storeKey, int $fixId): string
    {
        return '/stores/' . rawurlencode($storeKey) . '/fixes/' . $fixId . '/applied';
    }

    /**
     * A GET, retried once on a transient failure.
     *
     * With a budget the attempts share it: each is capped by the budget, a retry needs enough time left
     * (never after a 429, which would keep a throttled key throttled), and a transient outcome
     * stops the budget's remaining reads.
     *
     * @param Connection $c
     * @param string $path
     * @param ReadBudget|null $budget
     * @return EngineResult
     */
    private function read(Connection $c, string $path, ?ReadBudget $budget = null): EngineResult
    {
        if ($budget === null) {
            $first = $this->once('GET', $c, $path, null, self::READ_TIMEOUT);
            if ($first->status !== null && $first->status < 500) {
                return $first;
            }
            return $this->once('GET', $c, $path, null, self::READ_TIMEOUT);
        }
        $result = $this->budgetedAttempt($c, $path, $budget);
        if ($result->isTransient() && $result->status !== 429 && $budget->allowsRetry()) {
            $result = $this->budgetedAttempt($c, $path, $budget);
        }
        if ($result->isTransient()) {
            $budget->stop();
        }
        return $result;
    }

    /**
     * One budgeted GET; the budget is read once, and a timeout under 1 s is never sent.
     *
     * @param Connection $c
     * @param string $path
     * @param ReadBudget $budget
     * @return EngineResult
     */
    private function budgetedAttempt(Connection $c, string $path, ReadBudget $budget): EngineResult
    {
        $timeout = $budget->stopped() ? 0 : $budget->attemptTimeout();
        if ($timeout < 1) {
            $budget->stop();
            return EngineResult::transportFailure('read budget exhausted');
        }
        return $this->once('GET', $c, $path, null, $timeout);
    }

    /**
     * One request; transport failures come back as an EngineResult.
     *
     * @param string $method
     * @param Connection $c
     * @param string $path
     * @param string|null $body
     * @param int $timeout
     * @return EngineResult
     */
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
            // HTTP clients quote a rejected header value in their error text, which would put the key in
            // answereng.log and the sync log (seen locally when a malformed key value was sent), so strip it out.
            $error = mb_substr($c->apiKey === '' ? $e->getMessage()
                : str_replace($c->apiKey, '[api key]', $e->getMessage()), 0, 200);
            $this->logger->warning('AnswerEng request failed', ['method' => $method, 'path' => $path,
                'error' => $error]);
            return EngineResult::transportFailure($error);
        }
        $this->logger->info('AnswerEng request', ['method' => $method, 'path' => $path,
            'status' => $result->status, 'code' => $result->code()]);
        return $result;
    }
}
