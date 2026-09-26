<?php

declare(strict_types=1);

namespace MiGears\MiTable\Tests\Unit;

use PDO;
use PHPUnit\Framework\TestCase;
use MiGears\MiTable\MiTableInterface;
use MiGears\MiTable\SQLiteTable;
use MiGears\MiTable\Tests\Conformance\TableConformanceTests;

/**
 * Runs the shared conformance assertions against SQLite.
 *
 * Paired with MiGears\MiTable\Tests\Integration\MySQLConformanceTest, which runs
 * the identical assertions against MySQL. Together they are what makes
 * "cross-driver" a tested property rather than a claim.
 */
final class SQLiteConformanceTest extends TestCase
{
    use TableConformanceTests;

    private PDO $pdo;

    protected function newTable(): MiTableInterface
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $table = new SQLiteTable($this->pdo, 'conform_users');
        $table->create([
            'id' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
            'username' => 'VARCHAR(50) NOT NULL',
            'email' => 'VARCHAR(255) NULL',
        ]);

        return $table;
    }
}
