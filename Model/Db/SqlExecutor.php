<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Model\Db;

/**
 * The repositories' only view of the database: plain SQL with positional
 * `?` placeholders. Magento runs it through its connection; tests run it
 * through PDO against MariaDB, so the SQL itself is what gets tested.
 */
interface SqlExecutor
{
    /** Physical name for a logical table (Magento may add a prefix). */
    public function table(string $name): string;

    /**
     * @param list<scalar|null> $bind
     * @return int rows changed (MySQL counts changed, not matched)
     */
    public function execute(string $sql, array $bind = []): int;

    /**
     * @param list<scalar|null> $bind
     * @return list<array<string,mixed>>
     */
    public function fetchAll(string $sql, array $bind = []): array;

    /**
     * @param list<scalar|null> $bind
     * @return array<string,mixed>|null
     */
    public function fetchRow(string $sql, array $bind = []): ?array;

    /** @param list<scalar|null> $bind */
    public function fetchOne(string $sql, array $bind = []): mixed;

    public function lastInsertId(): int;
}
