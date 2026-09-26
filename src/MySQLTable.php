<?php

declare(strict_types=1);

namespace MiGears\MiTable;

use PDO;
use InvalidArgumentException;

/**
 * MySQL / MariaDB implementation of a single table.
 *
 * Pairs the driver-agnostic behaviour in TableOperations with the MySQL dialect:
 * InnoDB table options, `information_schema` existence checks, `SHOW COLUMNS` /
 * `SHOW INDEX` introspection, and the `ALTER TABLE` forms for renaming,
 * truncating, changing columns and managing keys and indexes.
 *
 * Usage:
 *   $table = new MySQLTable($pdo, 'users');
 *   $table->create([
 *       'id' => 'INT UNSIGNED AUTO_INCREMENT PRIMARY KEY',
 *       'username' => 'VARCHAR(50) NOT NULL',
 *   ]);
 *   $id = $table->insert(['username' => 'alice']);
 */
class MySQLTable implements MiTableInterface
{
    use TableOperations;

    /* ==================== Dialect hooks ==================== */

    protected function assertDialect(PDO $pdo): void
    {
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver !== 'mysql') {
            throw new InvalidArgumentException(
                "MySQLTable requires a MySQL or MariaDB connection; the \"{$driver}\" driver was given"
            );
        }
    }

    protected function quoteIdentifier(string $name): string
    {
        return "`{$name}`";
    }

    /**
     * Detect a single-column primary key usable as an iteration cursor.
     *
     * Returns null for keyless or composite-key tables, which fall back to
     * offset paging.
     */
    protected function detectPrimaryKey(): ?string
    {
        try {
            $t = $this->quoteIdentifier($this->getName());
            $stmt = $this->getPdo()->query("SHOW KEYS FROM {$t} WHERE Key_name = 'PRIMARY'");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return count($rows) === 1 ? (string) $rows[0]['Column_name'] : null;
        } catch (\PDOException) {
            // Fall through to offset paging.
            return null;
        }
    }

    protected function fetchOffsetPage(string $quotedTable, int $offset, int $size): array
    {
        return $this->getPdo()
            ->query("SELECT * FROM {$quotedTable} LIMIT {$offset}, {$size}")
            ->fetchAll(PDO::FETCH_ASSOC);
    }

    // ==================== DDL ====================

    public function create(
        array $columns,
        string $engine = 'InnoDB',
        string $charset = 'utf8mb4',
        string $collate = 'utf8mb4_unicode_ci'
    ): void {
        $t = $this->quoteIdentifier($this->getName());
        $defs = $this->columnDefinitions($columns);

        $this->getPdo()->exec(
            "CREATE TABLE {$t} ({$defs}) ENGINE={$engine} DEFAULT CHARSET={$charset} COLLATE={$collate}"
        );
    }

    public function exists(): bool
    {
        return $this->catalogHasRow(
            'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1'
        );
    }

    public function rename(string $newName): self
    {
        $t = $this->quoteIdentifier($this->getName());
        $new = $this->quoteIdentifier($newName);

        $this->getPdo()->exec("RENAME TABLE {$t} TO {$new}");

        return new self($this->getPdo(), $newName);
    }

    public function truncate(): void
    {
        $t = $this->quoteIdentifier($this->getName());
        $this->getPdo()->exec("TRUNCATE TABLE {$t}");
    }

    public function addColumn(string $name, string $definition, ?string $after = null): void
    {
        $t = $this->quoteIdentifier($this->getName());
        $col = $this->quoteIdentifier($name);

        $sql = "ALTER TABLE {$t} ADD COLUMN {$col} {$definition}";

        if ($after !== null) {
            $sql .= ' AFTER ' . $this->quoteIdentifier($after);
        }

        $this->getPdo()->exec($sql);
    }

    public function modifyColumn(string $name, string $definition): void
    {
        $t = $this->quoteIdentifier($this->getName());
        $col = $this->quoteIdentifier($name);

        $this->getPdo()->exec("ALTER TABLE {$t} MODIFY COLUMN {$col} {$definition}");
    }

    public function renameColumn(string $oldName, string $newName, string $definition): void
    {
        $t = $this->quoteIdentifier($this->getName());
        $old = $this->quoteIdentifier($oldName);
        $new = $this->quoteIdentifier($newName);

        $this->getPdo()->exec("ALTER TABLE {$t} CHANGE COLUMN {$old} {$new} {$definition}");
    }

    public function addIndex(string $name, array $columns, string $type = ''): void
    {
        $t = $this->quoteIdentifier($this->getName());
        $idx = $this->quoteIdentifier($name);
        $cols = $this->columnList($columns);
        $using = $type !== '' ? "USING {$type}" : '';

        $this->getPdo()->exec("ALTER TABLE {$t} ADD INDEX {$idx} {$using} ({$cols})");
    }

    public function dropIndex(string $name): void
    {
        $t = $this->quoteIdentifier($this->getName());
        $idx = $this->quoteIdentifier($name);

        $this->getPdo()->exec("ALTER TABLE {$t} DROP INDEX {$idx}");
    }

    public function addUniqueIndex(string $name, array $columns): void
    {
        $t = $this->quoteIdentifier($this->getName());
        $idx = $this->quoteIdentifier($name);
        $cols = $this->columnList($columns);

        $this->getPdo()->exec("ALTER TABLE {$t} ADD UNIQUE INDEX {$idx} ({$cols})");
    }

    public function addPrimaryKey(array $columns): void
    {
        $t = $this->quoteIdentifier($this->getName());
        $cols = $this->columnList($columns);

        $this->getPdo()->exec("ALTER TABLE {$t} ADD PRIMARY KEY ({$cols})");
    }

    public function dropPrimaryKey(): void
    {
        $t = $this->quoteIdentifier($this->getName());
        $this->getPdo()->exec("ALTER TABLE {$t} DROP PRIMARY KEY");
    }

    // ==================== Introspection ====================

    public function showColumns(): array
    {
        // MySQL raises an error for `SHOW COLUMNS` on a missing table, so the
        // guard keeps the "missing table returns an empty array" contract.
        if (!$this->exists()) {
            return [];
        }

        $t = $this->quoteIdentifier($this->getName());
        $rows = $this->getPdo()->query("SHOW COLUMNS FROM {$t}")->fetchAll(PDO::FETCH_ASSOC);
        $columns = [];

        foreach ($rows as $row) {
            $key = (string) $row['Key'];
            $columns[(string) $row['Field']] = [
                'name' => (string) $row['Field'],
                'type' => (string) $row['Type'],
                'nullable' => strtoupper((string) $row['Null']) === 'YES',
                'default' => $row['Default'],
                'primary' => $key === 'PRI',
                'unique' => $key === 'PRI' || $key === 'UNI',
            ];
        }

        return $columns;
    }

    public function showIndexes(): array
    {
        if (!$this->exists()) {
            return [];
        }

        $t = $this->quoteIdentifier($this->getName());
        $rows = $this->getPdo()->query("SHOW INDEX FROM {$t}")->fetchAll(PDO::FETCH_ASSOC);
        $indexes = [];

        // One row per indexed column; Seq_in_index gives the column's position.
        foreach ($rows as $row) {
            $name = (string) $row['Key_name'];

            $indexes[$name]['name'] = $name;
            $indexes[$name]['columns'][(int) $row['Seq_in_index']] = (string) $row['Column_name'];
            $indexes[$name]['unique'] = (int) $row['Non_unique'] === 0;
            $indexes[$name]['primary'] = $name === 'PRIMARY';
            $indexes[$name]['type'] = (string) $row['Index_type'];
        }

        foreach ($indexes as $name => $index) {
            ksort($indexes[$name]['columns']);
            $indexes[$name]['columns'] = array_values($indexes[$name]['columns']);
        }

        return $indexes;
    }
}
