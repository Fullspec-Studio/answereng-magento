<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Model\Engine;

class Connection
{
    public readonly string $engineUrl;

    public function __construct(string $engineUrl, public readonly string $apiKey)
    {
        $this->engineUrl = rtrim($engineUrl, '/');
    }
}
