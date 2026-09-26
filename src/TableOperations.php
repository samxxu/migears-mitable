<?php

declare(strict_types=1);

namespace MiGears\MiTable;

use PDO;
use InvalidArgumentException;

/**
 * The driver-agnostic half of a MiTable implementation.
 *
 * Holds everything that is the same on every dialect — CRUD, condition
 * compilation, cursor iteration and the transaction wrapper — so a dialect
 * class only has to supply the parts that differ. A dialect class uses this
 * trait, implements MiTableInterface, and fills in the abstract hooks below.
 *
 * The seam is deliberately narrow. Identifier quoting and offset paging are
 * abstract because they leak into this "portable" half: quoting appears in
 * every statement the trait builds, and `LIMIT offset, size` is accepted by
 * MySQL and SQLite but not by every driver.
 */
trait TableOperations
{
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
    /** @var list<array<string, mixed>> */
    private array $iterPage = [];
    private int $iterIndexInPage = 0;
    private bool $iterDone = false;
    private mixed $iterCursor = null;
    private mixed $iterStart = null;
    private mixed $iterLastKey = null;
    private ?string $iterKey = null;
    private ?string $iterKeyChoice = null;
    private bool $iterKeyResolved = false;

    /* ==================== Dialect hooks ==================== */

    /**
     * Reject a connection this dialect cannot drive.
     *
     * Called from the constructor so a mismatched pairing fails at once rather
     * than emitting foreign SQL later.
     */
    abstract protected function assertDialect(PDO $pdo): void;

    /**
     * Quote one identifier for this dialect.
     *
     * Both built-in dialects use backticks; a dialect that needs double quotes
     * overrides this and every statement the trait builds follows suit.
     */
    abstract protected function quoteIdentifier(string $name): string;

    /**
     * Detect a single-column primary key usable as an iteration cursor.
     *
     * Return null for keyless or composite-key tables, which fall back to
     * offset paging.
     */
    abstract protected function detectPrimaryKey(): ?string;

    /**
     * Fetch one page by offset, for the fallback used when no cursor key exists.
     *
     * @return list<array<string, mixed>>
     */
    abstract protected function fetchOffsetPage(string $quotedTable, int $offset, int $size): array;

    /* ==================== Construction ==================== */

    public function __construct(PDO $pdo, string $tableName)
    {
        $this->assertDialect($pdo);

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

    /**
     * Run a catalog lookup bound to the current table name.
     *
     * Shared by the dialect exists() implementations.
     */
    protected function catalogHasRow(string $sql): bool
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$this->table]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Render a column definition list for CREATE TABLE, rejecting empty input.
     *
     * @param array<string, string> $columns Column name => SQL definition
     */
    protected function columnDefinitions(array $columns): string
    {
        if ($columns === []) {
            throw new InvalidArgumentException('Cannot create table with no columns');
        }

        $defs = [];
        foreach ($columns as $name => $definition) {
            $defs[] = $this->quoteIdentifier((string) $name) . " {$definition}";
        }

        return implode(', ', $defs);
    }

    /**
     * Render a comma-separated, quoted column list.
     *
     * @param list<string> $columns
     */
    protected function columnList(array $columns): string
    {
        return implode(', ', array_map(fn($c) => $this->quoteIdentifier($c), $columns));
    }

    /* ==================== Shared DDL ==================== */

    /**
     * Drop the table if it exists.
     *
     * `DROP TABLE IF EXISTS` is accepted by every supported dialect.
     */
    public function drop(): void
    {
        $t = $this->quoteIdentifier($this->table);
        $this->pdo->exec("DROP TABLE IF EXISTS {$t}");
    }

    /**
     * Drop a column.
     *
     * `ALTER TABLE ... DROP COLUMN` is shared by MySQL and SQLite; SQLite has
     * supported it since 3.35.
     */
    public function dropColumn(string $name): void
    {
        $t = $this->quoteIdentifier($this->table);
        $col = $this->quoteIdentifier($name);

        $this->pdo->exec("ALTER TABLE {$t} DROP COLUMN {$col}");
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

        $cols = $this->columnList(array_map(strval(...), $columns));
        $vals = implode(', ', $placeholders);
        $t = $this->quoteIdentifier($this->table);

        $stmt = $this->pdo->prepare("INSERT INTO {$t} ({$cols}) VALUES ({$vals})");
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
        $cols = $this->columnList(array_map(strval(...), $columns));
        $t = $this->quoteIdentifier($this->table);

        $valueSets = [];
        $params = [];
        foreach ($rows as $i => $row) {
            $placeholders = array_map(fn($c) => ":{$c}_{$i}", $columns);
            $valueSets[] = '(' . implode(', ', $placeholders) . ')';
            foreach ($columns as $c) {
                $params["{$c}_{$i}"] = $row[$c];
            }
        }

        $sql = "INSERT INTO {$t} ({$cols}) VALUES " . implode(', ', $valueSets);
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
            $setParts[] = $this->quoteIdentifier((string) $key) . " = :{$name}";
            $setParams[$name] = $value;
        }

        [$clause, $whereParams] = $this->buildWhere($where);
        $t = $this->quoteIdentifier($this->table);

        $sql = "UPDATE {$t} SET " . implode(', ', $setParts) . $clause;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($setParams + $whereParams);

        return $stmt->rowCount();
    }

    /**
     * Delete rows matching conditions.
     *
     * An empty `$where` deletes every row.
     *
     * @param array<string, mixed> $where  Column => condition (see buildCondition)
     * @return int Number of affected rows
     */
    public function delete(array $where): int
    {
        [$clause, $params] = $this->buildWhere($where);
        $t = $this->quoteIdentifier($this->table);

        $stmt = $this->pdo->prepare("DELETE FROM {$t}{$clause}");
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
        $t = $this->quoteIdentifier($this->table);

        $sql = "SELECT * FROM {$t}{$clause} LIMIT 1";
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
        $t = $this->quoteIdentifier($this->table);

        $sql = "SELECT * FROM {$t}{$clause}";
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

    /**
     * Count rows matching conditions.
     *
     * @param array<string, mixed> $where Column => condition (see buildCondition)
     */
    public function count(array $where = []): int
    {
        [$clause, $params] = $this->buildWhere($where);
        $t = $this->quoteIdentifier($this->table);

        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM {$t}{$clause}");
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
        $col = $this->quoteIdentifier($column);

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
     * @param array<int|string, mixed> $values
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
     *
     * @return array<string, mixed>
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
     * flat in cost across a large table. Without one it degrades to offset
     * paging, which has no stable order.
     */
    private function fetchPage(): void
    {
        $size = $this->iterPageSize;
        $t = $this->quoteIdentifier($this->table);

        if ($this->iterKey !== null) {
            $key = $this->quoteIdentifier($this->iterKey);
            $sql = "SELECT * FROM {$t}";

            if ($this->iterCursor !== null) {
                $sql .= " WHERE {$key} > :__cursor";
            }

            $sql .= " ORDER BY {$key} ASC LIMIT {$size}";

            $stmt = $this->pdo->prepare($sql);
            if ($this->iterCursor !== null) {
                $type = is_int($this->iterCursor) ? PDO::PARAM_INT : PDO::PARAM_STR;
                $stmt->bindValue(':__cursor', $this->iterCursor, $type);
            }
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if ($rows !== []) {
                $this->iterCursor = $rows[count($rows) - 1][$this->iterKey];
            }
        } else {
            $rows = $this->fetchOffsetPage($t, $this->iterPosition, $size);
        }

        $this->iterPage = $rows;
        $this->iterIndexInPage = 0;
        $this->iterDone = $rows === [];
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
