<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Test\Db;

/**
 * Builds MySQL DDL from etc/db_schema.xml so repository tests run against
 * exactly the declared schema. Supports the column types this module uses.
 * Foreign keys to tables outside the file (Magento's `store`) are skipped.
 */
final class SchemaLoader
{
    /** @return list<string> table names in declaration order */
    public static function tables(): array
    {
        $names = [];
        foreach (self::xml()->table as $table) {
            $names[] = (string) $table['name'];
        }
        return $names;
    }

    /** @return list<string> CREATE TABLE statements in declaration order */
    public static function ddl(): array
    {
        $xml = self::xml();
        $declared = self::tables();
        $statements = [];
        foreach ($xml->table as $table) {
            $lines = [];
            foreach ($table->column as $col) {
                $lines[] = self::column($col);
            }
            foreach ($table->constraint as $c) {
                $type = (string) $c->attributes('xsi', true)->type;
                $cols = [];
                foreach ($c->column as $cc) {
                    $cols[] = '`' . $cc['name'] . '`';
                }
                if ($type === 'primary') {
                    $lines[] = 'PRIMARY KEY (' . implode(', ', $cols) . ')';
                } elseif ($type === 'unique') {
                    $lines[] = 'UNIQUE KEY `' . $c['referenceId'] . '` (' . implode(', ', $cols) . ')';
                } elseif ($type === 'foreign' && in_array((string) $c['referenceTable'], $declared, true)) {
                    $lines[] = sprintf('CONSTRAINT `%s` FOREIGN KEY (`%s`) REFERENCES `%s` (`%s`) ON DELETE %s',
                        $c['referenceId'], $c['column'], $c['referenceTable'], $c['referenceColumn'],
                        (string) ($c['onDelete'] ?: 'NO ACTION'));
                }
            }
            foreach ($table->index as $index) {
                $cols = [];
                foreach ($index->column as $ic) {
                    $cols[] = '`' . $ic['name'] . '`';
                }
                $lines[] = 'KEY `' . $index['referenceId'] . '` (' . implode(', ', $cols) . ')';
            }
            $statements[] = 'CREATE TABLE `' . $table['name'] . "` (\n  " . implode(",\n  ", $lines)
                . "\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        }
        return $statements;
    }

    private static function column(\SimpleXMLElement $col): string
    {
        $type = (string) $col->attributes('xsi', true)->type;
        $sql = match ($type) {
            'smallint' => 'SMALLINT',
            'int' => 'INT',
            'varchar' => 'VARCHAR(' . (int) $col['length'] . ')',
            'text' => 'TEXT',
            'mediumtext' => 'MEDIUMTEXT',
            'datetime' => 'DATETIME',
            default => throw new \RuntimeException("unsupported column type $type"),
        };
        if ((string) $col['unsigned'] === 'true') {
            $sql .= ' UNSIGNED';
        }
        $sql .= (string) $col['nullable'] === 'false' ? ' NOT NULL' : ' NULL';
        if (isset($col['default'])) {
            $default = (string) $col['default'];
            $sql .= ' DEFAULT ' . ($type === 'varchar' ? "'" . addslashes($default) . "'" : $default);
        }
        if ((string) $col['identity'] === 'true') {
            $sql .= ' AUTO_INCREMENT';
        }
        return '`' . $col['name'] . '` ' . $sql;
    }

    private static function xml(): \SimpleXMLElement
    {
        return simplexml_load_file(dirname(__DIR__, 2) . '/etc/db_schema.xml');
    }
}
