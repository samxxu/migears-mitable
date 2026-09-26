<?php

declare(strict_types=1);

namespace MiGears\MiTable\Tests\Integration;

use MiGears\MiTable\MiTableInterface;
use MiGears\MiTable\MySQLTable;
use MiGears\MiTable\Tests\Conformance\TableConformanceTests;
use MiGears\MiTable\Tests\MySqlTestCase;

/**
 * Runs the shared conformance assertions against a real MySQL server.
 *
 * The assertions come from the same trait as the SQLite run, so a behaviour that
 * only one dialect satisfies fails here. Skipped automatically when no server
 * is reachable.
 */
final class MySQLConformanceTest extends MySqlTestCase
{
    use TableConformanceTests;

    protected function newTable(): MiTableInterface
    {
        $table = new MySQLTable($this->pdo, 'conform_users');
        $table->create([
            'id' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY',
            'username' => 'VARCHAR(50) NOT NULL',
            'email' => 'VARCHAR(255) NULL',
        ]);

        return $table;
    }
}
