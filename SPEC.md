# migears/mitable Module Specification

Version: 2.0.0
Date: 2026-09-29

## 1. Positioning

`migears/mitable` is the execution layer for one-off migration scripts: a minimalist single-table helper wrapping one `PDO` connection around one table, offering DDL, CRUD, schema introspection and cursor-based iteration, with one class per SQL dialect (`MySQLTable` for MySQL/MariaDB, `SQLiteTable` for SQLite, both built on the shared `TableOperations` trait). It sits between a migration runner and raw PDO — it executes a single migration step, reaching for raw SQL through `getPdo()` only when a step outgrows it. It is not a migration framework and not a query builder.

## 2. Boundaries

### 2.1 In scope
- Cross-driver table abstraction: `MiTableInterface`, the dialect-agnostic `TableOperations` trait, and one class per dialect — `MySQLTable` (MySQL/MariaDB) and `SQLiteTable` (SQLite); PSR-4 root `MiGears\MiTable`.
- Single-table execution for migration scripts: DDL (create/drop/exists/rename/truncate, column and index changes), CRUD (`insert`, `bulkInsert`, `update`, `delete`, `find`, `where`, `count`), parameter-bound conditions, schema introspection, primary-key cursor iteration with resumable checkpoints, and an optional `withTransaction()` wrapper.
- Driver conformance as a tested property: one shared conformance trait asserts every dialect-shared behaviour on both SQLite (unit suite) and MySQL (integration suite); dialect tests cover only what is deliberately different.
- PHP 8.1+ with `ext-pdo` only — pure PDO, no query-builder dependency.

### 2.2 Out of scope (explicitly not done)
- The migration runner — registry, execution order, up/down, idempotency, automatic rollback and cross-table schema diffing stay outside; this package executes a single migration step.
- Query building and multi-table work — no nested conditions, groups or joins; query building is owned by `migears/sql`, and joins or multi-table writes are reached through `getPdo()`.
- Any model / Active Record layer, and any events, hooks or observers.
- Automatic transaction or rollback management beyond `withTransaction()` (no nesting, no retry); schema changes are not atomic — MySQL commits implicitly around DDL.

## 3. Public contract

Declared on `MiTableInterface`, which also extends `Iterator` (`current()`, `key()`, `next()`, `rewind()`, `valid()`) and carries `VERSION` (`2.0.0`) and `OPERATORS` (the accepted operator list).

| Group | Members | Notes |
|---|---|---|
| Meta | `getName(): string`, `getPdo(): PDO` | Table name; underlying connection |
| DDL — table | `create($columns, $engine='InnoDB', $charset='utf8mb4', $collate='utf8mb4_unicode_ci')`, `drop()`, `exists()`, `rename($newName): self`, `truncate()` | MySQL table options ignored elsewhere; `rename()` returns an instance bound to the new name |
| DDL — columns | `addColumn($name, $definition, ?$after=null)`, `dropColumn($name)`, `modifyColumn($name, $definition)`, `renameColumn($old, $new, $definition)` | `$after` MySQL-only; `modifyColumn()` throws on SQLite; `$definition` required on MySQL, ignored on SQLite |
| DDL — indexes/keys | `addIndex($name, $columns, $type='')`, `dropIndex($name)`, `addUniqueIndex($name, $columns)`, `addPrimaryKey($columns)`, `dropPrimaryKey()` | `$type` MySQL-only; `addPrimaryKey()`/`dropPrimaryKey()` throw on SQLite |
| Introspection | `showColumns(): array`, `showIndexes(): array` | Keyed by name; column fields `name,type,nullable,default,primary,unique`; index fields `name,columns,unique,primary,type` |
| CRUD | `insert($data): string`, `bulkInsert($rows): int`, `update($data, $where): int`, `delete($where): int`, `find($where): ?array`, `where($where=[], $orderBy='', $limit=null): array`, `count($where=[]): int` | All values parameter-bound; `$orderBy` is raw SQL; an empty `delete()` condition removes every row |
| Cursor | `withPageSize(int): self`, `withCursorKey(string): self`, `withCursorStart(mixed): self`, `cursor(): mixed` | Pages by primary key rather than `OFFSET`; falls back to offset paging when no single-column key exists |
| Transaction | `withTransaction(callable): mixed` | Commit on success, roll back and rethrow on `Throwable`; returns the callback's value |

A new dialect is one class that `use`s `TableOperations`, implements `MiTableInterface`, and supplies four abstract hooks:

| Hook | Purpose |
|---|---|
| `assertDialect(PDO): void` | Reject a connection this dialect cannot drive (called from the constructor) |
| `quoteIdentifier(string): string` | Quote one identifier; every statement the trait builds uses it |
| `detectPrimaryKey(): ?string` | Single-column cursor key, or `null` for keyless/composite tables |
| `fetchOffsetPage(string, int, int): array` | One offset page — the fallback when no cursor key exists |

## 4. Invariants and error behaviour

Always true across both dialects:
- The constructor rejects a connection whose driver does not match the class and one not in `PDO::ERRMODE_EXCEPTION`; both raise `InvalidArgumentException` before any SQL runs.
- Every value is bound; every identifier is quoted (embedded backticks doubled in both dialects).
- `rowCount()` is not a success signal — drivers differ on matched vs changed rows; verify with `find()` or `count()`.
- `where()`'s `$orderBy` is raw and never bound; `delete([])` deletes every row.
- `exists()` queries the driver catalog with no try/catch, so connection or permission failures surface rather than reading as "table missing"; a missing table makes introspection return `[]`.
- `withTransaction()` passes the callback's return value through, adds no nesting or retry, and only covers data changes.

| Trigger | Raised |
|---|---|
| `create([])` (no columns) | `InvalidArgumentException` |
| Empty condition list (`['col' => []]`) | `InvalidArgumentException` |
| Operator condition of wrong arity (`['=', 1, 2]`) | `InvalidArgumentException` |
| Symbol-only string in the implicit-IN position (`['==', 1]`) | `InvalidArgumentException`, hinting at valid operators |
| Non-scalar element in an IN list | `InvalidArgumentException` |
| `bulkInsert()` with ragged rows | `InvalidArgumentException` (row index + differing columns), before any SQL |
| `current()` while not on a row | `OutOfBoundsException` |
| `withCursorStart()` on a keyless or composite-key table | `InvalidArgumentException` when iteration begins |
| `modifyColumn()` / `addPrimaryKey()` / `dropPrimaryKey()` on SQLite | `RuntimeException` pointing at the table-rebuild route |
| A constraint the dialect must enforce (`UNIQUE`, `PRIMARY KEY`) | driver `PDOException` (e.g. duplicate key) |

## 5. Dependencies

### 5.1 Required
- Runtime: PHP `^8.1` and `ext-pdo`; one PDO driver per dialect — `ext-pdo_mysql` for MySQL/MariaDB, `ext-pdo_sqlite` for SQLite (both declared as suggestions). `SQLiteTable::dropColumn()` needs SQLite 3.35+.
- Autoload: PSR-4 `MiGears\MiTable\` => `src/`; tests under `MiGears\MiTable\Tests\` => `tests/`.
- Development only: `phpunit/phpunit ^10`, `phpstan/phpstan ^2.2`.

### 5.2 Forbidden by design
- No query-builder dependency — the package is pure PDO; query building belongs to `migears/sql`.
- No migration runner, version table or up/down commands, no cross-table schema diffing.
- No model / Active Record layer, no relationships or joins, no events, hooks or observers.
- Why: version management and orchestration need global state, and pulling them in would destroy the readability the class depends on.

## 6. Test plan

| Suite | Classes | Covers |
|---|---|---|
| Conformance (shared) | `tests/Conformance/TableConformanceTests`, run by `Unit/SQLiteConformanceTest` and `Integration/MySQLConformanceTest` | Behaviour every dialect must share, asserted identically on both |
| Dialect — SQLite | `tests/Unit/SQLiteTableTest` | SQLite-only behaviour |
| Dialect — MySQL | `tests/Integration/MySQLTableTest` | MySQL-only behaviour (skipped when no server) |

The shared trait must keep covering:
- DDL: create/exists, empty-column rejection, rename keeps rows, truncate empties and restarts the identity, addColumn, dropColumn, renameColumn, add/drop index, unique index enforces uniqueness.
- Introspection: column shape, empty result for a missing table, composite index column list.
- CRUD: insert/find/count, bulkInsert (empty input, ragged-row rejection, hyphenated/spaced column names, embedded backticks), update, delete, `where()` order/limit.
- Conditions: every accepted shape, malformed operator tuple, operator-like first value, symbol-operator hint, non-scalar list element.
- Iteration: order across pages, resume skipping earlier rows, `cursor()` reporting the handled row, `current()` before `rewind()`.
- Transactions: commit on success, rollback and rethrow on error.

Dialect tests hold what is deliberately different: SQLite's rowid-alias primary key, ignored `AFTER`, the three `RuntimeException` DDL verbs, `truncate()` resetting `AUTOINCREMENT`, foreign-driver and silent-connection rejection, idempotent index migration; MySQL's table options, `information_schema` existence, `SHOW COLUMNS`/`SHOW INDEX` metadata, `AFTER` positioning, `modifyColumn()`, `USING` types, add/drop primary key, changed-rows `rowCount()` semantics, non-atomic DDL inside a transaction, and offset fallback for composite/keyless tables. `tests/Unit/ReadmeConsistencyTest` guards the README's PHP range against the CI matrix. The integration suite resolves a server in order (`MYSQL_DSN` → `MYSQL_HOST` family → throwaway `docker`/`podman` container → skip) and starts each test from an empty schema; CI runs both suites on PHP 8.1–8.5.

---

# migears/mitable 模块规格说明

版本：2.0.0
日期：2026-09-29

## 1. 定位

`migears/mitable` 是一次性迁移脚本的执行层：把一条 `PDO` 连接套在单张表上的极简单表助手，提供 DDL、CRUD、结构反射与游标迭代，每个 SQL 方言一个类（MySQL/MariaDB 用 `MySQLTable`，SQLite 用 `SQLiteTable`，二者共用 `TableOperations` trait）。它介于迁移运行器与裸 PDO 之间 —— 只执行单个迁移步骤，只有当一个步骤超出它的能力时才通过 `getPdo()` 退回裸 SQL。它不是迁移框架，也不是查询构建器。

## 2. 边界

### 2.1 范围内
- 跨驱动的表抽象：`MiTableInterface`、与方言无关的 `TableOperations` trait，以及每个方言一个类 —— MySQL/MariaDB 的 `MySQLTable`、SQLite 的 `SQLiteTable`；PSR-4 根为 `MiGears\MiTable`。
- 服务于迁移脚本的单表操作：DDL（create/drop/exists/rename/truncate，列的增删改改名与索引变更）、CRUD（`insert`、`bulkInsert`、`update`、`delete`、`find`、`where`、`count`）、参数绑定条件、结构反射、主键游标迭代与可续跑断点，以及可选的 `withTransaction()` 包装。
- 把驱动一致性当作被测试的性质：同一个一致性 trait 在 SQLite（单元套件）与 MySQL（集成套件）上断言所有方言共享的行为，方言测试只覆盖刻意不同的部分。
- 仅要求 PHP 8.1+ 与 `ext-pdo` —— 纯 PDO，不依赖查询构建器。

### 2.2 范围外（刻意不做）
- 迁移运行器 —— 迁移记录表、执行顺序、up/down、幂等、自动回滚、跨表结构比对都留在包外；本包只执行单个迁移步骤。
- 查询构建与多表操作 —— 没有嵌套条件、分组和 JOIN；查询构建归 `migears/sql`，JOIN 或多表写入通过 `getPdo()` 直达。
- 任何模型 / Active Record 层，以及任何事件、钩子或观察者。
- `withTransaction()` 之外的自动事务与回滚管理（不提供嵌套、不提供重试）；结构变更不具备原子性 —— MySQL 在 DDL 前后会隐式提交。

## 3. 公开契约

以下方法声明在 `MiTableInterface` 上，除非另有说明，每个方言都实现。该接口同时继承 `Iterator`（`current()`、`key()`、`next()`、`rewind()`、`valid()`），并带有常量 `VERSION`（`2.0.0`）与 `OPERATORS`（可接受的操作符列表）。

| 分组 | 成员 | 说明 |
|---|---|---|
| 元信息 | `getName(): string`、`getPdo(): PDO` | 表名；底层连接 |
| DDL — 表 | `create($columns, $engine='InnoDB', $charset='utf8mb4', $collate='utf8mb4_unicode_ci')`、`drop()`、`exists()`、`rename($newName): self`、`truncate()` | MySQL 表选项在其他方言被忽略；`rename()` 返回绑定到新名字的实例 |
| DDL — 列 | `addColumn($name, $definition, ?$after=null)`、`dropColumn($name)`、`modifyColumn($name, $definition)`、`renameColumn($old, $new, $definition)` | `$after` 仅 MySQL；`modifyColumn()` 在 SQLite 抛异常；`$definition` 在 MySQL 必需、在 SQLite 忽略 |
| DDL — 索引/键 | `addIndex($name, $columns, $type='')`、`dropIndex($name)`、`addUniqueIndex($name, $columns)`、`addPrimaryKey($columns)`、`dropPrimaryKey()` | `$type` 仅 MySQL；`addPrimaryKey()`/`dropPrimaryKey()` 在 SQLite 抛异常 |
| 结构反射 | `showColumns(): array`、`showIndexes(): array` | 以名字为键；列字段 `name,type,nullable,default,primary,unique`；索引字段 `name,columns,unique,primary,type` |
| CRUD | `insert($data): string`、`bulkInsert($rows): int`、`update($data, $where): int`、`delete($where): int`、`find($where): ?array`、`where($where=[], $orderBy='', $limit=null): array`、`count($where=[]): int` | 所有值参数绑定；`$orderBy` 是裸 SQL；`delete()` 条件为空会删除全部行 |
| 游标 | `withPageSize(int): self`、`withCursorKey(string): self`、`withCursorStart(mixed): self`、`cursor(): mixed` | 按主键翻页而非 `OFFSET`；无单列主键时降级为偏移分页 |
| 事务 | `withTransaction(callable): mixed` | 成功即提交，遇 `Throwable` 回滚并重新抛出；透传回调返回值 |

新增一个方言，就是写一个 `use` `TableOperations`、实现 `MiTableInterface` 并补上四个抽象钩子的类：

| 钩子 | 用途 |
|---|---|
| `assertDialect(PDO): void` | 拒绝该方言无法驱动的连接（构造函数中调用） |
| `quoteIdentifier(string): string` | 引用单个标识符；trait 构建的每条语句都用到它 |
| `detectPrimaryKey(): ?string` | 单列游标键；无主键/复合主键返回 `null` |
| `fetchOffsetPage(string, int, int): array` | 获取一页偏移分页 —— 无游标键时的回退 |

## 4. 不变量与错误行为

两个方言下恒成立：
- 构造函数拒绝驱动与类不匹配的连接，也拒绝未处于 `PDO::ERRMODE_EXCEPTION` 的连接；两者都在发出任何 SQL 前抛出 `InvalidArgumentException`。
- 所有值都参数绑定；所有标识符都被引用（两个方言都把内嵌反引号翻倍转义）。
- `rowCount()` 不是成功判据 —— 各驱动对匹配行数/实际变更行数的返回不一致；请用 `find()` 或 `count()` 校验。
- `where()` 的 `$orderBy` 是裸 SQL、永不绑定；`delete([])` 会删除全部行。
- `exists()` 查询驱动数据字典且没有 try/catch，因此连接或权限故障会浮出真实错误，而不是被读成"表不存在"；表不存在时反射方法返回 `[]`。
- `withTransaction()` 透传回调返回值，不提供嵌套或重试，且只覆盖数据变更。

| 触发条件 | 抛出 |
|---|---|
| `create([])`（无列） | `InvalidArgumentException` |
| 空条件列表（`['col' => []]`） | `InvalidArgumentException` |
| 元素个数错误的操作符条件（`['=', 1, 2]`） | `InvalidArgumentException` |
| 隐式 IN 位置上的纯符号字符串（`['==', 1]`） | `InvalidArgumentException`，并给出可用操作符提示 |
| IN 列表中出现非标量元素 | `InvalidArgumentException` |
| `bulkInsert()` 行参差 | `InvalidArgumentException`（行号 + 差异列），在任何 SQL 之前 |
| 迭代器未落在某一行时调用 `current()` | `OutOfBoundsException` |
| 在无主键或复合主键表上调用 `withCursorStart()` | 开始遍历时 `InvalidArgumentException` |
| 在 SQLite 上调用 `modifyColumn()` / `addPrimaryKey()` / `dropPrimaryKey()` | `RuntimeException`，指向重建表路径 |
| 方言必须强制执行的约束（`UNIQUE`、`PRIMARY KEY`） | 驱动 `PDOException`（如重复键） |

## 5. 依赖

### 5.1 必需
- 运行时：PHP `^8.1` 与 `ext-pdo`；每个方言一个 PDO 驱动 —— MySQL/MariaDB 需要 `ext-pdo_mysql`，SQLite 需要 `ext-pdo_sqlite`（二者声明为 suggest）。`SQLiteTable::dropColumn()` 需要 SQLite 3.35+。
- 自动加载：PSR-4 `MiGears\MiTable\` => `src/`；测试位于 `MiGears\MiTable\Tests\` => `tests/`。
- 仅开发期：`phpunit/phpunit ^10`、`phpstan/phpstan ^2.2`。

### 5.2 设计上禁止
- 不依赖查询构建器 —— 本包是纯 PDO；查询构建归 `migears/sql`。
- 没有迁移运行器、版本表或 up/down 命令，没有跨表结构比对。
- 没有模型 / Active Record 层，没有关联或 JOIN，没有事件、钩子或观察者。
- 原因：版本管理与编排需要全局状态，一旦纳入就会毁掉这个类赖以立足的可读性。

## 6. 测试计划

| 套件 | 类 | 覆盖 |
|---|---|---|
| 一致性（共享） | `tests/Conformance/TableConformanceTests`，由 `Unit/SQLiteConformanceTest` 与 `Integration/MySQLConformanceTest` 分别运行 | 所有方言必须一致的行为，在两个方言上做相同断言 |
| 方言 — SQLite | `tests/Unit/SQLiteTableTest` | SQLite 专有行为 |
| 方言 — MySQL | `tests/Integration/MySQLTableTest` | MySQL 专有行为（无服务时跳过） |

共享 trait 必须持续覆盖：
- DDL：create/exists、空列拒绝、rename 保留数据、truncate 清空并重置自增、addColumn、dropColumn、renameColumn、增删索引、唯一索引强制唯一。
- 结构反射：列元数据形态、表不存在时返回空、复合索引的列清单。
- CRUD：insert/find/count、bulkInsert（空输入、参差行拒绝、带连字符/空格的列名、内嵌反引号）、update、delete、`where()` 排序与限行。
- 条件：所有被接受的形态、元素个数错误的操作符元组、首元素像操作符的取值、符号操作符提示、非标量列表元素。
- 迭代：跨页顺序、续跑跳过此前行、`cursor()` 报告正在处理的行、`rewind()` 之前调用 `current()`。
- 事务：成功提交、出错回滚并重新抛出。

方言测试承载刻意不同的部分：SQLite 的 rowid 别名主键、被忽略的 `AFTER`、三个抛 `RuntimeException` 的 DDL 动词、`truncate()` 重置 `AUTOINCREMENT`、异种驱动与静默连接的拒绝、幂等的索引迁移；MySQL 的表选项、`information_schema` 存在检查、`SHOW COLUMNS`/`SHOW INDEX` 元数据、`AFTER` 定位、`modifyColumn()`、`USING` 类型、增删主键、`rowCount()` 返回变更行数的语义、事务内 DDL 不原子、以及复合/无主键表回退偏移分页。`tests/Unit/ReadmeConsistencyTest` 用 CI 矩阵守护 README 的 PHP 版本区间。集成套件按顺序解析服务（`MYSQL_DSN` → `MYSQL_HOST` 系列 → 一次性 `docker`/`podman` 容器 → 跳过），每个测试都从空结构开始；CI 在 PHP 8.1–8.5 上运行两个套件。
