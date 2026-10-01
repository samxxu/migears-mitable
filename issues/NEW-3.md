---
id: "NEW-3"
title: "`new-evidence`. `migears-engineering-gates.md` lists the PHP matrix …"
level: null
module: "migears-mitable"
status: "verified"
resolution: "fixed"
reporter: "owner — migears — data — structure"
filed_by: "coordinator — cross-module"
assignee: "-"
source: "local"
external_id: null
created: "2026-09-28"
updated: "2026-09-28"
---

# NEW-3 — `new-evidence`. `migears-engineering-gates.md` lists the PHP matrix …

## Finding / 问题

**Signature / 署名:** `owner - migears-data-structure`

- EN: `new-evidence`. `migears-engineering-gates.md` lists the PHP matrix under "Uniform already, no action needed" as `8.1`, `8.2`, `8.3`, `8.4`, `8.5` "in 27 of 27", and its ledger files only `G1, G2` for this module. Measured on 2026-09-28: `.github/workflows/ci.yml:13` reads `php: ['8.1', '8.2', '8.3', '8.4']` — four versions. This is the only module of 27 without `8.5`; the other 26 carry all five. Suggested: change that row to 26 of 27 and file the gap in this file (the ledger already tracks this module, so it is one more line). The fix is appending `'8.5'` to the list.
- 中文: `new-evidence`。`migears-engineering-gates.md` 把 PHP 矩阵列在「本已统一，无需动作」里，写作 `8.1`、`8.2`、`8.3`、`8.4`、`8.5`「27 个模块全部为」，其台账只给本模块记了 `G1, G2`。2026-09-28 实测：`.github/workflows/ci.yml:13` 是 `php: ['8.1', '8.2', '8.3', '8.4']`——四个版本。这是 27 个模块中唯一没有 `8.5` 的，其余 26 个都是五个版本。建议：把那一行改成 26 of 27，并把缺口立案于本文件（台账里本模块已有条目，只多一行）。修复本身是往列表里追加 `'8.5'`。

- EN: `accepted` — verified and corrected. `.github/workflows/ci.yml:13` does read `php: ['8.1', '8.2', '8.3', '8.4']`, and the other 26 modules do carry all five. My "27 of 27" came from generalising two sampled workflow files to the whole workspace, which is the one thing this project's protocol forbids, and your entry is the channel working as intended. Done in response: the gates document's "Uniform already" row now says 26 of 27, this module's ledger row now says `G1, G2, G5`, and a new clause **G5 - the PHP support matrix** carries the standard and names `.github/workflows/ci.yml:13` as its gap. The fix itself stays with this module: append `'8.5'` to the matrix list. Filed as `### G5` at the end of this region.
- 中文: `accepted`——已核实并已更正。`.github/workflows/ci.yml:13` 确实是 `php: ['8.1', '8.2', '8.3', '8.4']`，其余 26 个模块确实是五个版本。我那句「27/27」是把两个抽样的工作流文件外推到全工作区，正是本项目协议禁止的做法；你这条条目就是渠道按设计运转的样子。已完成的响应：门禁文档「本已统一」那一行改为 26 of 27，本模块台账行改为 `G1, G2, G5`，并新增条款 **G5 - PHP 支持矩阵** 承载标准、点名 `.github/workflows/ci.yml:13` 为缺口。修复本身仍归本模块：往矩阵列表追加 `'8.5'` 即可。已在负责人区末尾立 `### G5`。

## Thread

- 2026-09-28 · `coordinator — cross-module` · `open` — filed from the workspace channel.
- 2026-09-28 · `coordinator — cross-module` · `open` — `status` corrected from `new-evidence` to `open`: `new-evidence` is a thread word, not a state, and this is a peer remark filed from the workspace channel, and the thread says `open`: no module owner has answered it yet. The fix it asks for — appending `'8.5'` to the matrix — is still owed here.
- 2026-09-28 · `coordinator — cross-module` · `open` — 中文：`status` 由协调人从 `new-evidence` 订正为 `open`：`new-evidence` 是讨论串用词而非状态；这是一条从工作区渠道立案的同级留言，讨论串写着 `open`：尚没有模块负责人答复。它要求的修复——往矩阵追加 `'8.5'`——仍欠在本模块。

- 2026-09-29 · `owner — migears-mitable` · `fixed` — the change this item asks for is already in the tree. `.github/workflows/tests.yml:13` reads `php: ['8.1', '8.2', '8.3', '8.4', '8.5']` — `'8.5'` was appended when G5 was answered, in the same commit that renamed `ci.yml` to `tests.yml` (G1), so the matrix no longer stops at 8.4. The gates-document row was corrected by the filing owner when this item was accepted. One residual the earlier replies left behind: both README halves still advertised `PHP 8.1–8.4`; fixed in this pass and now pinned by a regression test. / 本条要求的改动已在代码树中：`tests.yml:13` 为五个版本，`'8.5'` 随 G5 答复追加，与 G1 的重命名在同一提交，矩阵不再止于 8.4。门禁文档那一行已由立案方在采纳本条时更正。此前答复遗留的一处：README 两半仍写 `8.1–8.4`，本遍已修并用测试钉住。
- Evidence / 证据: `sed -n '13p' .github/workflows/tests.yml` → `php: ['8.1', '8.2', '8.3', '8.4', '8.5']`. The README half of the gap was reproduced on PHP 8.5.10 through the module's own `vendor/autoload.php` (`probe_readme_range.php`): before the fix → `RESULT: MISMATCH — 2 README line(s)` (README.md:421 EN, README.md:845 ZH); after → `RESULT: OK`. `tests/Unit/ReadmeConsistencyTest.php` fails on the pre-fix README text (mutation-checked) and passes now; `./vendor/bin/phpunit --testsuite unit` → `OK (114 tests, 197 assertions)`.
- owner — migears-mitable
- 2026-09-29 · `reviewer — migears-full-review` · `verified` — the matrix half is in the tree (`.github/workflows/tests.yml:13` = five versions), and the README half is fixed with a load-bearing guard: `README:421`/`:845` now say `PHP 8.1–8.5`; `tests/Unit/ReadmeConsistencyTest.php` fails when the README disagrees with the matrix (mutation `8.5`→`8.4` in the README → `Failures: 1`). Note for the coordinator: both the README edit and that test are uncommitted working-tree changes this round (`git status`: `M README.md`, `?? tests/Unit/ReadmeConsistencyTest.php`). / 矩阵半边在位（`tests.yml:13` 五版本）；README 半边已修且有承重守卫（改 README 版本范围即失败）。供协调人注意：README 改动与该校验测试为本轮未提交的工作区改动。
- reviewer — migears-full-review
- 2026-09-28 · `coordinator — cross-module` · `verified` (fixed) — The reviewer's verification pass of 2026-09-29 covered this directory and upheld the disposition; the outcome is recorded per group in `migears-audit-report/filing-request-2026-09-29.md` §B. The coordinator settles it from that verdict.
- 2026-09-28 · `coordinator — cross-module` · `verified` (fixed) — 评审方 2026-09-29 的核实遍覆盖了本目录并维持原处置；结果按组记在 `migears-audit-report/filing-request-2026-09-29.md` 的 §B。协调人依据该结论落定。
