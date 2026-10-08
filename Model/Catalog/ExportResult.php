<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Model\Catalog;

class ExportResult
{
    public const PAYLOAD = 'payload';
    public const EMPTY = 'empty';
    public const TOO_MANY = 'too_many';

    /**
     * Constructor.
     *
     * @param string $kind
     * @param string $json
     * @param int $count
     */
    private function __construct(public readonly string $kind, public readonly string $json, public readonly int $count)
    {
    }

    /**
     * Payload.
     *
     * @param string $json
     * @param int $count
     * @return self
     */
    public static function payload(string $json, int $count): self
    {
        return new self(self::PAYLOAD, $json, $count);
    }

    /**
     * No eligible products.
     *
     * @return self
     */
    public static function empty(): self
    {
        return new self(self::EMPTY, '', 0);
    }

    /**
     * Too many.
     *
     * @param int $count
     * @return self
     */
    public static function tooMany(int $count): self
    {
        return new self(self::TOO_MANY, '', $count);
    }
}
