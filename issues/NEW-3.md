---
id: "NEW-3"
title: "`new-evidence`. `migears-engineering-gates.md` lists the PHP matrix …"
level: null
module: "migears-mitable"
status: "new-evidence"
resolution: null
reporter: "owner — migears — data — structure"
filed_by: "coordinator — cross-module"
assignee: "owner — migears-mitable"
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
