<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Model\Db;

use Magento\Framework\App\ResourceConnection;

class MagentoSqlExecutor implements SqlExecutor
{
    public function __construct(private readonly ResourceConnection $resource)
    {
    }

    public function table(string $name): string
    {
        return $this->resource->getTableName($name);
    }

    public function execute(string $sql, array $bind = []): int
    {
        return $this->resource->getConnection()->query($sql, $bind)->rowCount();
    }

    public function fetchAll(string $sql, array $bind = []): array
    {
        return $this->resource->getConnection()->fetchAll($sql, $bind);
    }

    public function fetchRow(string $sql, array $bind = []): ?array
    {
        $row = $this->resource->getConnection()->fetchRow($sql, $bind);
        return $row === false ? null : $row;
    }

    public function fetchOne(string $sql, array $bind = []): mixed
    {
        $row = $this->resource->getConnection()->fetchRow($sql, $bind, \PDO::FETCH_NUM);
        return is_array($row) && $row !== [] ? $row[0] : null;
    }

    public function lastInsertId(): int
    {
        return (int) $this->fetchOne('SELECT LAST_INSERT_ID()');
    }
}
