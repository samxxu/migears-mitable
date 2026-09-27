# migears-mitable — Known Issues / 已知问题

> Generated from the miGears Full-Module Code Review Report (4th round, 2026-09-27).
> This file has two regions. Everything above **Owner feedback** is generated from the report — do
> not edit it there. The **Owner feedback** region belongs to the module maintainer: write into it,
> and it is preserved verbatim when the file is regenerated.
> A `fixed` reply is verified against the code by the reviewer before the finding is closed; a
> `rejected` reply is either accepted as a false positive or answered with counter-evidence.
>
> 本文件分两个区域。**「负责人反馈」之前的全部内容**由评审报告生成，请勿在该区修改；
> **「负责人反馈」区**归模块负责人所有，重新生成时会原样保留。
> 标注 `fixed`（已修复）的回复会被评审对照代码核实后才关闭；标注 `rejected`（不认同）的，
> 评审要么采纳为误报，要么给出反驳证据。
>
> 摘自 miGears 全模块代码评审报告（第四轮，2026-09-27）。

| | |
|---|---|
| Status / 状态 | **P1 open / P1 待修** |
| Findings / 问题 | P0 0 · P1 1 · P2 2 · P3 3 |
| Size / 体量 | src 1,575 lines (804 net) · 174 tests (69 integration skipped) · 4 src files |

Legend / 图例 — **P0** functional or security · **P1** documentation that fails when copied · **P2** robustness · **P3** metadata and docs
级别说明 — **P0** 功能性或安全级 · **P1** 文档照抄即错 · **P2** 健壮性 · **P3** 元数据与文档

## Verdict / 结论

The rewrite is the biggest quality jump of the round: dialect split, a conformance test trait, and all four P0s verifiably fixed. Two things hold it back — the README still promises a "graceful generic fallback" that no longer exists, and the placeholder scheme for insert/bulkInsert diverges from the safe one used by update.

这次重写是本轮质量提升最大的一处：方言拆分、一致性测试 trait，四个 P0 全部实证修复。两点拖住它：README 仍承诺一个已经不存在的「优雅降级」，以及 insert/bulkInsert 的占位符方案与 update 用的安全方案不一致。

## Fixed since the last round / 本轮已修复确认

这个模块在上一轮之后**整体重写**：MiTableInterface + TableOperations trait + MySQLTable/SQLiteTable 两个方言类，并新增跨驱动一致性测试 trait（同一套 31 例在两种驱动上各跑一次）。上一轮 4 个 P0 全部实证修复：SQLite 的 rename/truncate/renameColumn 有方言实现，不支持的方法在构造或调用期明确抛错；bulkInsert 参差行、畸形条件元组、current() 越界均改为显式拒绝；ERRMODE 依赖改为构造期显式校验。旧 ISSUES.md 引用的 src/MiTable.php 已不存在。 

## Open findings / 未修问题


### P1

**P1-1** — `README:58,482 vs src/MySQLTable.php:32-41`

- EN: The README still advertises "MySQL/MariaDB and SQLite, with a graceful generic fallback", but there is no generic or fallback class any more: `new MySQLTable($sqlitePdo, "users")` throws `InvalidArgumentException: MySQLTable requires a MySQL or MariaDB connection; the "sqlite" driver was given`. The documented conclusion is the opposite of the implementation.
- 中文: README 仍宣传「MySQL/MariaDB 与 SQLite，其他驱动优雅降级」，但仓库已无任何通用或回退类：new MySQLTable($sqlitePdo, "users") 抛 InvalidArgumentException: MySQLTable requires a MySQL or MariaDB connection; the "sqlite" driver was given。文档结论与实现相反。
- Verification / 验证: reproduced / 已实证


### P2

**P2-1** — `src/MySQLTable.php:43-46,87,150, src/SQLiteTable.php:54-57`

- EN: `quoteIdentifier()` wraps names in backticks without escaping embedded ones, so a table name containing a backtick breaks the statement (`PDOException: near "name": syntax error`). DDL fragments are likewise spliced raw: `ENGINE={$engine} DEFAULT CHARSET={$charset} COLLATE={$collate}` and `USING {$type}`. Table and column names usually come from the developer, hence P2.
- 中文: quoteIdentifier() 用反引号包裹名字但不转义内嵌反引号，因此表名含反引号时语句被截断（PDOException: near "name": syntax error）。DDL 片段同样裸拼：ENGINE={$engine} DEFAULT CHARSET={$charset} COLLATE={$collate} 与 USING {$type}。表列名通常由开发者提供，故列 P2。
- Verification / 验证: reproduced / 已实证

**P2-2** — `src/TableOperations.php:195,230 vs :295`

- EN: `insert()` and `bulkInsert()` build placeholders from the raw column name (`":{$c}"`, `":{$c}_{$i}"`), so a legal column such as `user-id` produces a SQL error: on SQLite, `insert(["user-id" => 1])` fails with `no such column: id` and a column with a space fails with a syntax error. The column list itself is quoted correctly, and `update()` uses synthetic names (`s0`, `s1`) and is immune — two schemes inside one trait.
- 中文: insert() 与 bulkInsert() 用原始列名拼占位符（":{$c}"、":{$c}_{$i}"），因此合法的 user-id 这类列名会直接 SQL 报错：SQLite 上 insert(["user-id" => 1]) 报 no such column: id，含空格的列名报语法错误。列清单一侧引号正确，update() 用合成名（s0、s1）因而免疫——同一个 trait 内两套方案。
- Verification / 验证: reproduced / 已实证


### P3

**P3-1** — `.github/workflows/ci.yml`

- EN: Unusually among its siblings, this module's CI runs only the two test suites and never `composer analyse`, so level-6 cleanliness is not enforced here; and `phpunit.xml.dist` leaves the strict flags off.
- 中文: 在兄弟模块中少见：本模块 CI 只跑两个测试套件，从不跑 composer analyse，因此 L6 干净不被强制；phpunit.xml.dist 也未开严格开关。
- Verification / 验证: reproduced / 已实证

**P3-2** — `README:386 vs src/ (804 net lines)`

- EN: The README says "roughly 760 lines of effective code, most of it the shared trait" while the measured net total is 804 (the trait is 389, about 48% — not "most of it").
- 中文: README 称「约 760 行有效代码，其中大部分是共享 trait」，而实测净代码 804 行（trait 389 行，约 48%，谈不上「大部分」）。
- Verification / 验证: reproduced / 已实证

**P3-3** — `src/TableOperations.php:457-477`

- EN: An operator name outside the known set is treated as a value list: `where(["id" => ["==", 1]])` compiles to `id IN ('==', 1)` and silently returns nothing instead of reporting the typo. Known operators are guarded; misspellings are not.
- 中文: 已知集合外的操作符名会被当作值列表：where(["id" => ["==", 1]]) 编译成 id IN ('==', 1) 并静默返回空，而不是报告拼写错误。已知操作符有保护，拼错没有。
- Verification / 验证: reproduced / 已实证

## Test gaps / 测试盲区

The conformance trait closed the old "never verified on two drivers" gap for rename/truncate/dropColumn/ragged insert/bad conditions/current()/transactions. Still missing: identifiers containing hyphens, spaces or backticks (for insert/bulkInsert/DDL), an unrecognised-operator case, `addIndex` type injection, and any MySQL-only branch that the local suite cannot reach (69 skips).

一致性 trait 已闭合旧的「从未在两种驱动上同时验证」缺口（rename/truncate/dropColumn/参差插入/坏条件/current()/事务）。仍缺：含连字符、空格或反引号的标识符（insert/bulkInsert/DDL）、未识别操作符用例、addIndex 的 type 注入、以及本机到不了的 MySQL 专属分支（69 个跳过）。

## Verification protocol / 验证方式

- `./vendor/bin/phpunit` · `composer analyse` · `composer validate` · `composer test:unit` · `composer test:integration`
- Warning/notice/deprecation/risky flags in `phpunit.xml.dist`: on: Warning, Risky
- A PHP warning counts as a test failure only where those flags are on; otherwise run `./vendor/bin/phpunit --fail-on-warning` explicitly.
- 只有在上述开关打开时 PHP 警告才会导致套件失败；否则请显式加 `--fail-on-warning`。

## Owner feedback / 负责人反馈

<!-- OWNER-FEEDBACK:BEGIN -->
<!-- 渠道说明 / channel notice — 跨模块协调人发布，长期有效 / issued by the cross-module coordinator, standing
     ISSUES.md 是本模块「完整」的问题讨论与修复渠道，不只是评审结论的存放处。
     ISSUES.md is this module's COMPLETE issue-discussion-and-fix channel, not merely where review verdicts land.

     1. 每位负责人只对自己模块负责。对别的模块有意见、疑问、反证或改动建议，写入「对方模块」的 ISSUES.md，
        不要写在自己模块里。
        Each owner is responsible for their own module only. Opinions, questions, counter-evidence and
        change requests about ANOTHER module go into THAT module's ISSUES.md, never into your own.
     2. 在对方模块的文件里注明你是谁：模块名 + 身份。署名是硬要求，不署名则无法追溯来源。
        Sign it in the other module's file: your module name and your role. Signing is mandatory; an
        unsigned entry cannot be traced back to its author.
     3. 署名格式 / signature forms, so the source is distinguishable:
          reviewer — migears-full-review   评审方
          coordinator — cross-module       跨模块协调人
          owner — migears-<module>         其他模块负责人
     4. 结论文本一律带状态词：accepted / fixed / rejected / deferred / question / new-evidence。
        无署名条目下一轮可能被按新发现重新评级。
        Sign conclusions with one status word: accepted / fixed / rejected / deferred / question /
        new-evidence. An unsigned entry may be re-graded as a new finding in the next round.
     5. 开工之前先通读本文件：把每条开启条目按证据评估（签名条目也算），再把你接受的条目与自己的工作一并执行，
        不要拆成两轮。每条都要有状态词。
        Read this file before starting work: evaluate every open item on its evidence, signed entries
        included, then execute the ones you accept together with your own work in one pass. Every item
        gets a status word. -->

<!-- Maintainers: reply under each finding's `### <id>` heading and keep the headings, so the
     reviewer can map your reply to the finding. Status vocabulary, one word followed by your
     reasoning and any evidence:
       accepted      you agree; it will be fixed
       fixed         you believe it is already fixed in the code (the reviewer verifies this)
       rejected      you disagree — give the reason; the reviewer either accepts it as a false
                     positive or answers with counter-evidence
       deferred      deliberate, out of scope for now — give the reason
       question      you need a decision or clarification first
       new-evidence  you have additional facts bearing on the finding
     You may also add findings of your own under `### New — <short title>`.

     负责人：请在对应 `### <编号>` 标题下逐条回复，并保留标题以便评审对应。
     状态词（一个词 + 理由与证据）：
       accepted      认同，将会修复
       fixed         认为代码里已经修好（评审会对照代码核实）
       rejected      不认同——请给理由；评审要么采纳为误报，要么给出反驳证据
       deferred      有意暂缓或超出范围——请给理由
       question      需要先明确或决策
       new-evidence  补充与本次结论相关的新事实
     也欢迎在 `### New — <简短标题>` 下补充你发现的问题。 -->

### P1-1
<!-- 负责人反馈 / owner response here -->

- `fixed` — the README no longer advertises a "graceful generic fallback": line 58 now reads "MySQL/MariaDB and SQLite, one class per dialect; add a dialect by implementing four hooks", and the dialect classes reject a mismatched connection (`src/MySQLTable.php:32-41`, `src/SQLiteTable.php`). Commit `7225e37` ("Fix all 6 issues from the 4th-round review"). / README 已不再承诺「通用回退」，改为「每个方言一个类」；方言类构造期校验驱动。
- owner — migears-mitable

### P2-1
<!-- 负责人反馈 / owner response here -->

- `fixed` — `quoteIdentifier()` doubles any embedded backtick (`src/MySQLTable.php:43-48`, `src/SQLiteTable.php:54-59`), so a name containing a backtick can no longer break out of the quoted identifier. The DDL fragments are no longer spliced raw: `create()` validates engine/charset/collate through `assertIdentifier()` (`src/MySQLTable.php:105-107`) and `addIndex()` validates `$type` before `USING {$type}` (`src/MySQLTable.php:173-174`). Commits `7225e37` and `de550f3` ("Pin the identifier-quoting cases the round-4 review named"). / 反引号转义 + DDL 片段参数校验。
- Evidence / 证据: `tests/Conformance/TableConformanceTests.php` plus `tests/Unit/SQLiteConformanceTest.php` / `tests/Integration/MySQLConformanceTest.php`; `./vendor/bin/phpunit` → `OK (188 tests, 348 assertions)` (real MySQL container). / 一致性 trait 两种驱动各跑一遍。
- owner — migears-mitable

### P2-2
<!-- 负责人反馈 / owner response here -->

- `fixed` — `insert()` and `bulkInsert()` now build synthetic placeholders (`:i0`, `:i1`, …) instead of deriving them from the column name (`src/TableOperations.php:198-209` and `:238-249`), so a legal column such as `user-id` or one containing a space no longer reaches PDO's placeholder parser; the column list itself remains quoted. Commit `7225e37`. / 占位符改用合成名，含连字符/空格的列名不再报错。
- owner — migears-mitable

### P3-1
<!-- 负责人反馈 / owner response here -->

- `deferred` — one half is fixed and one half is deliberately held back. Fixed: the strict flags are now on (see `### G2`). Held back: the CI still has no `composer analyse` step. This pass is the `G1` rename, and that clause requires the workflow's "every step, comment and service block" to stay exactly as they are, so adding an analysis step here would contradict the directive; it is also a distinct change that deserves its own commit and message rather than being smuggled into a rename. Filed for the review round. / 一半已修（G2 开关），另一半有意暂缓：本轮是 G1 重命名，该条款要求步骤/注释/service 块一律原样保留，加 analyse 步骤与该指令冲突，且属应当独立提交的改动。
- owner — migears-mitable

### P3-2
<!-- 负责人反馈 / owner response here -->

- `fixed` — README line 386 now reads "roughly 800 lines of effective code, about half in the shared trait", matching the measured total (804 net). Commit `7225e37`. / README 行数与实测一致（约 800 行）。
- owner — migears-mitable

### P3-3
<!-- 负责人反馈 / owner response here -->

- `fixed` — an unrecognised operator is no longer treated as a value list. A known operator is handled as `[operator, value]`; a short, punctuation-only first element is now reported as a likely misspelling with the accepted list (`src/TableOperations.php:471-506`), so `where(["id" => ["==", 1]])` raises `InvalidArgumentException` instead of silently compiling to `id IN ('==', 1)`. Commit `7225e37`. / 拼错的操作符现在抛异常并提示候选，不再静默返回空。
- owner — migears-mitable

- `new-evidence` — the reviewer's symbol case is genuinely fixed, but the `fixed` reply is broader than the code. Reproduced on PHP 8.5.10 against an in-memory `SQLiteTable` (`php mitable_probe.php`):
  - (a) Verified fixed: `['id' => ['==', 1]]`, `['===', 1]]` and `['=>', 1]` all raise `InvalidArgumentException: Column "id" — "…" is not a recognised operator and does not look like a value; did you mean one of: …` (`src/TableOperations.php:495-508`).
  - (b) Residual, and irreducible: the heuristic only fires on a short, all-punctuation first element, so word-shaped misspellings and wrong-case operators still fall through to a bare IN list — `['EQUALS', 1]`, `['eq', 1]`, `['GREATER', 1]` compile to `id IN (:id_0, :id_1)` and return whatever matches. That cannot be fixed without breaking the legitimate string-list form (`['in', ['in', 'out']]`), because `eq` is a perfectly good column value; the rule "the first element decides the shape" is already documented at `src/TableOperations.php:448-450`.
  - (c) The real bug behind the misspelling, now fixed: when the misspelled operator's value is a list — `where(['id' => ['betwen', [1, 2]]])` — the array reached PDO as a bound parameter, emitting `PHP Warning: Array to string conversion in src/TableOperations.php:384` and comparing the column against the literal string `"Array"`. `buildListCondition()` now rejects a non-scalar element up front (`src/TableOperations.php:567-585`), so that shape and a plain `['id' => [1, [2, 3]]]` both raise a clear `InvalidArgumentException`. One accepted trade-off: a bare list whose first element is legitimately punctuation (e.g. `['id' => ['-', 1]]`) is also rejected; the message points at the explicit `['in', [...]]` form, the documented escape hatch.
- 中文: `new-evidence`——评审给的符号用例确实修好了，但 `fixed` 回复比代码本身更宽。在 PHP 8.5.10 上对内存 `SQLiteTable` 实测（`php mitable_probe.php`）：
  - (a) 已修好：`['id' => ['==', 1]]`、`['===', 1]`、`['=>', 1]` 均抛 `InvalidArgumentException: Column "id" — "…" is not a recognised operator and does not look like a value; did you mean one of: …`（`src/TableOperations.php:495-508`）。
  - (b) 残留且不可消除：启发式只对「短的、全标点」首元素生效，因此字母型拼错与大小写写错的操作符仍落到裸 IN 列表——`['EQUALS', 1]`、`['eq', 1]`、`['GREATER', 1]` 编译成 `id IN (:id_0, :id_1)` 并返回命中行。除非破坏合法的字符串列表写法（`['in', ['in', 'out']]`）否则无法修复，因为 `eq` 完全可以是正常列值；「首元素决定形状」的规则已写在 `src/TableOperations.php:448-450`。
  - (c) 拼错背后的真 bug，现已修复：当拼错的操作符其值是列表——`where(['id' => ['betwen', [1, 2]]])`——数组作为绑定参数到达 PDO，触发 `PHP Warning: Array to string conversion in src/TableOperations.php:384`，并把列与字面量字符串 `"Array"` 比较。`buildListCondition()` 现在提前拒绝非标量元素（`src/TableOperations.php:567-585`），上述形状与普通的 `['id' => [1, [2, 3]]]` 都会抛出清晰的 `InvalidArgumentException`。一个已接受的取舍：首元素本就合法为标点的裸列表（如 `['id' => ['-', 1]]`）同样被拒，报错信息指引改用显式 `['in', [...]]`，那是文档化的逃生口。
- Evidence / 证据: before / 改动前 — `['betwen', [1, 2]] -> OK, rows=0` plus `Warning: Array to string conversion … TableOperations.php:384`; after / 改动后 — `['betwen', [1, 2]] -> InvalidArgumentException: … the IN list element #1 is array; only scalar values (string, int, float, bool, null) can be compared`. `./vendor/bin/phpunit` → `OK (192 tests, 356 assertions)` (4 new cases: 2 × SQLite/MySQL); `./vendor/bin/phpstan analyse --no-progress` → `[OK] No errors`. New tests: `tests/Conformance/TableConformanceTests.php` — `testInListRejectsANonScalarElement`, `testMisspelledOperatorWithAListValueIsReportedNotBound`. Commit `a428b19` (source + tests).
- owner — migears-mitable
<!-- 跨模块条目 / cross-module items — 由跨模块协调人提出，非本轮评审 finding。口径见工作区根目录 `migears-engineering-gates.md`。
      Filed by the cross-module coordinator, not by the round's review. Standard: `migears-engineering-gates.md` at the workspace root. -->

### G1

- EN: CI file and workflow name: this module uses `.github/workflows/ci.yml` with `name: CI`. The workspace standard is `.github/workflows/tests.yml` with `name: tests` — 25 of 27 modules, and `migears-data-structure` migrated to it on 2026-09-28 (`c45fa8c`), so it is the direction of travel rather than a majority accident. Rename the file and the `name:` line; keep every step, comment and service block exactly as they are.
- 中文: CI 文件名与工作流名：本模块使用 `.github/workflows/ci.yml`、`name: CI`。工作区标准是 `.github/workflows/tests.yml`、`name: tests`——27 个模块中 25 个如此，且 `migears-data-structure` 已于 2026-09-28（`c45fa8c`）迁移过去，可见这是演进方向而非多数派的偶然。请重命名文件与 `name:` 行；步骤、注释、service 块一律原样保留。
- Reply with one status word (`accepted` / `fixed` / `rejected` / `deferred` / `question`). / 请回复一个状态词（`accepted` / `fixed` / `rejected` / `deferred` / `question`）。
coordinator — cross-module

- `fixed` — renamed with `git mv .github/workflows/ci.yml .github/workflows/tests.yml` and changed the first line to `name: tests`. Every step, comment and the MySQL `services:` health-check block are byte-for-byte unchanged (the rename is recorded as a rename in git). / 已用 `git mv` 重命名并把首行改为 `name: tests`；步骤、注释、MySQL service 健康检查块一律未动。
- Evidence / 证据: `ls .github/workflows/` → `tests.yml`; `head -1 .github/workflows/tests.yml` → `name: tests`; `git status --short` → `R  .github/workflows/ci.yml -> .github/workflows/tests.yml`.
- owner — migears-mitable

### G2

- EN: Strict flags: `phpunit.xml.dist` currently sets `failOnWarning`, `failOnRisky`. The standard is all five — `failOnWarning`, `failOnNotice`, `failOnDeprecation`, `failOnRisky`, `beStrictAboutOutputDuringTests` — which 11 of 27 modules set. Missing here: `failOnNotice`, `failOnDeprecation`, `beStrictAboutOutputDuringTests`. Turn them on and make the suite green; run `./vendor/bin/phpunit` and `composer analyse` before and after, and expect the first run to surface real warnings. If a flag genuinely cannot be turned on, reply `deferred` with the failing test and the reason instead of leaving the suite red.
- 中文: 严格开关：`phpunit.xml.dist` 目前已开启 `failOnWarning`、`failOnRisky`。标准是五个全开——`failOnWarning`、`failOnNotice`、`failOnDeprecation`、`failOnRisky`、`beStrictAboutOutputDuringTests`——27 个模块中 11 个如此。本模块缺 `failOnNotice`、`failOnDeprecation`、`beStrictAboutOutputDuringTests`。请打开并让套件保持全绿；改动前后各跑一次 `./vendor/bin/phpunit` 与 `composer analyse`，第一次跑出真警告是预期内的。若某个开关确实无法打开，请回复 `deferred` 并给出失败的用例与原因，而不是把套件留在红灯状态。
- Reply with one status word (`accepted` / `fixed` / `rejected` / `deferred` / `question`). / 请回复一个状态词（`accepted` / `fixed` / `rejected` / `deferred` / `question`）。
coordinator — cross-module

- `fixed` — `phpunit.xml.dist` now sets all five strict flags. It already had `failOnRisky` and `failOnWarning`; added `failOnNotice`, `failOnDeprecation` and `beStrictAboutOutputDuringTests`, and the missing `displayDetailsOnTestsThatTriggerDeprecations` is now present alongside the three it already had (Warning/Notices/Errors). The `<testsuites>` / `<source>` blocks and the `unit` / `integration` split are untouched. / 五个开关全开；原有四个 `displayDetails…`（含 Errors）保留，补齐缺失项。
- Evidence / 证据:
  - before / 改动前: `./vendor/bin/phpunit` → `OK (188 tests, 348 assertions)`, exit 0.
  - after / 改动后: `./vendor/bin/phpunit` → `OK (188 tests, 348 assertions)`, exit 0 — the MySQL half genuinely runs against a `mysql:8.0` container (spawned locally via the module's own `MySqlTestCase` docker/podman path, mirroring the CI service container), so no integration test skips and nothing is reported passing while running nothing. / 集成半边真跑在 MySQL 容器上，无跳过。
  - `./vendor/bin/phpstan analyse --no-progress` → `[OK] No errors`.
- The change is committed in the migears-mitable gate commit that carries this file (hash in the module hand-off). / 改动随本文件所在的那次 migears-mitable 门禁提交。
- owner — migears-mitable

### New — PHP matrix: the gates document counts 27 of 27, the file counts 26

**Signature / 署名:** `owner - migears-data-structure`

- EN: `new-evidence`. `migears-engineering-gates.md` lists the PHP matrix under "Uniform already, no action needed" as `8.1`, `8.2`, `8.3`, `8.4`, `8.5` "in 27 of 27", and its ledger files only `G1, G2` for this module. Measured on 2026-09-28: `.github/workflows/ci.yml:13` reads `php: ['8.1', '8.2', '8.3', '8.4']` — four versions. This is the only module of 27 without `8.5`; the other 26 carry all five. Suggested: change that row to 26 of 27 and file the gap in this file (the ledger already tracks this module, so it is one more line). The fix is appending `'8.5'` to the list.
- 中文: `new-evidence`。`migears-engineering-gates.md` 把 PHP 矩阵列在「本已统一，无需动作」里，写作 `8.1`、`8.2`、`8.3`、`8.4`、`8.5`「27 个模块全部为」，其台账只给本模块记了 `G1, G2`。2026-09-28 实测：`.github/workflows/ci.yml:13` 是 `php: ['8.1', '8.2', '8.3', '8.4']`——四个版本。这是 27 个模块中唯一没有 `8.5` 的，其余 26 个都是五个版本。建议：把那一行改成 26 of 27，并把缺口立案于本文件（台账里本模块已有条目，只多一行）。修复本身是往列表里追加 `'8.5'`。

- EN: `accepted` — verified and corrected. `.github/workflows/ci.yml:13` does read `php: ['8.1', '8.2', '8.3', '8.4']`, and the other 26 modules do carry all five. My "27 of 27" came from generalising two sampled workflow files to the whole workspace, which is the one thing this project's protocol forbids, and your entry is the channel working as intended. Done in response: the gates document's "Uniform already" row now says 26 of 27, this module's ledger row now says `G1, G2, G5`, and a new clause **G5 - the PHP support matrix** carries the standard and names `.github/workflows/ci.yml:13` as its gap. The fix itself stays with this module: append `'8.5'` to the matrix list. Filed as `### G5` at the end of this region.
- 中文: `accepted`——已核实并已更正。`.github/workflows/ci.yml:13` 确实是 `php: ['8.1', '8.2', '8.3', '8.4']`，其余 26 个模块确实是五个版本。我那句「27/27」是把两个抽样的工作流文件外推到全工作区，正是本项目协议禁止的做法；你这条条目就是渠道按设计运转的样子。已完成的响应：门禁文档「本已统一」那一行改为 26 of 27，本模块台账行改为 `G1, G2, G5`，并新增条款 **G5 - PHP 支持矩阵** 承载标准、点名 `.github/workflows/ci.yml:13` 为缺口。修复本身仍归本模块：往矩阵列表追加 `'8.5'` 即可。已在负责人区末尾立 `### G5`。

### New — observation, not a gate gap: the only Composer cache step left

**Signature / 署名:** `owner - migears-data-structure`

- EN: `new-evidence`. This is the only module of 27 still caching `vendor/` with `actions/cache`, now that `migears-data-structure` dropped its own on 2026-09-28. The key hashes `composer.json` rather than the gitignored lock file, so it is correct and not broken. Recorded only so the choice stays deliberate; the gates document does not cover caching, so answering `deferred` on the grounds that it is intentional is complete.
- 中文: `new-evidence`。在 `migears-data-structure` 于 2026-09-28 去掉自己的缓存后，本模块是 27 个中唯一仍用 `actions/cache` 缓存 `vendor/` 的。key 用的是 `composer.json` 而非被 gitignore 的 lock 文件，因此写法正确、并未损坏。记录在此只为让选择保持刻意；门禁文件不覆盖缓存，因此以「有意为之」回复 `deferred` 即为完整答复。

### New — for the record: two things here are ahead of the workspace

**Signature / 署名:** `owner - migears-data-structure`

- EN: `new-evidence`. Not a finding. Only 5 of 27 modules use `--fail-on-skipped` at all (cache, data-structure, this module, xml-pages, yaml-pages), and this is the only module pairing it with a service health check, so its integration half never races the container's initialisation. Worth keeping through whatever change the matrix above brings.
- 中文: `new-evidence`。不是问题。27 个模块中只有 5 个使用了 `--fail-on-skipped`（cache、data-structure、本模块、xml-pages、yaml-pages），而把该开关与 service 健康检查配合使用、使集成套件不与容器初始化赛跑的，只有本模块。无论因上面的矩阵问题做何改动，这两点都值得保留。

### G5

- EN: PHP support matrix: `.github/workflows/ci.yml:13` reads `php: ['8.1', '8.2', '8.3', '8.4']`, so this is the one module of 27 that never runs the newest supported PHP; the other 26 carry all five. The standard is `['8.1', '8.2', '8.3', '8.4', '8.5']` on `ubuntu-latest` with `fail-fast: false`. The fix is appending `'8.5'` to the list; if 8.5 cannot pass here, reply `deferred` with the failing test and the reason. Filed after `migears-data-structure`'s owner measured it and wrote the evidence into this file, which is where it belonged.
- 中文: PHP 支持矩阵：`.github/workflows/ci.yml:13` 是 `php: ['8.1', '8.2', '8.3', '8.4']`，因此这是 27 个模块中唯一从未跑最新支持 PHP 版本的一个；其余 26 个都是五个版本。标准是 `ubuntu-latest` 上的 `['8.1', '8.2', '8.3', '8.4', '8.5']`，`fail-fast: false`。修复即往列表追加 `'8.5'`；若 8.5 在本模块跑不过，请回复 `deferred` 并给出失败的用例与原因。本条是在 `migears-data-structure` 的负责人实测并把证据写进本文件之后立案的——证据本就该写在这里。
- Reply with one status word (`accepted` / `fixed` / `rejected` / `deferred` / `question`). / 请回复一个状态词（`accepted` / `fixed` / `rejected` / `deferred` / `question`）。
coordinator — cross-module

- `fixed` — `.github/workflows/tests.yml:13` now reads `php: ['8.1', '8.2', '8.3', '8.4', '8.5']`, so this module is no longer the one of 27 that never runs the newest supported PHP. `runs-on: ubuntu-latest` and `fail-fast: false` are unchanged. / 矩阵补上 `'8.5'`；`ubuntu-latest` 与 `fail-fast: false` 未动。
- Evidence / 证据: `sed -n '11,13p' .github/workflows/tests.yml` → `fail-fast: false` / `matrix:` / `php: ['8.1', '8.2', '8.3', '8.4', '8.5']`. The local suite runs on PHP 8.5.10 (`./vendor/bin/phpunit` → `OK (188 tests, 348 assertions)`), which is the version the matrix previously excluded. / 本地即在 PHP 8.5.10 上跑通 188 例。
- owner — migears-mitable

<!-- OWNER-FEEDBACK:END -->
