<?php

declare(strict_types=1);

namespace MiGears\MiTable;

use PDO;
use Iterator;

/**
 * The contract every dialect implementation of a single database table honours.
 *
 * A driver-agnostic migration script should depend on this interface and let
 * the concrete class — MySQLTable, SQLiteTable, or one you write — carry the
 * dialect. Everything declared here is expected to work on every implementation
 * except where a method documents a dialect limitation; in that case the
 * implementation throws rather than emitting the wrong SQL silently.
 *
 * @extends Iterator<int, array<string, mixed>>
 */
interface MiTableInterface extends Iterator
{
    public const VERSION = '2.0.0';

    /**
     * Operators accepted in a `[operator, value]` condition.
     *
     * Declared on the contract rather than in the shared trait for two reasons:
     * a trait cannot carry a constant before PHP 8.2, and the accepted operator
     * set is part of what every dialect promises.
     */
    public const OPERATORS = [
        '=', '!=', '<>', '>', '>=', '<', '<=', 'like', 'not like',
        'in', 'not in', 'between', 'not between',
    ];

    /** Get the table name. */
    public function getName(): string;

    /** Get the underlying PDO instance. */
    public function getPdo(): PDO;

    // ==================== DDL ====================

    /**
     * Create the table from a column definition array.
     *
     * `$engine`, `$charset` and `$collate` are MySQL table options; other
     * implementations accept them and ignore them.
     *
     * @param array<string, string> $columns Column name => SQL definition
     */
    public function create(
        array $columns,
        string $engine = 'InnoDB',
        string $charset = 'utf8mb4',
        string $collate = 'utf8mb4_unicode_ci'
    ): void;

    /** Drop the table if it exists. */
    public function drop(): void;

    /** Check whether the table exists, asking the driver catalog. */
    public function exists(): bool;

    /** Rename the table and return an instance bound to the new name. */
    public function rename(string $newName): self;

    /** Remove every row from the table. */
    public function truncate(): void;

    /**
     * Add a column.
     *
     * `$after` is honoured only where the dialect supports column positioning;
     * elsewhere the column is appended and the argument is ignored.
     */
    public function addColumn(string $name, string $definition, ?string $after = null): void;

    /** Drop a column. */
    public function dropColumn(string $name): void;

    /**
     * Change a column's definition.
     *
     * @throws \RuntimeException when the dialect cannot alter a column in place
     */
    public function modifyColumn(string $name, string $definition): void;

    /**
     * Rename a column.
     *
     * `$definition` is required by dialects that must restate the column type
     * and ignored by those that keep it.
     */
    public function renameColumn(string $oldName, string $newName, string $definition): void;

    /**
     * Add an index. `$type` is a dialect-specific index method (e.g. `BTREE`)
     * and is ignored where the dialect has no such choice.
     *
     * @param list<string> $columns
     */
    public function addIndex(string $name, array $columns, string $type = ''): void;

    /** Drop an index by name. */
    public function dropIndex(string $name): void;

    /** @param list<string> $columns */
    public function addUniqueIndex(string $name, array $columns): void;

    /**
     * Add a primary key to an existing table.
     *
     * @param list<string> $columns
     * @throws \RuntimeException when the dialect cannot add one after creation
     */
    public function addPrimaryKey(array $columns): void;

    /**
     * Drop the primary key.
     *
     * @throws \RuntimeException when the dialect cannot drop one
     */
    public function dropPrimaryKey(): void;

    // ==================== Introspection ====================

    /**
     * List the table's columns, keyed by column name. A missing table returns
     * an empty array.
     *
     * @return array<string, array{name: string, type: string, nullable: bool, default: mixed, primary: bool, unique: bool}>
     */
    public function showColumns(): array;

    /**
     * List the table's indexes, keyed by index name. A missing table returns
     * an empty array.
     *
     * @return array<string, array{name: string, columns: list<string>, unique: bool, primary: bool, type: string}>
     */
    public function showIndexes(): array;

    // ==================== CRUD ====================

    /**
     * Insert a row and return the last insert ID.
     *
     * @param array<string, mixed> $data
     */
    public function insert(array $data): string;

    /**
     * Insert several rows in one statement and return the affected row count.
     *
     * `rowCount()` semantics differ between drivers; verify with find() or
     * count() rather than trusting the return value alone.
     *
     * @param list<array<string, mixed>> $rows
     */
    public function bulkInsert(array $rows): int;

    /**
     * Update matching rows and return the affected row count.
     *
     * @param array<string, mixed> $data  Column => value pairs to set
     * @param array<string, mixed> $where Column => condition
     */
    public function update(array $data, array $where): int;

    /**
     * Delete matching rows and return the affected row count. An empty `$where`
     * deletes every row.
     *
     * @param array<string, mixed> $where Column => condition
     */
    public function delete(array $where): int;

    /**
     * Find the first matching row.
     *
     * @param array<string, mixed> $where Column => condition
     * @return array<string, mixed>|null
     */
    public function find(array $where): ?array;

    /**
     * Get all matching rows.
     *
     * `$orderBy` is spliced into the statement as raw SQL and is never bound;
     * never pass user input to it.
     *
     * @param array<string, mixed> $where Column => condition
     * @return list<array<string, mixed>>
     */
    public function where(array $where = [], string $orderBy = '', ?int $limit = null): array;

    /**
     * Count matching rows.
     *
     * @param array<string, mixed> $where Column => condition
     */
    public function count(array $where = []): int;

    // ==================== Iteration ====================

    /**
     * Set the page size used while iterating.
     *
     * @return $this
     */
    public function withPageSize(int $pageSize): self;

    /**
     * Iterate ordered by an explicit cursor column instead of the detected key.
     * The column must be unique.
     *
     * @return $this
     */
    public function withCursorKey(string $column): self;

    /**
     * Resume iteration after an explicit cursor value; pass null to clear it.
     *
     * @return $this
     */
    public function withCursorStart(mixed $value): self;

    /** The cursor key of the row currently being handled, or null. */
    public function cursor(): mixed;

    // ==================== Transactions ====================

    /**
     * Run a callback inside a transaction: commit on success, roll back on
     * error. The transaction boundary stays the caller's, and no nesting or
     * retry logic is added.
     *
     * @param callable():mixed $callback
     * @return mixed The callback's return value
     */
    public function withTransaction(callable $callback): mixed;
}
