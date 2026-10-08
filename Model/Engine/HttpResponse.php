<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Model\Engine;

class HttpResponse
{
    /**
     * Constructor.
     *
     * @param int $status
     * @param array<string,string> $headers header names lower-cased
     * @param string $body
     */
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body
    ) {
    }

    /**
     * Header.
     *
     * @param string $name
     * @return string|null
     */
    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}
