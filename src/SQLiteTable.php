<?php

declare(strict_types=1);

namespace MiGears\MiTable;

use PDO;
use InvalidArgumentException;

/**
 * SQLite implementation of a single table.
 *
 * Pairs the driver-agnostic behaviour in TableOperations with the SQLite
 * dialect: `sqlite_master` existence checks, `PRAGMA` introspection, and the
 * `ALTER TABLE` forms SQLite actually has.
 *
 * Three operations have no SQLite equivalent, because SQLite cannot alter a
 * table's primary key or a column's type in place. They throw a
 * RuntimeException rather than emitting MySQL syntax that would fail with a
 * confusing syntax error:
 *
 *   modifyColumn()   — rebuild the table with the new definition
 *   addPrimaryKey()  — include the key in CREATE TABLE, or rebuild
 *   dropPrimaryKey() — rebuild the table without it
 *
 * `renameColumn()` ignores its `$definition` argument: SQLite keeps the
 * existing column type, where MySQL has to restate it.
 *
 * Usage:
 *   $table = new SQLiteTable($pdo, 'users');
 *   $table->create([
 *       'id' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
 *       'username' => 'VARCHAR(50) NOT NULL',
 *   ]);
 *   $id = $table->insert(['username' => 'alice']);
 */
class SQLiteTable implements MiTableInterface
{
    use TableOperations;

    /* ==================== Dialect hooks ==================== */

    protected function assertDialect(PDO $pdo): void
    {
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver !== 'sqlite') {
            throw new InvalidArgumentException(
                "SQLiteTable requires a SQLite connection; the \"{$driver}\" driver was given"
            );
        }
    }

    protected function quoteIdentifier(string $name): string
    {
        // Double any embedded backticks. SQLite accepts backtick-quoted
        // identifiers (MySQL-compatibility syntax) and uses the same
        // double-the-quote escape rule.
        return '`' . str_replace('`', '``', $name) . '`';
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
            $stmt = $this->getPdo()->query("PRAGMA table_info({$t})");
            $keys = [];

            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $column) {
                if ((int) $column['pk'] > 0) {
                    $keys[] = (string) $column['name'];
                }
            }

            return count($keys) === 1 ? $keys[0] : null;
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
        // SQLite has no storage engine, charset or collation table options, so
        // the three arguments are accepted and ignored.
        $t = $this->quoteIdentifier($this->getName());
        $defs = $this->columnDefinitions($columns);

        $this->getPdo()->exec("CREATE TABLE {$t} ({$defs})");
    }

    public function exists(): bool
    {
        return $this->catalogHasRow("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ? LIMIT 1");
    }

    public function rename(string $newName): self
    {
        $t = $this->quoteIdentifier($this->getName());
        $new = $this->quoteIdentifier($newName);

        $this->getPdo()->exec("ALTER TABLE {$t} RENAME TO {$new}");

        return new self($this->getPdo(), $newName);
    }

    public function truncate(): void
    {
        $pdo = $this->getPdo();
        $t = $this->quoteIdentifier($this->getName());

        $pdo->exec("DELETE FROM {$t}");

        // MySQL's TRUNCATE restarts AUTO_INCREMENT. SQLite keeps its counter in
        // sqlite_sequence, which only exists once a table uses AUTOINCREMENT.
        if ($this->hasSequenceTable()) {
            $stmt = $pdo->prepare('DELETE FROM sqlite_sequence WHERE name = ?');
            $stmt->execute([$this->getName()]);
        }
    }

    public function addColumn(string $name, string $definition, ?string $after = null): void
    {
        // SQLite has no column-positioning clause; the column is appended and
        // $after is ignored.
        $t = $this->quoteIdentifier($this->getName());
        $col = $this->quoteIdentifier($name);

        $this->getPdo()->exec("ALTER TABLE {$t} ADD COLUMN {$col} {$definition}");
    }

    public function modifyColumn(string $name, string $definition): void
    {
        throw new \RuntimeException(
            'SQLite cannot alter a column definition in place; rebuild the table '
            . '(CREATE a new one, copy the rows, drop the old one, rename)'
        );
    }

    public function renameColumn(string $oldName, string $newName, string $definition): void
    {
        // SQLite keeps the existing type, so $definition is ignored.
        $t = $this->quoteIdentifier($this->getName());
        $old = $this->quoteIdentifier($oldName);
        $new = $this->quoteIdentifier($newName);

        $this->getPdo()->exec("ALTER TABLE {$t} RENAME COLUMN {$old} TO {$new}");
    }

    public function addIndex(string $name, array $columns, string $type = ''): void
    {
        // SQLite has one index implementation, so $type is ignored.
        $idx = $this->quoteIdentifier($name);
        $t = $this->quoteIdentifier($this->getName());
        $cols = $this->columnList($columns);

        $this->getPdo()->exec("CREATE INDEX {$idx} ON {$t} ({$cols})");
    }

    public function dropIndex(string $name): void
    {
        $idx = $this->quoteIdentifier($name);
        $this->getPdo()->exec("DROP INDEX {$idx}");
    }

    public function addUniqueIndex(string $name, array $columns): void
    {
        $idx = $this->quoteIdentifier($name);
        $t = $this->quoteIdentifier($this->getName());
        $cols = $this->columnList($columns);

        $this->getPdo()->exec("CREATE UNIQUE INDEX {$idx} ON {$t} ({$cols})");
    }

    public function addPrimaryKey(array $columns): void
    {
        throw new \RuntimeException(
            'SQLite cannot add a primary key to an existing table; include it in CREATE TABLE or rebuild the table'
        );
    }

    public function dropPrimaryKey(): void
    {
        throw new \RuntimeException(
            'SQLite cannot drop a primary key; rebuild the table without it'
        );
    }

    // ==================== Introspection ====================

    /**
     * `PRAGMA table_info` returns an empty set for a missing table, so unlike
     * MySQL there is no need to check for the table first.
     */
    public function showColumns(): array
    {
        $t = $this->quoteIdentifier($this->getName());
        $rows = $this->getPdo()->query("PRAGMA table_info({$t})")->fetchAll(PDO::FETCH_ASSOC);
        $unique = $this->sqliteUniqueColumns();
        $columns = [];

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
     * List the table's indexes, keyed by index name.
     *
     * An `INTEGER PRIMARY KEY` is the rowid alias and has no index entry at all,
     * so it does not appear here — use showColumns() to detect that primary key.
     * Indexes created by a UNIQUE or PRIMARY KEY constraint do appear, under a
     * generated `sqlite_autoindex_*` name.
     *
     * @return array<string, array{name: string, columns: list<string>, unique: bool, primary: bool, type: string}>
     */
    public function showIndexes(): array
    {
        $t = $this->quoteIdentifier($this->getName());
        $rows = $this->getPdo()->query("PRAGMA index_list({$t})")->fetchAll(PDO::FETCH_ASSOC);
        $indexes = [];

        foreach ($rows as $row) {
            $name = (string) $row['name'];

            $columns = [];
            foreach ($this->indexColumns($name) as $column) {
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

    /**
     * Columns covered by a single-column unique index.
     *
     * @return array<string, true>
     */
    private function sqliteUniqueColumns(): array
    {
        $unique = [];

        foreach ($this->indexList() as $index) {
            if ((int) $index['unique'] !== 1) {
                continue;
            }

            $columns = $this->indexColumns((string) $index['name']);

            if (count($columns) === 1) {
                $unique[(string) $columns[0]['name']] = true;
            }
        }

        return $unique;
    }

    /** @return list<array<string, mixed>> */
    private function indexList(): array
    {
        $t = $this->quoteIdentifier($this->getName());

        return $this->getPdo()->query("PRAGMA index_list({$t})")->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<array<string, mixed>> */
    private function indexColumns(string $indexName): array
    {
        $idx = $this->quoteIdentifier($indexName);

        return $this->getPdo()->query("PRAGMA index_info({$idx})")->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Whether the AUTOINCREMENT counter table exists yet. */
    private function hasSequenceTable(): bool
    {
        $stmt = $this->getPdo()->prepare(
            "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'sqlite_sequence' LIMIT 1"
        );
        $stmt->execute();

        return $stmt->fetchColumn() !== false;
    }
}
