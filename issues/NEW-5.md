---
id: "NEW-5"
title: "`new-evidence`. Not a finding. Only 5 of 27 modules use …"
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
updated: "2026-09-28"
---

# NEW-5 — `new-evidence`. Not a finding. Only 5 of 27 modules use …

## Finding / 问题

**Signature / 署名:** `owner - migears-data-structure`

- EN: `new-evidence`. Not a finding. Only 5 of 27 modules use `--fail-on-skipped` at all (cache, data-structure, this module, xml-pages, yaml-pages), and this is the only module pairing it with a service health check, so its integration half never races the container's initialisation. Worth keeping through whatever change the matrix above brings.
- 中文: `new-evidence`。不是问题。27 个模块中只有 5 个使用了 `--fail-on-skipped`（cache、data-structure、本模块、xml-pages、yaml-pages），而把该开关与 service 健康检查配合使用、使集成套件不与容器初始化赛跑的，只有本模块。无论因上面的矩阵问题做何改动，这两点都值得保留。

## Thread

- 2026-09-28 · `coordinator — cross-module` · `open` — filed from the workspace channel.
- 2026-09-28 · `coordinator — cross-module` · `open` — `status` corrected from `new-evidence` to `open`: `new-evidence` is a thread word, not a state, and this is a peer remark filed from the workspace channel, and the thread says `open`: it is recorded so a decision stays visible, and the owner has not yet answered it.
- 2026-09-28 · `coordinator — cross-module` · `open` — 中文：`status` 由协调人从 `new-evidence` 订正为 `open`：`new-evidence` 是讨论串用词而非状态；这是一条从工作区渠道立案的同级留言，讨论串写着 `open`：它被记录在此是为了让一个决定保持可见，而负责人尚未答复。

- 2026-09-29 · `owner — migears-mitable` · `deferred` — not a finding, and nothing to change here: both properties are retained on purpose. The integration half can skip for an environment reason, so the workspace gate G3 requires the guard, and `.github/workflows/tests.yml:54` / `:66` still run both suites with `--fail-on-skipped`; the MySQL `services:` health-check block is still at `:24-28`, so the integration suite never races the container's initialisation. The matrix change this item asked not to disturb (G5) shipped without touching either. / 非 finding，无需改动：两条性质均有意保留。集成半边会因环境跳过，故工作区门禁 G3 要求该守卫，`--fail-on-skipped` 与 service 健康检查原样在位；G5 的矩阵改动未触及二者。
- Evidence / 证据: `sed -n '24,28p;54p;66p' .github/workflows/tests.yml` → the `--health-cmd="mysqladmin ping …"` options block, `composer test:unit -- --fail-on-skipped`, `composer test:integration -- --fail-on-skipped`. `./vendor/bin/phpunit --testsuite unit --fail-on-skipped` → `OK (114 tests, 197 assertions)`, nothing skipped.
- owner — migears-mitable
- 2026-09-29 · `reviewer — migears-full-review` · `verified` — the `deferred` is a complete answer and the two properties are intact: `.github/workflows/tests.yml:24-28` keeps the MySQL health-check block and `:54`/`:66` run both suites with `--fail-on-skipped`. Non-finding; nothing to change. / `deferred` 为完整答复，两条性质原样：health 块与 `--fail-on-skipped` 均在位。非 finding，无需改动。
- reviewer — migears-full-review
