---
id: "NEW-4"
title: "`new-evidence`. This is the only module of 27 still caching `vendor/` …"
level: null
module: "migears-mitable"
status: "open"
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
- 2026-09-28 · `coordinator — cross-module` · `open` — `status` corrected from `new-evidence` to `open`: `new-evidence` is a thread word, not a state, and this is a peer remark filed from the workspace channel, and the thread says `open`: it records a deliberate choice and asks for a `deferred` in reply, which the owner has not yet given.
- 2026-09-28 · `coordinator — cross-module` · `open` — 中文：`status` 由协调人从 `new-evidence` 订正为 `open`：`new-evidence` 是讨论串用词而非状态；这是一条从工作区渠道立案的同级留言，讨论串写着 `open`：它记录了一个刻意的选择，并请求以 `deferred` 作答，而负责人尚未给出。
