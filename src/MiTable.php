<?php

declare(strict_types=1);

namespace MiGears\MiTable;

use PDO;
use Iterator;
use InvalidArgumentException;

/**
 * Minimalist table management and data access for a single database table.
 *
 * Combines DDL operations (create, drop, add column, add index, etc.)
 * with basic CRUD and paginated iteration.
 *
 * Uses raw PDO — no query builder dependency. Works with any PDO driver,
 * but DDL methods are designed for MySQL/MariaDB.
 *
 * Usage:
 *   $table = new MiTable($pdo, 'users');
 *   $table->create([
 *       'id' => 'INT UNSIGNED AUTO_INCREMENT PRIMARY KEY',
 *       'username' => 'VARCHAR(50) NOT NULL',
 *       'email' => 'VARCHAR(255) NOT NULL',
 *   ]);
 *   $id = $table->insert(['username' => 'alice', 'email' => 'alice@example.com']);
 */
class MiTable implements Iterator
{
    public const VERSION = '2.0.0';

    private readonly PDO $pdo;
    private readonly string $table;

    // Iterator state
    private int $iterPageSize = 100;
    private int $iterPosition = 0;
    private int $iterTotal = 0;
    private array $iterPage = [];
    private int $iterIndexInPage = 0;

    public function __construct(PDO $pdo, string $tableName)
    {
        $this->pdo = $pdo;
        $this->table = $tableName;
    }

    /** Get the table name. */
    public function getName(): string
    {
        return $this->table;
    }

    /** Get the underlying PDO instance. */
    public function getPdo(): PDO
    {
        return $this->pdo;
    }

    /** Check whether the PDO driver is MySQL. */
    private function isMysql(): bool
    {
        return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    }

    // ==================== DDL ====================

    /**
     * Create the table from a column definition array.
     *
     * @param array<string, string> $columns  Column name => SQL definition
     * @param string $engine                  Storage engine (MySQL only, default: InnoDB)
     * @param string $charset                 Character set (MySQL only, default: utf8mb4)
     * @param string $collate                 Collation (MySQL only, default: utf8mb4_unicode_ci)
     */
    public function create(array $columns, string $engine = 'InnoDB', string $charset = 'utf8mb4', string $collate = 'utf8mb4_unicode_ci'): void
    {
        if ($columns === []) {
            throw new InvalidArgumentException('Cannot create table with no columns');
        }

        $defs = [];
        foreach ($columns as $name => $definition) {
            $defs[] = "`{$name}` {$definition}";
        }

        $sql = "CREATE TABLE `{$this->table}` (" . implode(', ', $defs) . ")";

        // MySQL-specific table options
        if ($this->isMysql()) {
            $sql .= " ENGINE={$engine} DEFAULT CHARSET={$charset} COLLATE={$collate}";
        }

        $this->pdo->exec($sql);
    }

    /** Drop the table if it exists. */
    public function drop(): void
    {
        $this->pdo->exec("DROP TABLE IF EXISTS `{$this->table}`");
    }

    /** Check if the table exists. */
    public function exists(): bool
    {
        try {
            $stmt = $this->pdo->query("SELECT 1 FROM `{$this->table}` LIMIT 1");
            return $stmt !== false;
        } catch (\PDOException) {
            return false;
        }
    }

    /** Rename the table. Returns a new MiTable instance. */
    public function rename(string $newName): self
    {
        $this->pdo->exec("RENAME TABLE `{$this->table}` TO `{$newName}`");
        return new self($this->pdo, $newName);
    }

    /** Truncate all rows from the table. */
    public function truncate(): void
    {
        $this->pdo->exec("TRUNCATE TABLE `{$this->table}`");
    }

    // --- Column operations ---

    /** Add a column. */
    public function addColumn(string $name, string $definition, ?string $after = null): void
    {
        $sql = "ALTER TABLE `{$this->table}` ADD COLUMN `{$name}` {$definition}";
        if ($after !== null) {
            $sql .= " AFTER `{$after}`";
        }
        $this->pdo->exec($sql);
    }

    /** Drop a column. */
    public function dropColumn(string $name): void
    {
        $this->pdo->exec("ALTER TABLE `{$this->table}` DROP COLUMN `{$name}`");
    }

    /** Modify a column's definition. */
    public function modifyColumn(string $name, string $definition): void
    {
        $this->pdo->exec("ALTER TABLE `{$this->table}` MODIFY COLUMN `{$name}` {$definition}");
    }

    /** Rename a column. */
    public function renameColumn(string $oldName, string $newName, string $definition): void
    {
        $this->pdo->exec("ALTER TABLE `{$this->table}` CHANGE COLUMN `{$oldName}` `{$newName}` {$definition}");
    }

    // --- Index operations ---

    /** Add an index. */
    public function addIndex(string $name, array $columns, string $type = ''): void
    {
        $cols = implode(', ', array_map(fn($c) => "`{$c}`", $columns));

        if ($this->isMysql()) {
            $using = $type !== '' ? "USING {$type}" : '';
            $this->pdo->exec("ALTER TABLE `{$this->table}` ADD INDEX `{$name}` {$using} ({$cols})");
        } else {
            $this->pdo->exec("CREATE INDEX `{$name}` ON `{$this->table}` ({$cols})");
        }
    }

    /** Drop an index. */
    public function dropIndex(string $name): void
    {
        if ($this->isMysql()) {
            $this->pdo->exec("ALTER TABLE `{$this->table}` DROP INDEX `{$name}`");
        } else {
            $this->pdo->exec("DROP INDEX `{$name}`");
        }
    }

    /** Add a unique index. */
    public function addUniqueIndex(string $name, array $columns): void
    {
        $cols = implode(', ', array_map(fn($c) => "`{$c}`", $columns));

        if ($this->isMysql()) {
            $this->pdo->exec("ALTER TABLE `{$this->table}` ADD UNIQUE INDEX `{$name}` ({$cols})");
        } else {
            $this->pdo->exec("CREATE UNIQUE INDEX `{$name}` ON `{$this->table}` ({$cols})");
        }
    }

    /** Add a primary key. */
    public function addPrimaryKey(array $columns): void
    {
        $cols = implode(', ', array_map(fn($c) => "`{$c}`", $columns));
        $this->pdo->exec("ALTER TABLE `{$this->table}` ADD PRIMARY KEY ({$cols})");
    }

    /** Drop the primary key. */
    public function dropPrimaryKey(): void
    {
        $this->pdo->exec("ALTER TABLE `{$this->table}` DROP PRIMARY KEY");
    }

    // ==================== CRUD ====================

    /**
     * Insert a row. Returns the last insert ID.
     *
     * @param array<string, mixed> $data
     */
    public function insert(array $data): string
    {
        $columns = array_keys($data);
        $placeholders = array_map(fn($c) => ":{$c}", $columns);

        $cols = implode(', ', array_map(fn($c) => "`{$c}`", $columns));
        $vals = implode(', ', $placeholders);

        $stmt = $this->pdo->prepare("INSERT INTO `{$this->table}` ({$cols}) VALUES ({$vals})");
        $stmt->execute($data);

        return $this->pdo->lastInsertId();
    }

    /**
     * Bulk insert multiple rows. Returns the number of affected rows.
     *
     * @param list<array<string, mixed>> $rows
     */
    public function bulkInsert(array $rows): int
    {
        if ($rows === []) {
            return 0;
        }

        $columns = array_keys($rows[0]);
        $cols = implode(', ', array_map(fn($c) => "`{$c}`", $columns));

        $valueSets = [];
        $params = [];
        foreach ($rows as $i => $row) {
            $placeholders = array_map(fn($c) => ":{$c}_{$i}", $columns);
            $valueSets[] = '(' . implode(', ', $placeholders) . ')';
            foreach ($columns as $c) {
                $params["{$c}_{$i}"] = $row[$c];
            }
        }

        $sql = "INSERT INTO `{$this->table}` ({$cols}) VALUES " . implode(', ', $valueSets);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount();
    }

    /**
     * Update rows matching conditions.
     *
     * @param array<string, mixed> $data       Column => value pairs to set
     * @param array<string, mixed> $where      Column => value conditions (AND)
     * @return int Number of affected rows
     */
    public function update(array $data, array $where): int
    {
        if ($data === []) {
            return 0;
        }

        $setParts = [];
        $params = [];
        foreach ($data as $key => $value) {
            $setParts[] = "`{$key}` = :set_{$key}";
            $params["set_{$key}"] = $value;
        }

        $whereParts = [];
        foreach ($where as $key => $value) {
            $whereParts[] = "`{$key}` = :where_{$key}";
            $params["where_{$key}"] = $value;
        }

        $sql = "UPDATE `{$this->table}` SET " . implode(', ', $setParts);
        if ($whereParts !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $whereParts);
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount();
    }

    /**
     * Delete rows matching conditions.
     *
     * @param array<string, mixed> $where  Column => value conditions (AND)
     * @return int Number of affected rows
     */
    public function delete(array $where): int
    {
        $whereParts = [];
        $params = [];
        foreach ($where as $key => $value) {
            $whereParts[] = "`{$key}` = :{$key}";
            $params[$key] = $value;
        }

        $sql = "DELETE FROM `{$this->table}`";
        if ($whereParts !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $whereParts);
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount();
    }

    /**
     * Find a single row by conditions.
     *
     * @param array<string, mixed> $where
     * @return array<string, mixed>|null
     */
    public function find(array $where): ?array
    {
        $whereParts = [];
        $params = [];
        foreach ($where as $key => $value) {
            $whereParts[] = "`{$key}` = :{$key}";
            $params[$key] = $value;
        }

        $sql = "SELECT * FROM `{$this->table}`";
        if ($whereParts !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $whereParts);
        }
        $sql .= ' LIMIT 1';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * Get all rows matching conditions.
     *
     * @param array<string, mixed> $where
     * @param string $orderBy  Column to order by (e.g. "id DESC")
     * @param int|null $limit  Max number of rows
     * @return list<array<string, mixed>>
     */
    public function where(array $where = [], string $orderBy = '', ?int $limit = null): array
    {
        $whereParts = [];
        $params = [];
        foreach ($where as $key => $value) {
            $whereParts[] = "`{$key}` = :{$key}";
            $params[$key] = $value;
        }

        $sql = "SELECT * FROM `{$this->table}`";
        if ($whereParts !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $whereParts);
        }
        if ($orderBy !== '') {
            $sql .= " ORDER BY {$orderBy}";
        }
        if ($limit !== null) {
            $sql .= " LIMIT {$limit}";
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Count rows matching conditions. */
    public function count(array $where = []): int
    {
        $whereParts = [];
        $params = [];
        foreach ($where as $key => $value) {
            $whereParts[] = "`{$key}` = :{$key}";
            $params[$key] = $value;
        }

        $sql = "SELECT COUNT(*) FROM `{$this->table}`";
        if ($whereParts !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $whereParts);
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    // ==================== Iterator ====================

    /**
     * Set the page size for iteration.
     *
     * @return $this
     */
    public function withPageSize(int $pageSize): self
    {
        $this->iterPageSize = max(1, $pageSize);
        return $this;
    }

    /** Return the current row. */
    public function current(): array
    {
        return $this->iterPage[$this->iterIndexInPage];
    }

    /** Return the current position (0-based). */
    public function key(): int
    {
        return $this->iterPosition;
    }

    /** Advance to the next row. */
    public function next(): void
    {
        $this->iterPosition++;
        $this->iterIndexInPage++;
    }

    /** Rewind to the first row. */
    public function rewind(): void
    {
        $this->iterPosition = 0;
        $this->iterIndexInPage = 0;
        $this->iterPage = [];
        $this->iterTotal = $this->count();
        $this->loadPage(0);
    }

    /** Check if current position is valid. */
    public function valid(): bool
    {
        if ($this->iterPosition >= $this->iterTotal) {
            return false;
        }

        // Load next page if we've exhausted the current one
        if ($this->iterIndexInPage >= count($this->iterPage)) {
            $page = (int) floor($this->iterPosition / $this->iterPageSize);
            $this->loadPage($page);
            $this->iterIndexInPage = 0;
        }

        return isset($this->iterPage[$this->iterIndexInPage]);
    }

    /** Load a page of data. */
    private function loadPage(int $page): void
    {
        $offset = $page * $this->iterPageSize;
        $sql = "SELECT * FROM `{$this->table}` LIMIT {$offset}, {$this->iterPageSize}";
        $stmt = $this->pdo->query($sql);
        $this->iterPage = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
