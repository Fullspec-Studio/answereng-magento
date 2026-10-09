<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Model\Engine;

class Connection
{
    /**
     * @var string engine URL without a trailing slash
     */
    public readonly string $engineUrl;

    /**
     * Constructor.
     *
     * @param string $engineUrl
     * @param string $apiKey
     */
    public function __construct(string $engineUrl, public readonly string $apiKey)
    {
        $this->engineUrl = rtrim($engineUrl, '/');
    }
}
