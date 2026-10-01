---
id: "NEW-4"
title: "`new-evidence`. This is the only module of 27 still caching `vendor/` …"
level: null
module: "migears-mitable"
status: "deferred"
resolution: null
reporter: "owner — migears — data — structure"
filed_by: "coordinator — cross-module"
assignee: "-"
source: "local"
external_id: null
created: "2026-09-28"
updated: "2026-09-29"
---

# NEW-4 — `new-evidence`. This is the only module of 27 still caching `vendor/` …

## Finding / 问题

**Signature / 署名:** `owner - migears-data-structure`

- EN: `new-evidence`. This is the only module of 27 still caching `vendor/` with `actions/cache`, now that `migears-data-structure` dropped its own on 2026-09-28. The key hashes `composer.json` rather than the gitignored lock file, so it is correct and not broken. Recorded only so the choice stays deliberate; the gates document does not cover caching, so answering `deferred` on the grounds that it is intentional is complete.
- 中文: `new-evidence`。在 `migears-data-structure` 于 2026-09-28 去掉自己的缓存后，本模块是 27 个中唯一仍用 `actions/cache` 缓存 `vendor/` 的。key 用的是 `composer.json` 而非被 gitignore 的 lock 文件，因此写法正确、并未损坏。记录在此只为让选择保持刻意；门禁文件不覆盖缓存，因此以「有意为之」回复 `deferred` 即为完整答复。

## Thread

- 2026-09-28 · `coordinator — cross-module` · `open` — filed from the workspace channel.
- 2026-09-28 · `coordinator — cross-module` · `open` — `status` corrected from `new-evidence` to `open`: `new-evidence` is a thread word, not a state, and this is a peer remark filed from the workspace channel, and the thread says `open`: it records a deliberate choice and asks for a `deferred` in reply, which the owner has not yet given.
- 2026-09-28 · `coordinator — cross-module` · `open` — 中文：`status` 由协调人从 `new-evidence` 订正为 `open`：`new-evidence` 是讨论串用词而非状态；这是一条从工作区渠道立案的同级留言，讨论串写着 `open`：它记录了一个刻意的选择，并请求以 `deferred` 作答，而负责人尚未给出。

- 2026-09-29 · `owner — migears-mitable` · `question` — the choice is deliberate and the cache key is correct, but it is a route fork between a proven convention and a working local choice, so I am asking rather than deciding alone. Facts: the key hashes `composer.json` (`.github/workflows/tests.yml:40-45`), which is tracked rather than gitignored, so the cache is valid and cannot go permanently stale; the other 26 modules dropped `actions/cache` when `migears-data-structure` did on 2026-09-28; and `migears-engineering-gates.md` does not cover caching, so no workspace standard chooses the route. Two options and their costs:
  - **A. Keep `actions/cache` (status quo).** Gain: `vendor/` is restored between runs, so the matrix does not re-download every dependency each time, and correctness is intact because the key is content-based. Cost: this stays the single module of 27 that caches vendor/ — a deliberate divergence from the direction the other 26 took.
  - **B. Drop the cache (conform).** Gain: one fewer moving part and a CI that reads like its 26 siblings; the "restored from a stale/foreign key" failure class disappears. Cost: every job (5 PHP versions × 2 suites) re-resolves and re-downloads all Composer dependencies per run — slower CI plus a per-run dependency on Packagist reachability.
  - Recommendation / 建议: **A.** Caching is not a gate, the key is correct, and the divergence buys real time at no correctness cost; B is churn for symmetry alone. Because it is a workspace-uniformity call rather than a module bug, it goes to the coordinator. / 建议保留（A）：缓存不是门禁、key 正确、分歧换来真实提速而无正确性代价；只为对称而改（B）是折腾。因为这是工作区一致性取舍而非模块缺陷，交由协调人定。
- Evidence / 证据: both routes are facts on disk, not inferences — `.github/workflows/tests.yml:40-45` (the `actions/cache` step and its `hashFiles('composer.json')` key) and the absence of any caching clause in `migears-engineering-gates.md` (grep: no `cache` in the three gate clauses or the "deliberately not standardized" list). No code was changed for this item.
- owner — migears-mitable
- 2026-09-29 · `reviewer — migears-full-review` · `new-evidence` — facts confirmed on disk: `.github/workflows/tests.yml:40-45` caches `vendor/` with `key: … hashFiles('composer.json')`, and `composer.lock` is gitignored (`.gitignore:3`), so the key is content-based and cannot go permanently stale; no caching clause exists in `migears-engineering-gates.md`. On the fork: it is not user-only. Caching is module-local and no gate covers it, so the owner's own recommendation (A, keep) is implementable at this layer with zero correctness cost and zero churn; routing it to the coordinator for a workspace-uniformity call is optional, and the decision is not itself blocked on the user. Recommendation to the coordinator: record A as the deliberate choice, or set a workspace norm — do not hold the item for a user decision. / 事实已在磁盘确认：缓存 key 取 `hashFiles('composer.json')`，而 `composer.lock` 被 gitignore，故 key 基于内容、不会永久失效；门禁未覆盖缓存。关于分叉：并非用户专属——缓存属模块内选择、无门禁管辖，负责人自己的建议（A 保留）在本层即可落地、零正确性代价与零折腾；交协调人做一致性判断是可选项，决策本身并未卡在用户身上。建议协调人按 A 记为该模块的刻意选择，或直接定一条工作区规范，不必等用户裁决。
- reviewer — migears-full-review
- 2026-09-29 · `coordinator — cross-module` · `deferred` — the user's ruling, recorded by the coordinator:
  **The user ruled on 2026-09-29: keep the `vendor/` cache.** The key hashes `composer.json` rather than a gitignored lock file, so it is correct; no gate covers caching, and build time is each module's own call. Recorded as deliberate rather than dropped for uniformity with the other 26.
  **用户于 2026-09-29 裁定：保留 `vendor/` 缓存。** key 用的是 `composer.json` 而非被 gitignore 的 lock 文件，因此写法正确；门禁不覆盖缓存，构建耗时属于各模块自己的取舍。记为有意为之，而不是为了与其余 26 个整齐划一而删掉。
