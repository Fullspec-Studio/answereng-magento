<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Model\Config;

use Fullspec\AnswerEng\Model\Engine\Connection;

/** Effective AnswerEng settings for one store view (spec §3). */
class Settings
{
    /**
     * Constructor.
     *
     * @param int $storeId
     * @param bool $enabled
     * @param string $engineUrl
     * @param string $apiKey
     * @param string $storeKey
     * @param list<string> $brandNames
     * @param list<string> $competitors
     */
    public function __construct(
        public readonly int $storeId,
        public readonly bool $enabled,
        public readonly string $engineUrl,
        public readonly string $apiKey,
        public readonly string $storeKey,
        public readonly array $brandNames,
        public readonly array $competitors
    ) {
    }

    /**
     * Changes whenever any setting changes (drives config_revision bumps).
     */
    public function hash(): string
    {
        return hash('sha256', json_encode([$this->enabled, $this->engineUrl, $this->apiKeyHash(),
            $this->storeKey, $this->brandNames, $this->competitors], JSON_THROW_ON_ERROR));
    }

    /**
     * Identifies the connection whose results may be shown (spec §7 snapshot identity).
     */
    public function fingerprint(): string
    {
        return hash('sha256', $this->engineUrl . "\n" . $this->apiKeyHash() . "\n" . $this->storeKey);
    }

    /**
     * Api key hash.
     *
     * @return string
     */
    public function apiKeyHash(): string
    {
        return hash('sha256', $this->apiKey);
    }

    /**
     * Connection.
     *
     * @return Connection
     */
    public function connection(): Connection
    {
        return new Connection($this->engineUrl, $this->apiKey);
    }
}
