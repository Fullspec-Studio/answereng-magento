<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Model\Engine;

// phpcs:disable Magento2.Functions.StaticFunction -- value-object named constructors, nothing to intercept.
class EngineResult
{
    /**
     * Constructor.
     *
     * @param int|null $status
     * @param array<mixed>|null $json decoded body when it is a JSON object/array
     * @param string|null $account
     * @param int|null $retryAfter
     * @param string|null $transportError
     */
    public function __construct(
        public readonly ?int $status,
        public readonly ?array $json,
        public readonly ?string $account,
        public readonly ?int $retryAfter,
        public readonly ?string $transportError
    ) {
    }

    /**
     * From response.
     *
     * @param HttpResponse $r
     * @return self
     */
    public static function fromResponse(HttpResponse $r): self
    {
        $json = null;
        if ($r->body !== '') {
            try {
                $decoded = json_decode($r->body, true, 64, JSON_THROW_ON_ERROR);
                $json = is_array($decoded) ? $decoded : null;
            } catch (\JsonException) {
                $json = null;
            }
        }
        $retry = $r->header('retry-after');
        return new self(
            $r->status,
            $json,
            $r->header('x-answereng-account'),
            ($retry !== null && ctype_digit(trim($retry))) ? (int) trim($retry) : null,
            null
        );
    }

    /**
     * Transport failure.
     *
     * @param string $message
     * @return self
     */
    public static function transportFailure(string $message): self
    {
        return new self(null, null, null, null, $message);
    }

    /**
     * Is success.
     *
     * @return bool
     */
    public function isSuccess(): bool
    {
        return $this->status !== null && $this->status >= 200 && $this->status < 300;
    }

    /**
     * No response, a 5xx or a 429: worth stopping further reads for.
     *
     * @return bool
     */
    public function isTransient(): bool
    {
        return $this->status === null || $this->status >= 500 || $this->status === 429;
    }

    /**
     * The engine's error `code` from the body, if any.
     *
     * @return string|null
     */
    public function code(): ?string
    {
        $code = $this->json['code'] ?? null;
        return is_string($code) ? $code : null;
    }

    /**
     * Limit.
     *
     * @return int|null
     */
    public function limit(): ?int
    {
        $limit = $this->json['limit'] ?? null;
        return is_int($limit) ? $limit : null;
    }

    /**
     * Purge after.
     *
     * @return string|null
     */
    public function purgeAfter(): ?string
    {
        $value = $this->json['purge_after'] ?? null;
        return is_string($value) ? $value : null;
    }
}
