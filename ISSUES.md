# migears-mitable — Known Issues

> Summary of this module's issues. The items themselves are in [`issues/`](issues/README.md), one file
> per item: a front-matter header and a thread. This file is generated from them and can be rewritten at
> any time; edit an item, never this file.
>
> From the miGears Full-Module Code Review Report (5th round, 2026-09-28).

| | |
|---|---|
| Status | **Best state** |
| Size | src 853 lines (net) · 153 tests (79 skipped) · 4 src files |

Legend — **P0** functional or security · **P1** documentation that fails when copied · **P2** robustness · **P3** metadata and docs

## At a glance

| | |
|---|---|
| Unsettled | P0 0 · P1 0 · P2 0 · P3 3 · other 2 |
| Settled | 9 of 14 |
| Waiting on the owner | `P3-4`, `P3-5` |
| Waiting on the coordinator | _nothing_ |
| Waiting on the reviewer | _nothing_ |
| Deferred, owing nobody | `P3-1`, `NEW-4`, `NEW-5` |

| id | level | status | title |
|---|---|---|---|
| [`P1-1`](issues/P1-1.md) | P1 | **verified** | The README still advertises 'MySQL/MariaDB and SQLite, with a graceful … |
| [`P2-1`](issues/P2-1.md) | P2 | **verified** | `quoteIdentifier()` wraps names in backticks without escaping embedded … |
| [`P2-2`](issues/P2-2.md) | P2 | **verified** | `insert()` and `bulkInsert()` build placeholders from the raw column … |
| [`P3-1`](issues/P3-1.md) | P3 | **deferred** | Unusually among its siblings, this module's CI runs only the two test … |
| [`P3-2`](issues/P3-2.md) | P3 | **verified** | The README says 'roughly 760 lines of effective code, most of it the … |
| [`P3-3`](issues/P3-3.md) | P3 | **verified** | An operator name outside the known set is treated as a value list: … |
| [`P3-4`](issues/P3-4.md) | P3 | **open** | update() with empty $where updates all rows, consistent with delete() … |
| [`P3-5`](issues/P3-5.md) | P3 | **open** | README CI section claims 'PHP 8.1–8.4' but G5 added 8.5 to the matrix; … |
| [`G1`](issues/G1.md) | - | **verified** | CI file and workflow name: this module uses `.github/workflows/ci.yml` … |
| [`G2`](issues/G2.md) | - | **verified** | Strict flags: `phpunit.xml.dist` currently sets `failOnWarning`, … |
| [`G5`](issues/G5.md) | - | **verified** | PHP support matrix: `.github/workflows/ci.yml:13` reads `php: ['8.1', … |
| [`NEW-3`](issues/NEW-3.md) | - | **verified** | `new-evidence`. `migears-engineering-gates.md` lists the PHP matrix … |
| [`NEW-4`](issues/NEW-4.md) | - | **deferred** | `new-evidence`. This is the only module of 27 still caching `vendor/` … |
| [`NEW-5`](issues/NEW-5.md) | - | **deferred** | `new-evidence`. Not a finding. Only 5 of 27 modules use … |

## Unclosed

What is left to do here: every item whose `status` is not `verified` or `closed`,
highest severity first. `waiting on` is the party who acts next, read from that status.

| | |
|---|---|
| Unclosed | **5** of 14 |
| By status | `open` 2 · `deferred` 3 |
| Waiting on | owner 2 · - 3 |

| level | item | status | waiting on | title |
|---|---|---|---|---|
| **P3** | [`P3-1`](issues/P3-1.md) | `deferred` | - | Unusually among its siblings, this module's CI runs only the two test … |
| **P3** | [`P3-4`](issues/P3-4.md) | `open` | owner | update() with empty $where updates all rows, consistent with delete() … |
| **P3** | [`P3-5`](issues/P3-5.md) | `open` | owner | README CI section claims 'PHP 8.1–8.4' but G5 added 8.5 to the matrix; … |
| **-** | [`NEW-4`](issues/NEW-4.md) | `deferred` | - | `new-evidence`. This is the only module of 27 still caching `vendor/` … |
| **-** | [`NEW-5`](issues/NEW-5.md) | `deferred` | - | `new-evidence`. Not a finding. Only 5 of 27 modules use … |

## Verdict

A solid, well-tested database abstraction with dialect-specific implementations (MySQL/SQLite) and good security practices; only CI-philosophy and documentation-drift items remain.

## Fixed since the last round

All prior P1/P2/P3 items confirmed fixed: P1-1 README "graceful generic fallback" claim removed; P2-1 backtick escaping added in both dialects; P2-2 synthetic placeholders for insert/bulkInsert; P3-2 line count updated; G2/G5 gates complete.

## Test gaps

No test for not like operator; no test for between / not between operators; no test for rename() method; no test for addColumn() / dropColumn() error paths; no test for withPageSize() edge cases (0, negative, very large).

## Verification protocol

- `./vendor/bin/phpunit` · `composer analyse` · `composer validate` · `composer test:unit` · `composer test:integration`
- Warning/notice/deprecation/risky flags in `phpunit.xml.dist`: all four on
- A PHP warning counts as a test failure only where those flags are on; otherwise run `./vendor/bin/phpunit --fail-on-warning` explicitly.


---

# migears-mitable — 已知问题

> 本模块问题的概览。条目本体在 [`issues/`](issues/README.md)，一条目一文件：前置字段加讨论串。
> 本文件由条目生成，随时可以整段重写；请改条目，不要改本文件。
>
> 出自 miGears 全模块代码评审报告（5th round，2026-09-28）。

| | |
|---|---|
| 状态 | **状态最好** |
| 体量 | src 853 行（净）· 153 个用例（79 跳过）· 4 个源文件 |

级别说明 — **P0** 功能性或安全级 · **P1** 文档照抄即错 · **P2** 健壮性 · **P3** 元数据与文档

## 状态一览

| | |
|---|---|
| 未了结 | P0 0 · P1 0 · P2 0 · P3 3 · 其他 2 |
| 已了结 | 9 / 14 |
| 等模块主 | `P3-4`, `P3-5` |
| 等协调人 | _无_ |
| 等评审方 | _无_ |
| 已暂缓，不欠谁 | `P3-1`, `NEW-4`, `NEW-5` |

| id | 级别 | 状态 | 标题 |
|---|---|---|---|
| [`P1-1`](issues/P1-1.md) | P1 | **verified** | README 仍宣传「MySQL/MariaDB 与 SQLite，其他驱动优雅降级」，但仓库已无任何通用或回退类：new … |
| [`P2-1`](issues/P2-1.md) | P2 | **verified** | quoteIdentifier() 用反引号包裹名字但不转义内嵌反引号，因此表名含反引号时语句被截断（PDOException: near … |
| [`P2-2`](issues/P2-2.md) | P2 | **verified** | insert() 与 bulkInsert() 用原始列名拼占位符（":{$c}"、":{$c}_{$i}"），因此合法的 user-id … |
| [`P3-1`](issues/P3-1.md) | P3 | **deferred** | 在兄弟模块中少见：本模块 CI 只跑两个测试套件，从不跑 composer analyse，因此 L6 … |
| [`P3-2`](issues/P3-2.md) | P3 | **verified** | README 称「约 760 行有效代码，其中大部分是共享 trait」，而实测净代码 804 行（trait 389 行，约 … |
| [`P3-3`](issues/P3-3.md) | P3 | **verified** | 已知集合外的操作符名会被当作值列表：where(["id" => ["==", 1]]) 编译成 id IN ('==', 1) … |
| [`P3-4`](issues/P3-4.md) | P3 | **open** | update() 空 $where 会更新所有行，与 delete() 行为一致，但 README 仅对 delete() 明确警告空 … |
| [`P3-5`](issues/P3-5.md) | P3 | **open** | README CI 部分声称「PHP 8.1–8.4」，但 G5 已将 8.5 加入矩阵；英文第 421 行和中文第 845 行已过时。 |
| [`G1`](issues/G1.md) | - | **verified** | CI 文件名与工作流名：本模块使用 `.github/workflows/ci.yml`、`name: CI`。工作区标准是 … |
| [`G2`](issues/G2.md) | - | **verified** | 严格开关：`phpunit.xml.dist` 目前已开启 … |
| [`G5`](issues/G5.md) | - | **verified** | PHP 支持矩阵：`.github/workflows/ci.yml:13` 是 `php: ['8.1', '8.2', '8.3', … |
| [`NEW-3`](issues/NEW-3.md) | - | **verified** | `new-evidence`。`migears-engineering-gates.md` 把 PHP 矩阵列在「本已统一，无需动作」里，写作 … |
| [`NEW-4`](issues/NEW-4.md) | - | **deferred** | `new-evidence`。在 `migears-data-structure` 于 2026-09-28 去掉自己的缓存后，本模块是 27 … |
| [`NEW-5`](issues/NEW-5.md) | - | **deferred** | `new-evidence`。不是问题。27 个模块中只有 5 个使用了 … |

## 未关闭

本模块还剩什么要做：所有 `status` 不是 `verified` 或 `closed` 的条目，按严重度从高到低。
`waiting on` 是下一步该动手的一方，由其状态读出。

| | |
|---|---|
| 未关闭 | **5** / 14 |
| 按状态 | `open` 2 · `deferred` 3 |
| 等在谁 | 模块主 2 · - 3 |

| 级别 | 条目 | 状态 | 等在谁 | 标题 |
|---|---|---|---|---|
| **P3** | [`P3-1`](issues/P3-1.md) | `deferred` | - | 在兄弟模块中少见：本模块 CI 只跑两个测试套件，从不跑 composer analyse，因此 L6 … |
| **P3** | [`P3-4`](issues/P3-4.md) | `open` | 模块主 | update() 空 $where 会更新所有行，与 delete() 行为一致，但 README 仅对 delete() 明确警告空 … |
| **P3** | [`P3-5`](issues/P3-5.md) | `open` | 模块主 | README CI 部分声称「PHP 8.1–8.4」，但 G5 已将 8.5 加入矩阵；英文第 421 行和中文第 845 行已过时。 |
| **-** | [`NEW-4`](issues/NEW-4.md) | `deferred` | - | `new-evidence`。在 `migears-data-structure` 于 2026-09-28 去掉自己的缓存后，本模块是 27 … |
| **-** | [`NEW-5`](issues/NEW-5.md) | `deferred` | - | `new-evidence`。不是问题。27 个模块中只有 5 个使用了 … |

## 结论

一个扎实、测试充分的数据库抽象层，按方言拆分实现（MySQL/SQLite），安全实践良好；仅剩 CI 理念与文档漂移类问题。

## 本轮已修复确认

All prior P1/P2/P3 items confirmed fixed: P1-1 README "graceful generic fallback" claim removed; P2-1 backtick escaping added in both dialects; P2-2 synthetic placeholders for insert/bulkInsert; P3-2 line count updated; G2/G5 gates complete.

## 测试盲区

缺少 not like 操作符测试；缺少 between / not between 操作符测试；缺少 rename() 方法测试；缺少 addColumn() / dropColumn() 错误路径测试；缺少 withPageSize() 边界值测试。

## 验证方式

- `./vendor/bin/phpunit` · `composer analyse` · `composer validate` · `composer test:unit` · `composer test:integration`
- `phpunit.xml.dist` 中的 warning/notice/deprecation/risky 开关：四个全开
- 只有在上述开关打开时 PHP 警告才会导致套件失败；否则请显式加 `--fail-on-warning`。
