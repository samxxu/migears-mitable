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
 * with basic CRUD, introspected schema reads, and cursor-paginated iteration.
 *
 * Built for one-off data migration scripts: point it at one table, reshape the
 * schema, move and transform rows, then get out of the way. Version management,
 * execution order, transactions and rollback are deliberately left to the
 * caller or to a separate migration runner.
 *
 * Uses raw PDO — no query builder dependency. Works with any PDO driver,
 * but DDL and introspection methods are designed for MySQL/MariaDB and SQLite.
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

    /** Operators accepted in a `[operator, value]` where condition. */
    private const OPERATORS = [
        '=', '!=', '<>', '>', '>=', '<', '<=', 'like', 'not like',
        'in', 'not in', 'between', 'not between',
    ];

    private readonly PDO $pdo;
    private readonly string $table;

    // Iterator state
    private int $iterPageSize = 100;
    private int $iterPosition = 0;
    private array $iterPage = [];
    private int $iterIndexInPage = 0;
    private bool $iterDone = false;
    private mixed $iterCursor = null;
    private mixed $iterStart = null;
    private mixed $iterLastKey = null;
    private ?string $iterKey = null;
    private ?string $iterKeyChoice = null;
    private bool $iterKeyResolved = false;

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

    /** Check whether the PDO driver is SQLite. */
    private function isSqlite(): bool
    {
        return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
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

    /**
     * Check if the table exists.
     *
     * Asks the driver catalog (information_schema / sqlite_master) instead of
     * probing the table, so connection, permission and syntax problems surface
     * as real errors rather than being reported as "missing table".
     */
    public function exists(): bool
    {
        if ($this->isMysql()) {
            return $this->catalogHasRow(
                'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1'
            );
        }

        if ($this->isSqlite()) {
            return $this->catalogHasRow("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ? LIMIT 1");
        }

        // Unknown driver: fall back to probing the table directly.
        try {
            $this->pdo->query("SELECT 1 FROM `{$this->table}` LIMIT 1");
            return true;
        } catch (\PDOException) {
            return false;
        }
    }

    /** Run a catalog lookup bound to the current table name. */
    private function catalogHasRow(string $sql): bool
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$this->table]);

        return $stmt->fetchColumn() !== false;
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

    /**
     * Add a column.
     *
     * `$after` positions the column on MySQL only. SQLite has no such clause
     * and always appends new columns, so the argument is ignored there.
     */
    public function addColumn(string $name, string $definition, ?string $after = null): void
    {
        $sql = "ALTER TABLE `{$this->table}` ADD COLUMN `{$name}` {$definition}";

        if ($after !== null && $this->isMysql()) {
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

    // ==================== Introspection ====================

    /**
     * List the table's columns, keyed by column name.
     *
     * Lets a migration decide "add only if missing" without probing with raw SQL.
     * A missing table returns an empty array. Each entry: name, type, nullable,
     * default, primary, unique.
     *
     * @return array<string, array{name: string, type: string, nullable: bool, default: mixed, primary: bool, unique: bool}>
     */
    public function showColumns(): array
    {
        $this->requireIntrospectionDriver('showColumns');

        // MySQL raises an error for `SHOW COLUMNS` on a missing table while
        // SQLite returns an empty set, so the guard keeps the contract uniform.
        if (!$this->exists()) {
            return [];
        }

        $columns = [];

        if ($this->isMysql()) {
            $rows = $this->pdo->query("SHOW COLUMNS FROM `{$this->table}`")->fetchAll(PDO::FETCH_ASSOC);

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

        $rows = $this->pdo->query("PRAGMA table_info(`{$this->table}`)")->fetchAll(PDO::FETCH_ASSOC);
        $unique = $this->sqliteUniqueColumns();

        foreach ($rows as $row) {
            $name = (string) $row['name'];
            $primary = (int) $row['pk'] > 0;

            $columns[$name] = [
                'name' => $name,
                'type' => (string) $row['type'],
                // A primary key column is never nullable; SQLite reports
                // notnull = 0 for `INTEGER PRIMARY KEY`, MySQL reports NO.
                'nullable' => !$primary && (int) $row['notnull'] === 0,
                'default' => $row['dflt_value'],
                'primary' => $primary,
                'unique' => $primary || isset($unique[$name]),
            ];
        }

        return $columns;
    }

    /**
     * Columns covered by a single-column unique index (SQLite).
     *
     * @return array<string, true>
     */
    private function sqliteUniqueColumns(): array
    {
        $unique = [];
        $indexes = $this->pdo->query("PRAGMA index_list(`{$this->table}`)")->fetchAll(PDO::FETCH_ASSOC);

        foreach ($indexes as $index) {
            if ((int) $index['unique'] !== 1) {
                continue;
            }

            $columns = $this->pdo->query("PRAGMA index_info(`{$index['name']}`)")->fetchAll(PDO::FETCH_ASSOC);

            if (count($columns) === 1) {
                $unique[(string) $columns[0]['name']] = true;
            }
        }

        return $unique;
    }

    /**
     * List the table's indexes, keyed by index name.
     *
     * Answers "is there already an index called idx_email?" so a migration can
     * choose between dropping and adding without raw SQL. Each entry: name,
     * columns (in index order), unique, primary, type.
     *
     * Also covers what showColumns() cannot see: an index spanning several
     * columns, such as a composite unique key.
     *
     * SQLite note: an `INTEGER PRIMARY KEY` is the rowid alias and has no index
     * entry at all, so it does not appear here. Use showColumns() to detect that
     * primary key. Indexes created by a UNIQUE or PRIMARY KEY constraint do
     * appear, under a generated `sqlite_autoindex_*` name.
     *
     * @return array<string, array{name: string, columns: list<string>, unique: bool, primary: bool, type: string}>
     */
    public function showIndexes(): array
    {
        $this->requireIntrospectionDriver('showIndexes');

        if (!$this->exists()) {
            return [];
        }

        return $this->isMysql() ? $this->mysqlIndexes() : $this->sqliteIndexes();
    }

    /**
     * Guard the introspection helpers, which only have MySQL and SQLite
     * implementations.
     *
     * Other drivers share the generic iterator fallback happily, so they could
     * otherwise reach a SQLite-only `PRAGMA` statement and fail with a confusing
     * syntax error — or worse, be mistaken for a table with no columns.
     */
    private function requireIntrospectionDriver(string $method): void
    {
        if ($this->isMysql() || $this->isSqlite()) {
            return;
        }

        $driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        throw new \RuntimeException(
            "{$method}() supports MySQL and SQLite; the \"{$driver}\" driver is not supported"
        );
    }

    /**
     * Index metadata from `SHOW INDEX`, grouped by index name.
     *
     * @return array<string, array{name: string, columns: list<string>, unique: bool, primary: bool, type: string}>
     */
    private function mysqlIndexes(): array
    {
        $rows = $this->pdo->query("SHOW INDEX FROM `{$this->table}`")->fetchAll(PDO::FETCH_ASSOC);
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

    /**
     * Index metadata from `PRAGMA index_list`, grouped by index name.
     *
     * @return array<string, array{name: string, columns: list<string>, unique: bool, primary: bool, type: string}>
     */
    private function sqliteIndexes(): array
    {
        $rows = $this->pdo->query("PRAGMA index_list(`{$this->table}`)")->fetchAll(PDO::FETCH_ASSOC);
        $indexes = [];

        foreach ($rows as $row) {
            $name = (string) $row['name'];

            $columns = [];
            foreach ($this->pdo->query("PRAGMA index_info(`{$name}`)")->fetchAll(PDO::FETCH_ASSOC) as $column) {
                $columns[(int) $column['seqno']] = (string) $column['name'];
            }
            ksort($columns);

            $indexes[$name] = [
                'name' => $name,
                'columns' => array_values($columns),
                'unique' => (int) $row['unique'] === 1,
                'primary' => ($row['origin'] ?? '') === 'pk',
                'type' => '',
            ];
        }

        return $indexes;
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
     * Note: `rowCount()` semantics differ between drivers. Verify a migration
     * with `find()` or `count()` rather than trusting this return value alone.
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
     * Note: `rowCount()` semantics differ between drivers. Verify a migration
     * with `find()` or `count()` rather than trusting this return value alone.
     *
     * @param array<string, mixed> $data   Column => value pairs to set
     * @param array<string, mixed> $where  Column => condition (see buildCondition)
     * @return int Number of affected rows
     */
    public function update(array $data, array $where): int
    {
        if ($data === []) {
            return 0;
        }

        $setParts = [];
        $setParams = [];
        $i = 0;
        foreach ($data as $key => $value) {
            $name = 's' . $i++;
            $setParts[] = "`{$key}` = :{$name}";
            $setParams[$name] = $value;
        }

        [$clause, $whereParams] = $this->buildWhere($where);

        $sql = "UPDATE `{$this->table}` SET " . implode(', ', $setParts) . $clause;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($setParams + $whereParams);

        return $stmt->rowCount();
    }

    /**
     * Delete rows matching conditions.
     *
     * An empty `$where` deletes every row, matching the previous behaviour.
     *
     * @param array<string, mixed> $where  Column => condition (see buildCondition)
     * @return int Number of affected rows
     */
    public function delete(array $where): int
    {
        [$clause, $params] = $this->buildWhere($where);

        $stmt = $this->pdo->prepare("DELETE FROM `{$this->table}`{$clause}");
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
        [$clause, $params] = $this->buildWhere($where);

        $sql = "SELECT * FROM `{$this->table}`{$clause} LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * Get all rows matching conditions.
     *
     * @param array<string, mixed> $where  Column => condition (see buildCondition)
     * @param string $orderBy  Raw ORDER BY expression (e.g. "id DESC")
     * @param int|null $limit  Max number of rows
     * @return list<array<string, mixed>>
     */
    public function where(array $where = [], string $orderBy = '', ?int $limit = null): array
    {
        [$clause, $params] = $this->buildWhere($where);

        $sql = "SELECT * FROM `{$this->table}`{$clause}";
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
        [$clause, $params] = $this->buildWhere($where);

        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM `{$this->table}`{$clause}");
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    // ==================== Where compilation ====================

    /**
     * Compile a column => condition map into a WHERE clause.
     *
     * @param array<string, mixed> $where
     * @return array{0: string, 1: array<string, mixed>}  [SQL fragment, bound parameters]
     */
    private function buildWhere(array $where): array
    {
        if ($where === []) {
            return ['', []];
        }

        $parts = [];
        $params = [];
        $i = 0;

        foreach ($where as $column => $condition) {
            $parts[] = $this->buildCondition((string) $column, $condition, 'w' . $i++, $params);
        }

        return [' WHERE ' . implode(' AND ', $parts), $params];
    }

    /**
     * Compile one column condition into a SQL fragment.
     *
     * Accepted forms:
     *   'active'              => equal
     *   42                    => equal
     *   null                  => IS NULL
     *   [1, 2, 3]             => IN
     *   ['>=', 100]           => comparison
     *   ['like', '%foo%']     => LIKE
     *   ['!=', null]          => IS NOT NULL
     *   ['in', [1, 2]]        => IN
     *   ['not in', [1, 2]]    => NOT IN
     *   ['between', [1, 10]]  => BETWEEN
     *
     * Everything stays a single column => condition pair. For anything beyond
     * this — joins, functions, nested groups — use getPdo() and write raw SQL.
     *
     * @param array<string, mixed> $params Accumulated bound parameters (by reference)
     */
    private function buildCondition(string $column, mixed $condition, string $prefix, array &$params): string
    {
        $col = "`{$column}`";

        if ($condition === null) {
            return "{$col} IS NULL";
        }

        if (!is_array($condition)) {
            $params[$prefix] = $condition;
            return "{$col} = :{$prefix}";
        }

        if ($condition === []) {
            throw new InvalidArgumentException("Empty condition list for column \"{$column}\"");
        }

        $operator = is_string($condition[0] ?? null) ? strtolower(trim($condition[0])) : null;

        // [operator, value] tuple
        if ($operator !== null && count($condition) === 2 && in_array($operator, self::OPERATORS, true)) {
            return $this->buildOperatorCondition($col, $column, $operator, $condition[1], $prefix, $params);
        }

        // Bare list => IN
        return $this->buildListCondition($col, $column, $condition, $prefix, $params, false);
    }

    /**
     * Compile an explicit `[operator, value]` condition.
     *
     * @param array<string, mixed> $params Accumulated bound parameters (by reference)
     */
    private function buildOperatorCondition(string $col, string $column, string $operator, mixed $value, string $prefix, array &$params): string
    {
        if ($operator === 'in' || $operator === 'not in') {
            if (!is_array($value) || $value === []) {
                throw new InvalidArgumentException("\"{$operator}\" on column \"{$column}\" expects a non-empty array");
            }

            return $this->buildListCondition($col, $column, $value, $prefix, $params, $operator === 'not in');
        }

        if ($operator === 'between' || $operator === 'not between') {
            if (!is_array($value) || count($value) !== 2) {
                throw new InvalidArgumentException("\"{$operator}\" on column \"{$column}\" expects [min, max]");
            }

            $bounds = array_values($value);
            $params["{$prefix}_lo"] = $bounds[0];
            $params["{$prefix}_hi"] = $bounds[1];

            return "{$col} " . strtoupper($operator) . " :{$prefix}_lo AND :{$prefix}_hi";
        }

        if ($value === null) {
            if ($operator === '=') {
                return "{$col} IS NULL";
            }
            if ($operator === '!=' || $operator === '<>') {
                return "{$col} IS NOT NULL";
            }

            throw new InvalidArgumentException("\"{$operator}\" on column \"{$column}\" does not accept null");
        }

        $params[$prefix] = $value;

        return "{$col} " . strtoupper($operator) . " :{$prefix}";
    }

    /**
     * Compile a value list into IN / NOT IN with one bound parameter per value.
     *
     * @param list<mixed> $values
     * @param array<string, mixed> $params Accumulated bound parameters (by reference)
     */
    private function buildListCondition(string $col, string $column, array $values, string $prefix, array &$params, bool $negate): string
    {
        $placeholders = [];

        foreach (array_values($values) as $index => $value) {
            $name = "{$prefix}_{$index}";
            $params[$name] = $value;
            $placeholders[] = ":{$name}";
        }

        $keyword = $negate ? 'NOT IN' : 'IN';

        return "{$col} {$keyword} (" . implode(', ', $placeholders) . ')';
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

    /**
     * Iterate ordered by an explicit cursor column instead of the detected key.
     *
     * The column must be unique (a primary key or unique index). A non-unique
     * cursor can skip rows that share the cursor value.
     *
     * @return $this
     */
    public function withCursorKey(string $column): self
    {
        $this->iterKeyChoice = $column;
        $this->iterKeyResolved = false;
        return $this;
    }

    /**
     * Resume iteration after an explicit cursor value.
     *
     * Rows whose cursor key is less than or equal to `$value` are skipped, so
     * feeding back a value previously read from cursor() continues exactly
     * where that run stopped. Passing null clears the start point.
     *
     * Requires a single-column cursor key: a table with a composite or absent
     * primary key cannot be resumed safely and throws when iteration begins.
     *
     * @return $this
     */
    public function withCursorStart(mixed $value): self
    {
        $this->iterStart = $value;
        return $this;
    }

    /**
     * The cursor key of the row currently being handled.
     *
     * Record this once a row has been fully processed and pass it back through
     * withCursorStart() on the next attempt:
     *
     *   foreach ($users as $row) {
     *       migrate($row);
     *       saveCheckpoint($users->cursor());
     *   }
     *
     * The value is reported when the row is read, so an interruption while
     * handling it resumes on that same row rather than skipping it — a run is
     * at-least-once, which is what an idempotent migration wants.
     *
     * Returns null when the table has no usable cursor key, or before iteration.
     */
    public function cursor(): mixed
    {
        return $this->iterLastKey;
    }

    /**
     * Return the current row.
     *
     * Recording the cursor here (rather than in next()) means cursor() reports
     * the row the caller is currently handling. A run that dies while handling
     * a row therefore resumes on that same row instead of skipping it.
     */
    public function current(): array
    {
        $row = $this->iterPage[$this->iterIndexInPage];

        if ($this->iterKey !== null && array_key_exists($this->iterKey, $row)) {
            $this->iterLastKey = $row[$this->iterKey];
        }

        return $row;
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

    /** Rewind to the first row, or to the configured cursor start. */
    public function rewind(): void
    {
        $this->iterPosition = 0;
        $this->iterIndexInPage = 0;
        $this->iterPage = [];
        $this->iterDone = false;

        if (!$this->iterKeyResolved) {
            $this->iterKey = $this->iterKeyChoice ?? $this->detectPrimaryKey();
            $this->iterKeyResolved = true;
        }

        if ($this->iterStart !== null && $this->iterKey === null) {
            throw new InvalidArgumentException(
                "Cannot resume `{$this->table}` from a cursor: it has no single-column cursor key"
            );
        }

        $this->iterCursor = $this->iterStart;
        $this->iterLastKey = $this->iterStart;

        $this->fetchPage();
    }

    /** Check if current position is valid, fetching the next page when needed. */
    public function valid(): bool
    {
        if ($this->iterIndexInPage < count($this->iterPage)) {
            return true;
        }

        if ($this->iterDone) {
            return false;
        }

        $this->fetchPage();

        return $this->iterPage !== [];
    }

    /**
     * Fetch the next page of rows.
     *
     * With a single-column key available this pages by key cursor
     * (`WHERE key > last ORDER BY key`), which keeps a stable order and stays
     * flat in cost across a large table. Without one it degrades to
     * `LIMIT offset, size`, which has no stable order.
     */
    private function fetchPage(): void
    {
        $size = $this->iterPageSize;

        if ($this->iterKey !== null) {
            $key = $this->iterKey;
            $sql = "SELECT * FROM `{$this->table}`";

            if ($this->iterCursor !== null) {
                $sql .= " WHERE `{$key}` > :__cursor";
            }

            $sql .= " ORDER BY `{$key}` ASC LIMIT {$size}";

            $stmt = $this->pdo->prepare($sql);
            if ($this->iterCursor !== null) {
                $type = is_int($this->iterCursor) ? PDO::PARAM_INT : PDO::PARAM_STR;
                $stmt->bindValue(':__cursor', $this->iterCursor, $type);
            }
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if ($rows !== []) {
                $this->iterCursor = $rows[count($rows) - 1][$key];
            }
        } else {
            $offset = $this->iterPosition;
            $rows = $this->pdo->query("SELECT * FROM `{$this->table}` LIMIT {$offset}, {$size}")
                ->fetchAll(PDO::FETCH_ASSOC);
        }

        $this->iterPage = $rows;
        $this->iterIndexInPage = 0;
        $this->iterDone = $rows === [];
    }

    /**
     * Detect a single-column primary key usable as an iteration cursor.
     *
     * Returns null for keyless or composite-key tables, which fall back to
     * offset paging.
     */
    private function detectPrimaryKey(): ?string
    {
        try {
            if ($this->isMysql()) {
                $stmt = $this->pdo->query("SHOW KEYS FROM `{$this->table}` WHERE Key_name = 'PRIMARY'");
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

                return count($rows) === 1 ? (string) $rows[0]['Column_name'] : null;
            }

            if ($this->isSqlite()) {
                $stmt = $this->pdo->query("PRAGMA table_info(`{$this->table}`)");
                $keys = [];

                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $column) {
                    if ((int) $column['pk'] > 0) {
                        $keys[] = (string) $column['name'];
                    }
                }

                return count($keys) === 1 ? $keys[0] : null;
            }
        } catch (\PDOException) {
            // Fall through to offset paging.
        }

        return null;
    }

    // ==================== Transactions ====================

    /**
     * Run a callback inside a transaction: commit on success, roll back on error.
     *
     * A convenience wrapper only — the transaction boundary and the decision to
     * use one remain the caller's, and no nesting or retry logic is added.
     *
     * @param callable():mixed $callback
     * @return mixed The callback's return value
     */
    public function withTransaction(callable $callback): mixed
    {
        $this->pdo->beginTransaction();

        try {
            $result = $callback();
            $this->pdo->commit();

            return $result;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }
}
