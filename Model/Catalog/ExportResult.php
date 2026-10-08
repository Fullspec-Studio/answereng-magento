<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Model\Catalog;

class ExportResult
{
    public const PAYLOAD = 'payload';
    public const EMPTY = 'empty';
    public const TOO_MANY = 'too_many';

    private function __construct(public readonly string $kind, public readonly string $json, public readonly int $count)
    {
    }

    public static function payload(string $json, int $count): self
    {
        return new self(self::PAYLOAD, $json, $count);
    }

    public static function empty(): self
    {
        return new self(self::EMPTY, '', 0);
    }

    public static function tooMany(int $count): self
    {
        return new self(self::TOO_MANY, '', $count);
    }
}
