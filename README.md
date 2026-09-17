# migears/mitable

![Version](https://img.shields.io/badge/version-2.0.0-blue)

Minimalist single-table database management — DDL, CRUD, and paginated iteration in one class.

MiTable wraps a PDO connection around a single table, providing intuitive methods for schema management, data operations, and efficient iteration over large datasets. No query builder dependency — just pure PDO.

## Features

- **DDL operations** — create, drop, exists, rename, truncate
- **Column management** — add, drop, modify, rename columns
- **Index management** — add/drop regular, unique, and primary keys
- **CRUD operations** — insert, bulkInsert, update, delete, find, where, count
- **Paginated iteration** — `foreach` over millions of rows without memory bloat
- **Cross-driver** — works with MySQL and SQLite (basic operations)
- **Single class** — ~300 lines, easy to read and understand

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

### Paginated Iteration

Iterate over all rows in a table without loading everything into memory:

```php
$users->withPageSize(100); // pages of 100 rows

foreach ($users as $row) {
    // Process each row...
    // Pages are loaded automatically in the background
    processUser($row);
}
```

### DDL Operations

```php
// Check if table exists
if (!$users->exists()) {
    $users->create([...]);
}

// Add a column
$users->addColumn('bio', 'TEXT', 'after' => 'email');

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
| `foreach ($table as $row)` | Iterate over all rows |

## Design Philosophy

miGears MiTable follows the miGears philosophy: **minimal, readable, and useful**.

- **One class** — no abstracts, no traits, no interfaces
- **PDO only** — no query builder dependency
- **Safe by default** — uses prepared statements for all data operations
- **Small enough to read** — ~300 lines of code

**What we don't do**:
- No query builder (use `migears/sql` for that)
- No migrations system
- No relationships or joins
- No model / active record pattern
- No events, hooks, or observers

## Notes on SQLite Support

Most DDL operations work on both MySQL and SQLite. Some MySQL-specific features (ENGINE, CHARSET, fulltext indexes, etc.) are silently ignored on SQLite.

For production MySQL deployments, `ext-pdo_mysql` is required.

## License

MIT

---

# migears/mitable

![Version](https://img.shields.io/badge/version-2.0.0-blue)

极简单表数据库管理 —— 一个类搞定 DDL、CRUD 和分页遍历。

MiTable 将 PDO 连接封装在单个表周围，提供直观的表结构管理、数据操作方法，以及高效遍历大数据集的能力。没有查询构建器依赖 —— 只有纯 PDO。

## 特性

- **DDL 操作** — create、drop、exists、rename、truncate
- **列管理** — 添加、删除、修改、重命名列
- **索引管理** — 添加/删除普通索引、唯一索引和主键
- **CRUD 操作** — insert、bulkInsert、update、delete、find、where、count
- **分页迭代** — `foreach` 遍历百万行数据不爆内存
- **跨驱动** — 支持 MySQL 和 SQLite（基础操作）
- **单类实现** — 约 300 行，易读易懂

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

### 分页迭代

遍历表中所有行，无需一次性加载全部数据到内存：

```php
$users->withPageSize(100); // 每页 100 行

foreach ($users as $row) {
    // 处理每一行...
    // 页面在后台自动加载
    processUser($row);
}
```

### DDL 操作

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
| `foreach ($table as $row)` | 遍历所有行 |

## 设计哲学

miGears MiTable 遵循 miGears 设计哲学：**极简、可读、实用**。

- **一个类** — 没有抽象类、没有 trait、没有接口
- **仅依赖 PDO** — 不依赖查询构建器
- **默认安全** — 所有数据操作都使用预处理语句
- **小到可以读完** — 约 300 行代码

**我们不做的事**：
- 没有查询构建器（用 `migears/sql`）
- 没有迁移系统
- 没有关联或 JOIN
- 没有模型 / Active Record 模式
- 没有事件、钩子或观察者

## SQLite 支持说明

大多数 DDL 操作在 MySQL 和 SQLite 上都能工作。一些 MySQL 特定的功能（ENGINE、CHARSET、全文索引等）在 SQLite 上会被静默忽略。

生产环境 MySQL 部署需要 `ext-pdo_mysql`。

## 许可证

MIT
