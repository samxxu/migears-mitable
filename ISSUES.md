# migears-mitable — Known Issues / 已知问题

> Summary of this module's issues. The items themselves are in [`issues/`](issues/README.md), one file
> per item: a front-matter header and a thread. This file is generated from them and can be rewritten at
> any time; edit an item, never this file.
>
> 本模块问题的概览。条目本体在 [`issues/`](issues/README.md)，一条目一文件：前置字段加讨论串。
> 本文件由条目生成，随时可以整段重写；请改条目，不要改本文件。
>
> From the miGears Full-Module Code Review Report (4th round, 2026-09-27).

| | |
|---|---|
| Status / 状态 | **P1 open / P1 待修** |
| Size / 体量 | src 1,575 lines (804 net) · 174 tests (69 integration skipped) · 4 src files |

Legend / 图例 — **P0** functional or security · **P1** documentation that fails when copied · **P2** robustness · **P3** metadata and docs
级别说明 — **P0** 功能性或安全级 · **P1** 文档照抄即错 · **P2** 健壮性 · **P3** 元数据与文档

## At a glance / 状态一览

| | |
|---|---|
| Items / 条目 | P0 0 · P1 1 · P2 2 · P3 3 · other 6 |
| Answered / 已回复 | 12 of 12 |
| Waiting / 等待回复 | _nothing / 无_ |

| id | level | status | title |
|---|---|---|---|
| [`P1-1`](issues/P1-1.md) | P1 | **fixed** | The README still advertises 'MySQL/MariaDB and SQLite, with a graceful … |
| [`P2-1`](issues/P2-1.md) | P2 | **fixed** | `quoteIdentifier()` wraps names in backticks without escaping embedded … |
| [`P2-2`](issues/P2-2.md) | P2 | **fixed** | `insert()` and `bulkInsert()` build placeholders from the raw column … |
| [`P3-1`](issues/P3-1.md) | P3 | **deferred** | Unusually among its siblings, this module's CI runs only the two test … |
| [`P3-2`](issues/P3-2.md) | P3 | **fixed** | The README says 'roughly 760 lines of effective code, most of it the … |
| [`P3-3`](issues/P3-3.md) | P3 | **fixed** | An operator name outside the known set is treated as a value list: … |
| [`G1`](issues/G1.md) | - | **fixed** | CI file and workflow name: this module uses `.github/workflows/ci.yml` … |
| [`G2`](issues/G2.md) | - | **fixed** | Strict flags: `phpunit.xml.dist` currently sets `failOnWarning`, … |
| [`G5`](issues/G5.md) | - | **fixed** | PHP support matrix: `.github/workflows/ci.yml:13` reads `php: ['8.1', … |
| [`NEW-3`](issues/NEW-3.md) | - | **new-evidence** | `new-evidence`. `migears-engineering-gates.md` lists the PHP matrix … |
| [`NEW-4`](issues/NEW-4.md) | - | **new-evidence** | `new-evidence`. This is the only module of 27 still caching `vendor/` … |
| [`NEW-5`](issues/NEW-5.md) | - | **new-evidence** | `new-evidence`. Not a finding. Only 5 of 27 modules use … |

## Verdict / 结论

The rewrite is the biggest quality jump of the round: dialect split, a conformance test trait, and all four P0s verifiably fixed. Two things hold it back — the README still promises a "graceful generic fallback" that no longer exists, and the placeholder scheme for insert/bulkInsert diverges from the safe one used by update.

这次重写是本轮质量提升最大的一处：方言拆分、一致性测试 trait，四个 P0 全部实证修复。两点拖住它：README 仍承诺一个已经不存在的「优雅降级」，以及 insert/bulkInsert 的占位符方案与 update 用的安全方案不一致。

## Fixed since the last round / 本轮已修复确认

这个模块在上一轮之后**整体重写**：MiTableInterface + TableOperations trait + MySQLTable/SQLiteTable 两个方言类，并新增跨驱动一致性测试 trait（同一套 31 例在两种驱动上各跑一次）。上一轮 4 个 P0 全部实证修复：SQLite 的 rename/truncate/renameColumn 有方言实现，不支持的方法在构造或调用期明确抛错；bulkInsert 参差行、畸形条件元组、current() 越界均改为显式拒绝；ERRMODE 依赖改为构造期显式校验。旧 ISSUES.md 引用的 src/MiTable.php 已不存在。 

## Test gaps / 测试盲区

The conformance trait closed the old "never verified on two drivers" gap for rename/truncate/dropColumn/ragged insert/bad conditions/current()/transactions. Still missing: identifiers containing hyphens, spaces or backticks (for insert/bulkInsert/DDL), an unrecognised-operator case, `addIndex` type injection, and any MySQL-only branch that the local suite cannot reach (69 skips).

一致性 trait 已闭合旧的「从未在两种驱动上同时验证」缺口（rename/truncate/dropColumn/参差插入/坏条件/current()/事务）。仍缺：含连字符、空格或反引号的标识符（insert/bulkInsert/DDL）、未识别操作符用例、addIndex 的 type 注入、以及本机到不了的 MySQL 专属分支（69 个跳过）。

## Verification protocol / 验证方式

- `./vendor/bin/phpunit` · `composer analyse` · `composer validate` · `composer test:unit` · `composer test:integration`
- Warning/notice/deprecation/risky flags in `phpunit.xml.dist`: all four on
- A PHP warning counts as a test failure only where those flags are on; otherwise run `./vendor/bin/phpunit --fail-on-warning` explicitly.
- 只有在上述开关打开时 PHP 警告才会导致套件失败；否则请显式加 `--fail-on-warning`。
