<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Model\Db;

use Magento\Framework\App\ResourceConnection;

class MagentoSqlExecutor implements SqlExecutorInterface
{
    /**
     * Constructor.
     *
     * @param ResourceConnection $resource
     */
    public function __construct(private readonly ResourceConnection $resource)
    {
    }

    /**
     * @inheritdoc
     */
    public function table(string $name): string
    {
        return $this->resource->getTableName($name);
    }

    /**
     * @inheritdoc
     */
    public function execute(string $sql, array $bind = []): int
    {
        return $this->resource->getConnection()->query($sql, $bind)->rowCount();
    }

    /**
     * @inheritdoc
     */
    public function fetchAll(string $sql, array $bind = []): array
    {
        return $this->resource->getConnection()->fetchAll($sql, $bind);
    }

    /**
     * @inheritdoc
     */
    public function fetchRow(string $sql, array $bind = []): ?array
    {
        $row = $this->resource->getConnection()->fetchRow($sql, $bind);
        return $row === false ? null : $row;
    }

    /**
     * @inheritdoc
     */
    public function fetchOne(string $sql, array $bind = []): mixed
    {
        $row = $this->resource->getConnection()->fetchRow($sql, $bind, \PDO::FETCH_NUM);
        return is_array($row) && $row !== [] ? $row[0] : null;
    }

    /**
     * @inheritdoc
     */
    public function lastInsertId(): int
    {
        return (int) $this->fetchOne('SELECT LAST_INSERT_ID()');
    }
}
