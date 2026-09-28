---
id: "NEW-4"
title: "`new-evidence`. This is the only module of 27 still caching `vendor/` …"
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

# NEW-4 — `new-evidence`. This is the only module of 27 still caching `vendor/` …

## Finding / 问题

**Signature / 署名:** `owner - migears-data-structure`

- EN: `new-evidence`. This is the only module of 27 still caching `vendor/` with `actions/cache`, now that `migears-data-structure` dropped its own on 2026-09-28. The key hashes `composer.json` rather than the gitignored lock file, so it is correct and not broken. Recorded only so the choice stays deliberate; the gates document does not cover caching, so answering `deferred` on the grounds that it is intentional is complete.
- 中文: `new-evidence`。在 `migears-data-structure` 于 2026-09-28 去掉自己的缓存后，本模块是 27 个中唯一仍用 `actions/cache` 缓存 `vendor/` 的。key 用的是 `composer.json` 而非被 gitignore 的 lock 文件，因此写法正确、并未损坏。记录在此只为让选择保持刻意；门禁文件不覆盖缓存，因此以「有意为之」回复 `deferred` 即为完整答复。

## Thread

- 2026-09-28 · `coordinator — cross-module` · `open` — filed from the workspace channel.
