# migears/mitable

![Version](https://img.shields.io/badge/version-2.0.0-blue)

Minimalist single-table execution helper for one-off data migrations — DDL, CRUD, schema introspection, and cursor-based iteration, with one class per SQL dialect.

A table class wraps a PDO connection around a single table. Point it at one table, reshape the schema, move and transform rows, then get out of the way. No query builder dependency — just pure PDO.

> **Background**: miGears is the open-source successor of **TinyGears**, a
> self-developed PHP framework. It was renamed and open-sourced recently because
> the name *TinyGears* is already taken in the open-source community.

## Structure

| Piece | Role |
|---|---|
| `MiTableInterface` | The contract: everything a table implementation must do |
| `TableOperations` | The dialect-agnostic half — CRUD, condition compilation, cursor iteration, transactions |
| `MySQLTable` | MySQL / MariaDB dialect |
| `SQLiteTable` | SQLite dialect |

Pick the class that matches your connection:

```php
$users = new MySQLTable($pdo, 'users');   // MySQL or MariaDB
$rows  = new SQLiteTable($pdo, 'rows');   // SQLite
```

The constructor rejects a connection whose driver does not match the class, so a mismatched pairing fails at once instead of emitting foreign SQL later. A script that must run on either can type-hint `MiTableInterface`.

Adding a dialect means writing one class that uses `TableOperations` and fills in four hooks: the driver check, identifier quoting, primary-key detection and offset paging. Everything else comes from the trait.

## What this is, and what it is not

This package is the **execution layer for migration scripts**, not a migration framework.

It knows how to create a table, change its columns, write and transform its rows, and walk it without loading everything into memory. It does not know when a migration should run, whether it already ran, or how to undo it.

| Layer | Responsibility |
|---|---|
| Migration runner (not in this package) | Migration registry, execution order, up/down, idempotency, automatic rollback, cross-table diffing |
| **This package** | Single-table DDL, bulk writes, conditional updates and deletes, memory-safe iteration, schema introspection |
| Raw PDO (escape hatch) | Joins, multi-table writes, complex conditions — reach them through `getPdo()` |

Keeping version management and orchestration outside is deliberate: they need global state and would destroy the readability that makes this class useful.

The reasoning behind this split, the migration scenarios the class is built for, and the record of what changed and why are written up in [docs/mitable-improvement-plan.html](docs/mitable-improvement-plan.html) (in Chinese).

## Features

- **DDL** — create, drop, exists, rename, truncate; add/drop/modify/rename columns; regular, unique, and primary key indexes
- **CRUD** — insert, bulkInsert, update, delete, find, where, count
- **Expressive conditions** — equality, `IN`, `NOT IN`, comparisons, `BETWEEN`, `LIKE`, and NULL matching, all parameter bound
- **Schema introspection** — `showColumns()` reports name, type, nullability, default, primary and unique per column; `showIndexes()` reports every index by name with its columns, so a script can add only what is missing
- **Cursor iteration** — `foreach` over millions of rows with a stable order, paging by primary key instead of `OFFSET`
- **Resumable scans** — `cursor()` and `withCursorStart()` let an interrupted run pick up where it stopped, without skipping or repeating a row
- **Transactions** — an optional `withTransaction()` wrapper; the boundary stays the caller's
- **Cross-driver** — MySQL/MariaDB and SQLite, one class per dialect; add a dialect by implementing four hooks

## Boundaries

**In scope**

- The cross-driver table abstraction: `MiTableInterface`, the dialect-agnostic `TableOperations` trait, and one class per dialect — `MySQLTable` for MySQL/MariaDB and `SQLiteTable` for SQLite (PSR-4 root `MiGears\MiTable`).
- Single-table execution for migration scripts: DDL (create/drop/exists/rename/truncate, column and index changes), CRUD (`insert`, `bulkInsert`, `update`, `delete`, `find`, `where`, `count`), parameter-bound conditions, schema introspection, primary-key cursor iteration with resumable checkpoints, and an optional `withTransaction()` wrapper.
- Driver conformance as a tested property: one shared conformance trait asserts every dialect-shared behaviour on both SQLite (unit suite) and MySQL (integration suite); dialect tests cover only what is deliberately different.
- PHP 8.1+ with `ext-pdo` only — pure PDO, no query-builder dependency.

**Not in scope (by design)**

- The migration runner — migration registry, execution order, up/down, idempotency, automatic rollback and cross-table schema diffing stay outside; this package executes a single migration step.
- Query building and multi-table work — no nested conditions, groups or joins; use `migears/sql` for query building, and reach joins or multi-table writes through `getPdo()`.
- Any model / Active Record layer, and any events, hooks or observers.
- Automatic transaction or rollback management beyond `withTransaction()` (no nesting, no retry); schema changes are not atomic — MySQL commits implicitly around DDL.

## Installation

```bash
composer require migears/mitable
```

Requires: PHP 8.1+, ext-pdo.

## Quick Start

```php
use MiGears\MiTable\MySQLTable;

$pdo = new PDO('mysql:host=localhost;dbname=app', 'user', 'pass');
$users = new MySQLTable($pdo, 'users');

// Create table
$users->create([
    'id' => 'INT UNSIGNED AUTO_INCREMENT PRIMARY KEY',
    'username' => 'VARCHAR(50) NOT NULL',
    'email' => 'VARCHAR(255) NOT NULL UNIQUE',
    'created_at' => 'DATETIME DEFAULT CURRENT_TIMESTAMP',
]);

// Insert
$id = $users->insert([
    'username' => 'alice',
    'email' => 'alice@example.com',
]);

// Bulk insert
$users->bulkInsert([
    ['username' => 'bob', 'email' => 'bob@example.com'],
    ['username' => 'charlie', 'email' => 'charlie@example.com'],
]);

// Find
$user = $users->find(['id' => $id]);

// Query
$activeUsers = $users->where(['status' => 'active'], 'id DESC', 10);

// Update
$users->update(['status' => 'inactive'], ['id' => $id]);

// Delete
$users->delete(['id' => $id]);

// Count
$total = $users->count();
$activeCount = $users->count(['status' => 'active']);
```

## Where Conditions

Every condition is a single `column => condition` pair, and every value is bound as a parameter. The condition's shape decides the SQL:

| Condition | SQL |
|---|---|
| `'alice'` | `col = 'alice'` |
| `42` | `col = 42` |
| `null` | `col IS NULL` |
| `['alice', 'bob']` | `col IN ('alice', 'bob')` |
| `['in', [1, 2]]` | `col IN (1, 2)` |
| `['not in', [1, 2]]` | `col NOT IN (1, 2)` |
| `['>=', 100]` | `col >= 100` |
| `['between', [1, 10]]` | `col BETWEEN 1 AND 10` |
| `['like', '%smith%']` | `col LIKE '%smith%'` |
| `['!=', null]` | `col IS NOT NULL` |

Accepted operators: `=`, `!=`, `<>`, `>`, `>=`, `<`, `<=`, `like`, `not like`, `in`, `not in`, `between`, `not between`. Multiple conditions are joined with `AND`.

```php
// Backfill a derived column for a set of domains
$users->update(
    ['channel' => 'personal'],
    ['email' => ['like', '%@gmail.com']]
);

// Archive a batch by id
$users->update(['archived' => 1], ['id' => ['in', [101, 102, 103]]]);

// Work on a date range
$orders->where(['created_at' => ['between', ['2024-01-01', '2024-12-31']]], 'id ASC');

// Repair rows missing a value
$users->where(['phone' => null]);
```

An empty list throws `InvalidArgumentException` rather than silently matching nothing, so a buggy condition cannot quietly turn into a full-table update. A condition that *starts* with an operator name must be a two-element `[operator, value]` pair; anything else of that shape throws instead of being reinterpreted as a list of values. That means a list whose first value happens to be an operator name needs the explicit form — `['in', ['in', 'out']]`, not `['in', 'out']`.

This is intentionally not a query builder. Conditions stay flat: no nesting, no groups, no joins. When a condition no longer fits, that is the signal to use `getPdo()` and write the SQL directly.

## Iterating Large Tables

```php
$users->withPageSize(1000);

foreach ($users as $row) {
    processUser($row);
}
```

Iteration pages by **primary key cursor** — `WHERE id > :last ORDER BY id ASC LIMIT n` — rather than `LIMIT offset, n`. Two things follow:

- Cost stays flat. `OFFSET` paging makes the server scan every skipped row, which degrades badly on a large table.
- Order is stable. Rows are visited in ascending key order, so removing rows behind the cursor cannot shift the window and skip a row.

The cursor column is auto-detected from the table's single-column primary key. Composite-key and keyless tables fall back to `OFFSET` paging, which has no stable order.

Use `withCursorKey()` when the key is not a plain primary key:

```php
$events->withCursorKey('event_id')->withPageSize(500);
```

The cursor column must be unique (a primary key or unique index). A non-unique cursor can skip rows that share a value.

Because iteration is keyed rather than counted, there is no `COUNT(*)` up front — starting a `foreach` on a huge table is cheap.

### Resuming an interrupted run

A long scan can die partway through. `cursor()` reports the key of the row being handled, and `withCursorStart()` skips everything up to it:

```php
$users->withPageSize(1000)->withCursorStart($checkpoint);

foreach ($users as $row) {
    migrate($row);
    $checkpoint = $users->cursor();
}

saveCheckpoint($checkpoint);
```

Record the checkpoint after the row is finished. The value is reported when the row is read, so a run that dies while handling a row resumes on that same row rather than skipping it — the result is at-least-once, which is what an idempotent migration wants.

Resuming requires a single-column cursor key. On a keyless or composite-key table `withCursorStart()` throws when iteration begins rather than silently restarting from the first row, and `cursor()` returns `null`.

## Schema Introspection

Migration scripts constantly need to ask "does this column exist yet?". `showColumns()` answers without raw SQL:

```php
$columns = $users->showColumns();

if (!isset($columns['phone'])) {
    $users->addColumn('phone', 'VARCHAR(20) NULL', 'email');
}

if (!$columns['email']['unique']) {
    $users->addUniqueIndex('uniq_email', ['email']);
}
```

A missing table returns an empty array, so the same script works whether it is creating the table or altering it.

Each entry is keyed by column name and carries:

| Field | Meaning |
|---|---|
| `name` | Column name |
| `type` | Driver type string (e.g. `VARCHAR(50)`, `varchar(50)`) |
| `nullable` | Whether NULL is allowed. Primary key columns always report `false`, matching MySQL |
| `default` | Default expression, or `null` |
| `primary` | Part of the primary key |
| `unique` | Primary key or covered by a single-column unique index |

`showIndexes()` answers the other half of the question — is there already an index by this name? — and sees indexes that span several columns, which `showColumns()` cannot:

```php
$indexes = $users->showIndexes();

if (!isset($indexes['idx_email'])) {
    $users->addIndex('idx_email', ['email']);
} elseif (!$indexes['idx_email']['unique']) {
    $users->dropIndex('idx_email');
    $users->addUniqueIndex('idx_email', ['email']);
}
```

Each entry is keyed by index name and carries:

| Field | Meaning |
|---|---|
| `name` | Index name |
| `columns` | Indexed columns, in index order |
| `unique` | Whether the index enforces uniqueness |
| `primary` | Whether it is the primary key |
| `type` | Index type on MySQL (e.g. `BTREE`); empty when a driver does not report one |

On SQLite an `INTEGER PRIMARY KEY` is the rowid alias and produces no index entry at all, so it never appears in `showIndexes()`; use `showColumns()` to detect that primary key. Indexes created by a `UNIQUE` or `PRIMARY KEY` constraint do appear, under a generated `sqlite_autoindex_*` name.

## DDL Operations

```php
// Check if table exists
if (!$users->exists()) {
    $users->create([...]);
}

// Add a column
$users->addColumn('bio', 'TEXT', after: 'email');

// Drop a column
$users->dropColumn('old_field');

// Modify a column
$users->modifyColumn('username', 'VARCHAR(100) NOT NULL');

// Add an index
$users->addIndex('idx_email', ['email']);
$users->addUniqueIndex('uniq_username', ['username']);

// Drop an index
$users->dropIndex('idx_email');

// Add primary key
$users->addPrimaryKey(['id']);

// Rename table
$renamed = $users->rename('app_users');

// Truncate
$users->truncate();

// Drop
$users->drop();
```

## Transactions

The transaction boundary stays the caller's. `withTransaction()` only removes the boilerplate around data changes:

```php
$users->withTransaction(function () use ($users) {
    $users->update(['channel' => 'personal'], ['email' => ['like', '%@gmail.com']]);
    $users->update(['channel' => 'work'], ['email' => ['like', '%@example.com']]);
});
```

The callback's return value is passed through. On any `Throwable` the transaction is rolled back and the exception is rethrown. No nesting or retry logic is added — for anything more involved, drive `beginTransaction()` yourself through `getPdo()`.

Schema changes are not covered by this. MySQL commits implicitly around DDL, so an `ALTER TABLE` inside the callback survives a later failure: wrapping `addColumn()` in a transaction does not make it atomic. Split a migration so schema changes and data changes are separate steps, and make each step safe to re-run.

## API Reference

| Method | Description |
|--------|-------------|
| `new MySQLTable(PDO $pdo, string $tableName)` | Constructor (MySQL / MariaDB) |
| `new SQLiteTable(PDO $pdo, string $tableName)` | Constructor (SQLite) |
| `getName()` | Get table name |
| `getPdo()` | Get PDO instance |
| **DDL** | |
| `create($columns, $engine, $charset, $collate)` | Create table |
| `drop()` | Drop table |
| `exists()` | Check if table exists |
| `rename($newName)` | Rename (returns new instance) |
| `truncate()` | Truncate all rows |
| **Columns** | |
| `addColumn($name, $definition, $after = null)` | Add column |
| `dropColumn($name)` | Drop column |
| `modifyColumn($name, $definition)` | Modify column |
| `renameColumn($old, $new, $definition)` | Rename column |
| **Indexes** | |
| `addIndex($name, $columns, $type = '')` | Add index |
| `dropIndex($name)` | Drop index |
| `addUniqueIndex($name, $columns)` | Add unique index |
| `addPrimaryKey($columns)` | Add primary key |
| `dropPrimaryKey()` | Drop primary key |
| **Introspection** | |
| `showColumns()` | Column metadata keyed by name |
| `showIndexes()` | Index metadata keyed by name |
| **CRUD** | |
| `insert($data)` | Insert row, returns last insert ID |
| `bulkInsert($rows)` | Bulk insert, returns affected count |
| `update($data, $where)` | Update rows, returns affected count |
| `delete($where)` | Delete rows, returns affected count |
| `find($where)` | Find first matching row |
| `where($where = [], $orderBy = '', $limit = null)` | Find all matching rows |
| `count($where = [])` | Count matching rows |
| **Iterator** | |
| `withPageSize($pageSize)` | Set iteration page size |
| `withCursorKey($column)` | Set the cursor column explicitly |
| `withCursorStart($value)` | Resume iteration after a cursor value |
| `cursor()` | Cursor key of the row being handled |
| `foreach ($table as $row)` | Iterate over all rows |
| **Transactions** | |
| `withTransaction($callback)` | Run a callback atomically |

## Behaviour Notes

- **`rowCount()` is not a reliable success signal.** Drivers differ on whether it reports matched rows or changed rows. Verify a migration with `find()` or `count()` rather than trusting the return value of `update()` or `bulkInsert()`.
- **`exists()` asks the catalog.** On MySQL and SQLite it queries `information_schema` / `sqlite_master`, so a connection or permission failure raises a real error instead of being reported as "table missing". It has no try/catch on those two paths.
- **`delete([])` deletes every row**, matching the previous behaviour. Pass conditions deliberately.
- **`update($data, [])` updates every row**, the same way `delete([])` does. Pass conditions deliberately.
- **`where()`'s `$orderBy` argument is raw SQL** and is not parameter bound. Never interpolate user input into it.
- **Iteration reads rows in pages**, so a row deleted after its page was loaded is still yielded.
- **`bulkInsert()` requires every row to carry the same columns.** Ragged input throws with the row index and the difference, rather than dropping a later row's extra column or binding null for one it lacks.
- **The connection must be in `PDO::ERRMODE_EXCEPTION`.** No write path inspects the return of `prepare()` or `execute()`, so a silent connection would turn a failed write into an unnoticed no-op; the constructor rejects one instead.
- **`current()` throws an `OutOfBoundsException`** when the iterator is not sitting on a row, rather than emitting a PHP warning and a `TypeError`.

## Driver Support

Each class drives one dialect and refuses the others:

| | `MySQLTable` | `SQLiteTable` |
|---|---|---|
| Table options (`ENGINE`, `CHARSET`, `COLLATE`) | applied | not available, ignored |
| `AFTER` column positioning, `USING` index type | supported | not available, ignored |
| `renameColumn()` definition argument | required | ignored — SQLite keeps the existing type |
| `modifyColumn()` | supported | throws |
| `addPrimaryKey()` / `dropPrimaryKey()` | supported | throws |
| `rename()` / `truncate()` / `renameColumn()` | supported | supported |
| Introspection | `SHOW COLUMNS` / `SHOW INDEX` | `PRAGMA table_info` / `PRAGMA index_list` |

SQLite cannot alter a table's primary key or a column's type in place, so those three operations throw a `RuntimeException` that points at the table-rebuild route, rather than emitting MySQL syntax that would surface as a bare syntax error. `SQLiteTable::truncate()` issues `DELETE FROM` and clears the `sqlite_sequence` counter, so it restarts the identity column the way MySQL's `TRUNCATE` does.

`SQLiteTable` needs SQLite 3.35 or newer for `DROP COLUMN`. For production MySQL, `ext-pdo_mysql` is required.

## Design Philosophy

miGears miTable follows the miGears philosophy: **minimal, readable, and useful**.

- **Four files** — an interface, one shared trait, and one class per dialect; no abstract base class
- **PDO only** — no query builder dependency
- **Safe by default** — every data operation uses prepared statements
- **Small enough to read** — roughly 800 lines of effective code, about half in the shared trait

**What we don't do**:

- No migration runner, version table, or up/down commands
- No automatic transaction or rollback management
- No cross-table schema diffing
- No query builder (use `migears/sql` for that)
- No relationships or joins
- No model / active record pattern
- No events, hooks, or observers

## Testing

```bash
composer test              # both suites
composer test:unit         # SQLite + SQLite conformance, no server needed
composer test:integration  # MySQL + MySQL conformance
```

The tests come in two kinds. **Conformance tests** assert behaviour every dialect must share, and the same trait runs them twice — once on SQLite in the unit suite, once on MySQL in the integration suite. A behaviour only one dialect satisfies therefore fails. **Dialect tests** cover what is deliberately different: MySQL's `AFTER` positioning and `USING` index types, the three DDL verbs SQLite has no `ALTER` equivalent for, and SQLite's rowid-alias primary key.

This split exists because of a real gap: previously the SQLite and MySQL suites tested different things, so "cross-driver" was never a verified property, and the DDL verbs with no SQLite coverage at all went unnoticed.

The unit suite runs against an in-memory SQLite database and needs nothing else. The integration suite resolves a MySQL server in this order:

| Order | Source | Example |
|---|---|---|
| 1 | `MYSQL_DSN` | `mysql://root:secret@127.0.0.1:3306/migears_test` |
| 2 | `MYSQL_HOST` with `MYSQL_PORT`, `MYSQL_DB`, `MYSQL_USER`, `MYSQL_PASSWORD` | `MYSQL_HOST=127.0.0.1` |
| 3 | A throwaway container via `docker` or `podman` | `MYSQL_IMAGE=mysql:8.0` overrides the image |
| 4 | Nothing reachable | every integration test is skipped |

A container spawned for the run is removed when the process ends, and each test method starts from a schema with no tables. A `MYSQL_DSN` or `MYSQL_HOST` you provide is used as given: if it is wrong the suite fails instead of quietly falling back, so a misconfigured job cannot pass by accident.

CI runs both suites on every push across PHP 8.1–8.5, the integration one against a MySQL service container.

## License

MIT

---

# migears/mitable

![Version](https://img.shields.io/badge/version-2.0.0-blue)

极简单表数据库操作助手，服务于一次性数据迁移 —— DDL、CRUD、结构反射与游标迭代，每个 SQL 方言一个实现类。

表操作类将 PDO 连接封装在单个表周围：指向一张表，改它的结构，搬运和转换其中的数据，然后功成身退。没有查询构建器依赖 —— 只有纯 PDO。

## 结构

| 组成 | 职责 |
|---|---|
| `MiTableInterface` | 契约：一个表实现必须做到的全部事情 |
| `TableOperations` | 与方言无关的那一半 —— CRUD、条件编译、游标迭代、事务 |
| `MySQLTable` | MySQL / MariaDB 方言 |
| `SQLiteTable` | SQLite 方言 |

按你的连接选择对应类：

```php
$users = new MySQLTable($pdo, 'users');   // MySQL 或 MariaDB
$rows  = new SQLiteTable($pdo, 'rows');   // SQLite
```

构造函数会拒绝驱动与类不匹配的连接，因此配错会在构造时立刻失败，而不是在后续悄悄发出别家的 SQL。需要在两种库上都能跑的脚本，可以类型标注为 `MiTableInterface`。

新增一个方言，就是写一个使用 `TableOperations` 的类并实现四个钩子：驱动校验、标识符引用、主键探测、偏移分页。其余全部来自 trait。

## 这个包是什么，不是什么

这个包是**迁移脚本的执行层**，不是迁移框架。

它知道如何建表、改列、写入与转换行数据、以及在不把整表读进内存的前提下遍历全表。它不关心迁移该在什么时候跑、是否已经跑过、以及如何撤销。

| 层次 | 职责 |
|---|---|
| 迁移运行器（不在本包） | 迁移记录表、执行顺序、up/down、幂等、自动回滚、跨表结构比对 |
| **本包** | 单表 DDL、批量写入、条件更新与删除、无内存遍历、结构反射 |
| 裸 PDO（逃生通道） | JOIN、多表写入、复杂条件 —— 通过 `getPdo()` 直达 |

把版本管理和编排留在包外是刻意的：它们需要全局状态，一旦塞进来就会毁掉这个类赖以立足的可读性。

这条切分的理由、它服务的迁移场景，以及改了什么、为什么改的完整记录，见 [docs/mitable-improvement-plan.html](docs/mitable-improvement-plan.html)。

## 特性

- **DDL** — create、drop、exists、rename、truncate；列的增删改改名；普通索引、唯一索引、主键
- **CRUD** — insert、bulkInsert、update、delete、find、where、count
- **表达力充分的查询条件** — 等值、`IN`、`NOT IN`、范围比较、`BETWEEN`、`LIKE`、NULL 匹配，全部参数绑定
- **结构反射** — `showColumns()` 返回每列的名称、类型、可空性、默认值、是否主键与唯一；`showIndexes()` 按索引名返回每个索引及其列清单，脚本因此可以"缺什么才补什么"
- **游标迭代** — `foreach` 遍历百万行且顺序稳定，按主键翻页而非 `OFFSET`
- **可续跑的扫描** — `cursor()` 与 `withCursorStart()` 让中断的遍历从断点继续，不跳行也不重复
- **事务** — 可选的 `withTransaction()` 包装；事务边界仍归调用方
- **跨驱动** — MySQL/MariaDB 与 SQLite，每个方言一个类；实现四个钩子即可新增方言

## 边界

**范围内**

- 跨驱动的表抽象：`MiTableInterface`、与方言无关的 `TableOperations` trait，以及每个方言一个类 —— MySQL/MariaDB 的 `MySQLTable`、SQLite 的 `SQLiteTable`（PSR-4 根为 `MiGears\MiTable`）。
- 服务于迁移脚本的单表操作：DDL（create/drop/exists/rename/truncate，列的增删改改名与索引变更）、CRUD（`insert`、`bulkInsert`、`update`、`delete`、`find`、`where`、`count`）、参数绑定条件、结构反射、主键游标迭代与可续跑断点，以及可选的 `withTransaction()` 包装。
- 把驱动一致性当作被测试的性质：同一个一致性 trait 在 SQLite（单元套件）与 MySQL（集成套件）上断言所有方言共享的行为，方言测试只覆盖刻意不同的部分。
- 仅要求 PHP 8.1+ 与 `ext-pdo` —— 纯 PDO，不依赖查询构建器。

**范围外（刻意不做）**

- 迁移运行器 —— 迁移记录表、执行顺序、up/down、幂等、自动回滚、跨表结构比对都留在包外；本包只执行单个迁移步骤。
- 查询构建与多表操作 —— 没有嵌套条件、分组和 JOIN；查询构建请用 `migears/sql`，JOIN 或多表写入请通过 `getPdo()` 直达。
- 任何模型 / Active Record 层，以及任何事件、钩子或观察者。
- `withTransaction()` 之外的自动事务与回滚管理（不提供嵌套、不提供重试）；结构变更不具备原子性 —— MySQL 在 DDL 前后会隐式提交。

## 安装

```bash
composer require migears/mitable
```

要求：PHP 8.1+，ext-pdo。

## 快速开始

```php
use MiGears\MiTable\MySQLTable;

$pdo = new PDO('mysql:host=localhost;dbname=app', 'user', 'pass');
$users = new MySQLTable($pdo, 'users');

// 创建表
$users->create([
    'id' => 'INT UNSIGNED AUTO_INCREMENT PRIMARY KEY',
    'username' => 'VARCHAR(50) NOT NULL',
    'email' => 'VARCHAR(255) NOT NULL UNIQUE',
    'created_at' => 'DATETIME DEFAULT CURRENT_TIMESTAMP',
]);

// 插入
$id = $users->insert([
    'username' => 'alice',
    'email' => 'alice@example.com',
]);

// 批量插入
$users->bulkInsert([
    ['username' => 'bob', 'email' => 'bob@example.com'],
    ['username' => 'charlie', 'email' => 'charlie@example.com'],
]);

// 查询单条
$user = $users->find(['id' => $id]);

// 查询多条
$activeUsers = $users->where(['status' => 'active'], 'id DESC', 10);

// 更新
$users->update(['status' => 'inactive'], ['id' => $id]);

// 删除
$users->delete(['id' => $id]);

// 统计
$total = $users->count();
$activeCount = $users->count(['status' => 'active']);
```

## 查询条件

每个条件都是"列 => 条件"这**一个**键值对，所有值都作为参数绑定。条件的形态决定编译出的 SQL：

| 条件写法 | 生成的 SQL |
|---|---|
| `'alice'` | `col = 'alice'` |
| `42` | `col = 42` |
| `null` | `col IS NULL` |
| `['alice', 'bob']` | `col IN ('alice', 'bob')` |
| `['in', [1, 2]]` | `col IN (1, 2)` |
| `['not in', [1, 2]]` | `col NOT IN (1, 2)` |
| `['>=', 100]` | `col >= 100` |
| `['between', [1, 10]]` | `col BETWEEN 1 AND 10` |
| `['like', '%smith%']` | `col LIKE '%smith%'` |
| `['!=', null]` | `col IS NOT NULL` |

支持的操作符：`=`、`!=`、`<>`、`>`、`>=`、`<`、`<=`、`like`、`not like`、`in`、`not in`、`between`、`not between`。多个条件之间以 `AND` 连接。

```php
// 按邮箱域名批量回填派生列
$users->update(
    ['channel' => 'personal'],
    ['email' => ['like', '%@gmail.com']]
);

// 按 id 集合归档一批数据
$users->update(['archived' => 1], ['id' => ['in', [101, 102, 103]]]);

// 处理某个时间范围
$orders->where(['created_at' => ['between', ['2024-01-01', '2024-12-31']]], 'id ASC');

// 修复缺失字段的行
$users->where(['phone' => null]);
```

空列表会抛出 `InvalidArgumentException`，而不是静默地匹配不到任何行 —— 一个写错的条件不该悄悄变成全表更新。**以操作符名开头的条件必须是两元素的 `[operator, value]` 对**；同形状的其他写法会抛异常，而不会被重新理解成一串取值。因此，当列表的首个取值恰好是操作符名时，需要写显式形式 —— `['in', ['in', 'out']]`，而不是 `['in', 'out']`。

这里刻意不做查询构建器。条件保持扁平：没有嵌套、没有分组、没有 JOIN。当某个条件已经装不下时，那正是改用 `getPdo()` 直接写 SQL 的信号。

## 遍历大表

```php
$users->withPageSize(1000);

foreach ($users as $row) {
    processUser($row);
}
```

迭代按**主键游标**翻页 —— `WHERE id > :last ORDER BY id ASC LIMIT n`，而不是 `LIMIT offset, n`。这带来两点：

- 开销恒定。`OFFSET` 翻页会让服务器扫过所有被跳过的行，在大表上衰减严重。
- 顺序稳定。行按主键升序被访问，因此删除游标之后的记录不会导致窗口位移而漏行。

游标列会自动从表的单列主键探测。复合主键和无主键的表降级为 `OFFSET` 翻页，那种方式没有稳定顺序。

当主键不是普通主键时，用 `withCursorKey()` 显式指定：

```php
$events->withCursorKey('event_id')->withPageSize(500);
```

游标列必须唯一（主键或唯一索引）。非唯一游标会跳过共享同一取值的行。

由于迭代是按游标推进而非计数，起始时没有 `COUNT(*)` —— 对超大表发起一次 `foreach` 是很轻的。

### 中断后续跑

长时间扫描可能中途断掉。`cursor()` 返回当前正在处理那一行的游标键，`withCursorStart()` 则跳过它之前的所有行：

```php
$users->withPageSize(1000)->withCursorStart($checkpoint);

foreach ($users as $row) {
    migrate($row);
    $checkpoint = $users->cursor();
}

saveCheckpoint($checkpoint);
```

请在一行**处理完成之后**记录断点。游标值是在读取该行时报告的，因此某行处理到一半崩溃，重跑会从这一行本身继续而不是跳过它——整体语义是"至少一次"，这正是幂等迁移想要的。

续跑要求存在单列游标键。在无主键或复合主键的表上调用 `withCursorStart()` 会在开始遍历时抛异常，而不是悄悄从第一行重扫；这些情况下 `cursor()` 返回 `null`。

## 结构反射

迁移脚本总要问"这一列存在了吗"。`showColumns()` 让你不必写裸 SQL 就能回答：

```php
$columns = $users->showColumns();

if (!isset($columns['phone'])) {
    $users->addColumn('phone', 'VARCHAR(20) NULL', 'email');
}

if (!$columns['email']['unique']) {
    $users->addUniqueIndex('uniq_email', ['email']);
}
```

表不存在时返回空数组，因此同一段脚本在"建表"和"改表"两种情况下都能工作。

返回值以列名作为键，每项包含：

| 字段 | 含义 |
|---|---|
| `name` | 列名 |
| `type` | 驱动返回的类型串（如 `VARCHAR(50)`、`varchar(50)`） |
| `nullable` | 是否允许 NULL。主键列一律返回 `false`，与 MySQL 保持一致 |
| `default` | 默认值表达式，无则为 `null` |
| `primary` | 是否属于主键 |
| `unique` | 主键，或被单列唯一索引覆盖 |

`showIndexes()` 回答问题的另一半——"是否已有名为 idx_email 的索引"——并且能看见跨多列的索引，这是 `showColumns()` 做不到的：

```php
$indexes = $users->showIndexes();

if (!isset($indexes['idx_email'])) {
    $users->addIndex('idx_email', ['email']);
} elseif (!$indexes['idx_email']['unique']) {
    $users->dropIndex('idx_email');
    $users->addUniqueIndex('idx_email', ['email']);
}
```

返回值以索引名作为键，每项包含：

| 字段 | 含义 |
|---|---|
| `name` | 索引名 |
| `columns` | 索引包含的列，按索引顺序排列 |
| `unique` | 是否强制唯一 |
| `primary` | 是否为主键 |
| `type` | MySQL 上的索引类型（如 `BTREE`）；驱动不报告时为空字符串 |

在 SQLite 上，`INTEGER PRIMARY KEY` 是 rowid 别名，完全不产生索引条目，因此不会出现在 `showIndexes()` 里；检测这种主键请用 `showColumns()`。由 `UNIQUE` 或 `PRIMARY KEY` 约束创建的索引会出现，名字是自动生成的 `sqlite_autoindex_*`。

## DDL 操作

```php
// 检查表是否存在
if (!$users->exists()) {
    $users->create([...]);
}

// 添加列
$users->addColumn('bio', 'TEXT', after: 'email');

// 删除列
$users->dropColumn('old_field');

// 修改列
$users->modifyColumn('username', 'VARCHAR(100) NOT NULL');

// 添加索引
$users->addIndex('idx_email', ['email']);
$users->addUniqueIndex('uniq_username', ['username']);

// 删除索引
$users->dropIndex('idx_email');

// 添加主键
$users->addPrimaryKey(['id']);

// 重命名表
$renamed = $users->rename('app_users');

// 清空表
$users->truncate();

// 删除表
$users->drop();
```

## 事务

事务边界仍归调用方，`withTransaction()` 只负责省掉数据变更周围的样板代码：

```php
$users->withTransaction(function () use ($users) {
    $users->update(['channel' => 'personal'], ['email' => ['like', '%@gmail.com']]);
    $users->update(['channel' => 'work'], ['email' => ['like', '%@example.com']]);
});
```

回调的返回值会被透传。一旦抛出 `Throwable`，事务回滚并重新抛出异常。不提供嵌套或重试 —— 更复杂的场景请通过 `getPdo()` 自行控制 `beginTransaction()`。

结构变更不在这个保护范围内。MySQL 在 DDL 前后会隐式提交，因此回调里的 `ALTER TABLE` 在后续失败后依然会留下：把 `addColumn()` 包进事务并不能让它具备原子性。请把迁移拆成"结构变更"与"数据变更"两个独立步骤，并让每一步都能安全重跑。

## API 参考

| 方法 | 说明 |
|------|------|
| `new MySQLTable(PDO $pdo, string $tableName)` | 构造函数（MySQL / MariaDB） |
| `new SQLiteTable(PDO $pdo, string $tableName)` | 构造函数（SQLite） |
| `getName()` | 获取表名 |
| `getPdo()` | 获取 PDO 实例 |
| **DDL** | |
| `create($columns, $engine, $charset, $collate)` | 创建表 |
| `drop()` | 删除表 |
| `exists()` | 检查表是否存在 |
| `rename($newName)` | 重命名（返回新实例） |
| `truncate()` | 清空所有行 |
| **列操作** | |
| `addColumn($name, $definition, $after = null)` | 添加列 |
| `dropColumn($name)` | 删除列 |
| `modifyColumn($name, $definition)` | 修改列 |
| `renameColumn($old, $new, $definition)` | 重命名列 |
| **索引操作** | |
| `addIndex($name, $columns, $type = '')` | 添加索引 |
| `dropIndex($name)` | 删除索引 |
| `addUniqueIndex($name, $columns)` | 添加唯一索引 |
| `addPrimaryKey($columns)` | 添加主键 |
| `dropPrimaryKey()` | 删除主键 |
| **结构反射** | |
| `showColumns()` | 以列名为键返回列元数据 |
| `showIndexes()` | 以索引名为键返回索引元数据 |
| **CRUD** | |
| `insert($data)` | 插入行，返回最后插入 ID |
| `bulkInsert($rows)` | 批量插入，返回影响行数 |
| `update($data, $where)` | 更新行，返回影响行数 |
| `delete($where)` | 删除行，返回影响行数 |
| `find($where)` | 查找第一条匹配行 |
| `where($where = [], $orderBy = '', $limit = null)` | 查找所有匹配行 |
| `count($where = [])` | 统计匹配行数 |
| **迭代器** | |
| `withPageSize($pageSize)` | 设置迭代页大小 |
| `withCursorKey($column)` | 显式指定游标列 |
| `withCursorStart($value)` | 从指定游标值之后继续遍历 |
| `cursor()` | 当前正在处理那一行的游标键 |
| `foreach ($table as $row)` | 遍历所有行 |
| **事务** | |
| `withTransaction($callback)` | 原子地执行一个回调 |

## 行为说明

- **`rowCount()` 不是可靠的成功判据。** 不同驱动对"匹配行数"和"实际变更行数"的返回并不一致。请用 `find()` 或 `count()` 校验迁移结果，而不是相信 `update()` 或 `bulkInsert()` 的返回值。
- **`exists()` 查的是数据字典。** 在 MySQL 和 SQLite 上它查询 `information_schema` / `sqlite_master`，因此连接或权限故障会抛出真实错误，而不会被报告成"表不存在"。这两条路径上没有 try/catch。
- **`delete([])` 会删除全部行**，与既有行为一致。请有意识地传条件。
- **`update($data, [])` 会更新全部行**，与 `delete([])` 相同。请有意识地传条件。
- **`where()` 的 `$orderBy` 参数是裸 SQL**，不做参数绑定。绝不要把用户输入拼进这里。
- **迭代按页读取**，因此某个行所在页已加载后它才被删除，该行仍会被返回。
- **`bulkInsert()` 要求每行携带相同的列。** 参差输入会抛异常并指出行号与差异，而不是丢弃后续行多出的列、或为缺失的列绑定 null。
- **连接必须处于 `PDO::ERRMODE_EXCEPTION`。** 所有写路径都不检查 `prepare()` / `execute()` 的返回值，静默模式会让写失败变成无人察觉的空操作；构造函数会直接拒绝这种连接。
- **`current()` 在迭代器未落在某一行时会抛 `OutOfBoundsException`**，而不是先发一条 PHP 警告再抛 `TypeError`。

## 驱动支持

每个类只驱动一种方言，并拒绝其他方言：

| | `MySQLTable` | `SQLiteTable` |
|---|---|---|
| 表选项（`ENGINE`、`CHARSET`、`COLLATE`） | 生效 | 不存在，忽略 |
| `AFTER` 列定位、`USING` 索引类型 | 支持 | 不存在，忽略 |
| `renameColumn()` 的 `$definition` 参数 | 必需 | 忽略 —— SQLite 保留原有类型 |
| `modifyColumn()` | 支持 | 抛异常 |
| `addPrimaryKey()` / `dropPrimaryKey()` | 支持 | 抛异常 |
| `rename()` / `truncate()` / `renameColumn()` | 支持 | 支持 |
| 结构反射 | `SHOW COLUMNS` / `SHOW INDEX` | `PRAGMA table_info` / `PRAGMA index_list` |

SQLite 无法就地修改主键或列类型，因此上述三个方法会抛出 `RuntimeException`，并在消息里指明需要重建表，而不是发出 MySQL 语法、让使用者看到一个没头没尾的语法错误。`SQLiteTable::truncate()` 使用 `DELETE FROM` 并清空 `sqlite_sequence` 计数，因此和 MySQL 的 `TRUNCATE` 一样会重置自增列。

`SQLiteTable` 的 `DROP COLUMN` 需要 SQLite 3.35 或更高版本。生产环境 MySQL 部署需要 `ext-pdo_mysql`。

## 设计哲学

miGears miTable 遵循 miGears 设计哲学：**极简、可读、实用**。

- **四个文件** — 一个接口、一个公用 trait、每个方言一个类；没有需要继承的抽象基类
- **仅依赖 PDO** — 不依赖查询构建器
- **默认安全** — 所有数据操作都使用预处理语句
- **小到可以读完** — 约 800 行有效代码，其中约一半是公用 trait

**我们不做的事**：

- 没有迁移运行器、版本表或 up/down 命令
- 没有自动的事务与回滚管理
- 没有跨表结构差异比对
- 没有查询构建器（用 `migears/sql`）
- 没有关联或 JOIN
- 没有模型 / Active Record 模式
- 没有事件、钩子或观察者

## 测试

```bash
composer test              # 两个套件
composer test:unit         # SQLite + SQLite 一致性，无需任何服务
composer test:integration  # MySQL + MySQL 一致性
```

测试分两类。**一致性测试**断言所有方言必须一致的行为，同一个 trait 把它跑两遍——一遍在单元套件的 SQLite 上，一遍在集成套件的 MySQL 上。因此"只有一个方言满足"的行为必然失败。**方言测试**覆盖刻意不同的部分：MySQL 的 `AFTER` 定位与 `USING` 索引类型、SQLite 上三个无 `ALTER` 等价写法的 DDL 动词，以及 SQLite 的 rowid 别名主键。

这个划分源于一个真实缺口：此前 SQLite 与 MySQL 两个套件测的是**不同**的东西，所以"跨驱动"从来不是一条被验证过的性质，那些在 SQLite 上零覆盖的 DDL 动词也就一直没被发现。

单元套件运行在内存 SQLite 上，不需要数据库服务。集成套件按以下顺序解析可用服务：

| 顺序 | 来源 | 示例 |
|---|---|---|
| 1 | `MYSQL_DSN` | `mysql://root:secret@127.0.0.1:3306/migears_test` |
| 2 | `MYSQL_HOST` 配合 `MYSQL_PORT`、`MYSQL_DB`、`MYSQL_USER`、`MYSQL_PASSWORD` | `MYSQL_HOST=127.0.0.1` |
| 3 | 通过 `docker` 或 `podman` 起一个一次性容器 | `MYSQL_IMAGE=mysql:8.0` 可覆盖镜像 |
| 4 | 都不可用 | 所有集成测试标记为跳过 |

运行期间起的容器会在进程结束时删除，每个测试方法都从"不含任何表"的空库开始。你显式提供的 `MYSQL_DSN` 或 `MYSQL_HOST` 会被原样使用：配错了就是失败，而不是悄悄降级，因此配置错误的流水线不会意外通过。

CI 在每次推送时都会跑这两个套件，PHP 版本覆盖 8.1–8.5，其中集成套件跑在 MySQL service container 上。

## 许可证

MIT
