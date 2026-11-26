<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Test\Db;

use Fullspec\AnswerEng\Model\Db\SqlExecutorInterface;

final class PdoSqlExecutor implements SqlExecutorInterface
{
    public function __construct(public readonly \PDO $pdo)
    {
    }

    public function table(string $name): string
    {
        return $name;
    }

    public function execute(string $sql, array $bind = []): int
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($bind);
        return $stmt->rowCount();
    }

    public function fetchAll(string $sql, array $bind = []): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($bind);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function fetchRow(string $sql, array $bind = []): ?array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($bind);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function fetchOne(string $sql, array $bind = []): mixed
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($bind);
        $value = $stmt->fetchColumn();
        return $value === false ? null : $value;
    }

    public function lastInsertId(): int
    {
        return (int) $this->pdo->lastInsertId();
    }
}
