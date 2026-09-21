# migears/mitable

![Version](https://img.shields.io/badge/version-2.0.0-blue)

Minimalist single-table execution helper for one-off data migrations — DDL, CRUD, schema introspection, and cursor-based iteration in one class.

MiTable wraps a PDO connection around a single table. Point it at one table, reshape the schema, move and transform rows, then get out of the way. No query builder dependency — just pure PDO.

## What this is, and what it is not

MiTable is the **execution layer for migration scripts**, not a migration framework.

It knows how to create a table, change its columns, write and transform its rows, and walk it without loading everything into memory. It does not know when a migration should run, whether it already ran, or how to undo it.

| Layer | Responsibility |
|---|---|
| Migration runner (not in this package) | Migration registry, execution order, up/down, idempotency, automatic rollback, cross-table diffing |
| **MiTable (this package)** | Single-table DDL, bulk writes, conditional updates and deletes, memory-safe iteration, schema introspection |
| Raw PDO (escape hatch) | Joins, multi-table writes, complex conditions — reach them through `getPdo()` |

Keeping version management and orchestration outside is deliberate: they need global state and would destroy the readability that makes this class useful.

## Features

- **DDL** — create, drop, exists, rename, truncate; add/drop/modify/rename columns; regular, unique, and primary key indexes
- **CRUD** — insert, bulkInsert, update, delete, find, where, count
- **Expressive conditions** — equality, `IN`, `NOT IN`, comparisons, `BETWEEN`, `LIKE`, and NULL matching, all parameter bound
- **Schema introspection** — `showColumns()` reports name, type, nullability, default, primary and unique per column; `showIndexes()` reports every index by name with its columns, so a script can add only what is missing
- **Cursor iteration** — `foreach` over millions of rows with a stable order, paging by primary key instead of `OFFSET`
- **Resumable scans** — `cursor()` and `withCursorStart()` let an interrupted run pick up where it stopped, without skipping or repeating a row
- **Transactions** — an optional `withTransaction()` wrapper; the boundary stays the caller's
- **Cross-driver** — MySQL/MariaDB and SQLite, with a graceful generic fallback

## Installation

```bash
composer require migears/mitable
```

Requires: PHP 8.1+, ext-pdo.

## Quick Start

```php
use MiGears\MiTable\MiTable;

$pdo = new PDO('mysql:host=localhost;dbname=app', 'user', 'pass');
$users = new MiTable($pdo, 'users');

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

An empty list throws `InvalidArgumentException` rather than silently matching nothing, so a buggy condition cannot quietly turn into a full-table update.

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
| `new MiTable(PDO $pdo, string $tableName)` | Constructor |
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
- **`where()`'s `$orderBy` argument is raw SQL** and is not parameter bound. Never interpolate user input into it.
- **Iteration reads rows in pages**, so a row deleted after its page was loaded is still yielded.

## Driver Support

MySQL/MariaDB and SQLite are the supported targets. DDL table options (`ENGINE`, `CHARSET`, `COLLATE`) and `AFTER` positioning apply to MySQL and are ignored or adapted on SQLite. `rowCount()` semantics vary by driver, as noted above.

Introspection is implemented for MySQL (`SHOW COLUMNS`, `SHOW KEYS`, `SHOW INDEX`) and SQLite (`PRAGMA table_info`, `PRAGMA index_list`, `PRAGMA index_info`). On any other driver, `showColumns()` and `showIndexes()` throw a `RuntimeException` naming the driver, rather than reaching a SQLite-only statement and failing with a confusing syntax error. Everything else stays driver-agnostic: the iterator falls back to `OFFSET` paging and `exists()` probes the table directly. For production MySQL, `ext-pdo_mysql` is required.

## Design Philosophy

miGears MiTable follows the miGears philosophy: **minimal, readable, and useful**.

- **One class** — no abstracts, no traits, no interfaces
- **PDO only** — no query builder dependency
- **Safe by default** — every data operation uses prepared statements
- **Small enough to read** — roughly 540 lines of code

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
composer test:unit         # SQLite only, no server needed
composer test:integration  # MySQL/MariaDB
```

The unit suite runs against an in-memory SQLite database and needs nothing else. It covers DDL, CRUD, condition compilation, schema introspection, transactions, and iterator behaviour including cursor paging, fallback paging, and row removal mid-iteration.

The integration suite exercises the MySQL-specific paths the unit suite cannot reach: `information_schema` existence checks, `SHOW COLUMNS` / `SHOW KEYS` introspection, MySQL table options, `AFTER` column positioning, `USING` index types, and MySQL's `rowCount` semantics. It resolves a server in this order:

| Order | Source | Example |
|---|---|---|
| 1 | `MYSQL_DSN` | `mysql://root:secret@127.0.0.1:3306/migears_test` |
| 2 | `MYSQL_HOST` with `MYSQL_PORT`, `MYSQL_DB`, `MYSQL_USER`, `MYSQL_PASSWORD` | `MYSQL_HOST=127.0.0.1` |
| 3 | A throwaway container via `docker` or `podman` | `MYSQL_IMAGE=mysql:8.0` overrides the image |
| 4 | Nothing reachable | every integration test is skipped |

A container spawned for the run is removed when the process ends, and each test method starts from a schema with no tables. A `MYSQL_DSN` or `MYSQL_HOST` you provide is used as given: if it is wrong the suite fails instead of quietly falling back, so a misconfigured job cannot pass by accident.

CI runs both suites on every push across PHP 8.1–8.4, the integration one against a MySQL service container.

## License

MIT

---

# migears/mitable

![Version](https://img.shields.io/badge/version-2.0.0-blue)

极简单表数据库操作助手，服务于一次性数据迁移 —— 一个类搞定 DDL、CRUD、结构反射和游标迭代。

MiTable 将 PDO 连接封装在单个表周围：指向一张表，改它的结构，搬运和转换其中的数据，然后功成身退。没有查询构建器依赖 —— 只有纯 PDO。

## 这个包是什么，不是什么

MiTable 是**迁移脚本的执行层**，不是迁移框架。

它知道如何建表、改列、写入与转换行数据、以及在不把整表读进内存的前提下遍历全表。它不关心迁移该在什么时候跑、是否已经跑过、以及如何撤销。

| 层次 | 职责 |
|---|---|
| 迁移运行器（不在本包） | 迁移记录表、执行顺序、up/down、幂等、自动回滚、跨表结构比对 |
| **MiTable（本包）** | 单表 DDL、批量写入、条件更新与删除、无内存遍历、结构反射 |
| 裸 PDO（逃生通道） | JOIN、多表写入、复杂条件 —— 通过 `getPdo()` 直达 |

把版本管理和编排留在包外是刻意的：它们需要全局状态，一旦塞进来就会毁掉这个类赖以立足的可读性。

## 特性

- **DDL** — create、drop、exists、rename、truncate；列的增删改改名；普通索引、唯一索引、主键
- **CRUD** — insert、bulkInsert、update、delete、find、where、count
- **表达力充分的查询条件** — 等值、`IN`、`NOT IN`、范围比较、`BETWEEN`、`LIKE`、NULL 匹配，全部参数绑定
- **结构反射** — `showColumns()` 返回每列的名称、类型、可空性、默认值、是否主键与唯一；`showIndexes()` 按索引名返回每个索引及其列清单，脚本因此可以"缺什么才补什么"
- **游标迭代** — `foreach` 遍历百万行且顺序稳定，按主键翻页而非 `OFFSET`
- **可续跑的扫描** — `cursor()` 与 `withCursorStart()` 让中断的遍历从断点继续，不跳行也不重复
- **事务** — 可选的 `withTransaction()` 包装；事务边界仍归调用方
- **跨驱动** — MySQL/MariaDB 与 SQLite，其他驱动优雅降级

## 安装

```bash
composer require migears/mitable
```

要求：PHP 8.1+，ext-pdo。

## 快速开始

```php
use MiGears\MiTable\MiTable;

$pdo = new PDO('mysql:host=localhost;dbname=app', 'user', 'pass');
$users = new MiTable($pdo, 'users');

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

空列表会抛出 `InvalidArgumentException`，而不是静默地匹配不到任何行 —— 一个写错的条件不该悄悄变成全表更新。

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
| `new MiTable(PDO $pdo, string $tableName)` | 构造函数 |
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
- **`where()` 的 `$orderBy` 参数是裸 SQL**，不做参数绑定。绝不要把用户输入拼进这里。
- **迭代按页读取**，因此某个行所在页已加载后它才被删除，该行仍会被返回。

## 驱动支持

支持的目标是 MySQL/MariaDB 与 SQLite。DDL 表选项（`ENGINE`、`CHARSET`、`COLLATE`）和 `AFTER` 定位针对 MySQL 生效，在 SQLite 上被忽略或改写。`rowCount()` 的语义随驱动而异，见上文。

结构反射分别针对 MySQL（`SHOW COLUMNS`、`SHOW KEYS`、`SHOW INDEX`）与 SQLite（`PRAGMA table_info`、`PRAGMA index_list`、`PRAGMA index_info`）实现。在其他驱动上，`showColumns()` 与 `showIndexes()` 会抛出 `RuntimeException` 并指明驱动名，而不是走到只有 SQLite 才有的语句上抛出令人困惑的语法错误。其余能力保持驱动无关：迭代器降级为 `OFFSET` 翻页，`exists()` 直接探测表。生产环境 MySQL 部署需要 `ext-pdo_mysql`。

## 设计哲学

miGears MiTable 遵循 miGears 设计哲学：**极简、可读、实用**。

- **一个类** — 没有抽象类、没有 trait、没有接口
- **仅依赖 PDO** — 不依赖查询构建器
- **默认安全** — 所有数据操作都使用预处理语句
- **小到可以读完** — 约 540 行代码

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
composer test:unit         # 仅 SQLite，无需任何服务
composer test:integration  # MySQL/MariaDB
```

单元测试套件运行在内存 SQLite 上，不需要数据库服务。覆盖 DDL、CRUD、条件编译、结构反射、事务，以及迭代器行为（含游标翻页、降级翻页和迭代中途删行）。

集成测试套件专门跑单元测试触及不到的 MySQL 专属路径：`information_schema` 存在性检查、`SHOW COLUMNS` / `SHOW KEYS` 结构反射、MySQL 建表选项、`AFTER` 列定位、`USING` 索引类型，以及 MySQL 的 `rowCount` 语义。它按以下顺序解析可用服务：

| 顺序 | 来源 | 示例 |
|---|---|---|
| 1 | `MYSQL_DSN` | `mysql://root:secret@127.0.0.1:3306/migears_test` |
| 2 | `MYSQL_HOST` 配合 `MYSQL_PORT`、`MYSQL_DB`、`MYSQL_USER`、`MYSQL_PASSWORD` | `MYSQL_HOST=127.0.0.1` |
| 3 | 通过 `docker` 或 `podman` 起一个一次性容器 | `MYSQL_IMAGE=mysql:8.0` 可覆盖镜像 |
| 4 | 都不可用 | 所有集成测试标记为跳过 |

运行期间起的容器会在进程结束时删除，每个测试方法都从"不含任何表"的空库开始。你显式提供的 `MYSQL_DSN` 或 `MYSQL_HOST` 会被原样使用：配错了就是失败，而不是悄悄降级，因此配置错误的流水线不会意外通过。

CI 在每次推送时都会跑这两个套件，PHP 版本覆盖 8.1–8.4，其中集成套件跑在 MySQL service container 上。

## 许可证

MIT
