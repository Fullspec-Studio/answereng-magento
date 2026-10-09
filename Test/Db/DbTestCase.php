<?php
declare(strict_types=1);

namespace Fullspec\AnswerEng\Test\Db;

use PHPUnit\Framework\TestCase;

/** Fresh module tables per test in the database named by ANSWERENG_TEST_DB_DSN. */
abstract class DbTestCase extends TestCase
{
    protected PdoSqlExecutor $db;

    protected function setUp(): void
    {
        $dsn = getenv('ANSWERENG_TEST_DB_DSN');
        if (!$dsn) {
            self::markTestSkipped('ANSWERENG_TEST_DB_DSN not set');
        }
        $pdo = new \PDO($dsn, (string) getenv('ANSWERENG_TEST_DB_USER'), (string) getenv('ANSWERENG_TEST_DB_PASSWORD'),
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_STRINGIFY_FETCHES => true]);
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach (array_reverse(SchemaLoader::tables()) as $table) {
            $pdo->exec("DROP TABLE IF EXISTS `$table`");
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        foreach (SchemaLoader::ddl() as $ddl) {
            $pdo->exec($ddl);
        }
        $this->db = new PdoSqlExecutor($pdo);
    }
}
