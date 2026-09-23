# Architecture

Core Blueprint Content Migrator is a **first-party Core Blueprint extension**. Core Blueprint Base 1.0.0-rc1 or newer and a compatible Core API 1.1 runtime are required. The migration engine uses public WordPress APIs for content changes and Base public contracts for extension registration, status and Governance auditing.

## Runtime layers

- `Admin/Page.php` — WordPress-native Tools screen and UI-as-manual workflow.
- `Admin/Controller.php` — capability/nonce gates, active-job pinning, ownership, confirmations and state transitions.
- `Migration/PostAnalyzer.php` — read-only post migration discovery.
- `Migration/PostRunner.php` — post copy, verification, rollback and finalize.
- `Migration/TaxonomyAnalyzer.php` — read-only taxonomy discovery.
- `Migration/TaxonomyRunner.php` — term copy, hierarchy, term meta, relationships, verification and rollback.
- `Migration/TermRollbackGuard.php` — shared protection against deleting migrated terms that gained external relationships or child terms.
- `Migration/PlanStore.php` — per-user analyzed plan.
- `Migration/JobStore.php` — one atomically claimed active site-level migration job.
- `Integration/Suite.php` — required Core Blueprint Extension Registry and status integration.
- `Governance/Events.php` — required Base Governance event registration and audit writes.

## Base contract

The plugin declares the native WordPress dependency `Requires Plugins: core-blueprint` and also validates the Core Blueprint runtime contract before booting the migration UI or controllers.

The runtime requires:

- Core Blueprint Base `1.0.0-rc1` or newer.
- Core API `1.1` with the same API major and an equal or newer API minor.
- Public `ExtensionRegistry`, `Governance\EventRegistry` and `Governance\Audit` contracts.

An installed but incompatible Base keeps the Content Migrator runtime inert and surfaces an administrator notice.

## Safety invariants

1. Analysis never mutates content.
2. Mappings are explicit; unknown fields and taxonomies are not guessed.
3. Every mutating job action is nonce-protected, capability-gated and pinned to the expected active job ID.
4. One site-level migration job is claimed atomically; stale job writes are refused.
5. A job has one explicit administrator owner. Another administrator must explicitly take ownership before mutating it.
6. Source posts are never permanently deleted by RC1.
7. Source taxonomy terms are never deleted by RC1.
8. A newly created target post is marked and added to the job map before later meta, image or taxonomy operations can fail.
9. A newly created target term is marked and tracked before mapped term meta can fail.
10. Verification fails closed when the copy phase recorded errors.
11. Post rollback deletes only marked target posts after rechecking object-level delete capability.
12. Term rollback deletes only marked, job-created terms that have no remaining object relationships and no remaining child terms.
13. Taxonomy rollback removes only relationships tracked as newly added by the job and rechecks permissions on the affected object.
14. Existing target terms matched by slug are reused without mutation.
15. Target lifecycle hooks remain enabled during post insertion.
16. Destructive rollback and source-to-Trash finalization require explicit confirmation.
17. Finalization performs a preflight and preserves rollback markers until all finalization work succeeds.
18. Major state transitions, failures and ownership takeover are written to the Base Governance audit log.

## Taxonomy conflicts

A matching target slug is treated as an existing canonical target term. Content Migrator maps the source term to it but does not overwrite its name, description, hierarchy or meta. Term-meta mapping applies only to terms created by the migration job.

If a job-created target term later gains an external content relationship or a child term outside the rollback set, rollback refuses to delete that term. This is intentional: rollback must not destroy content that started depending on migration-created data after the migration began.

## Finalization

Finalization removes rollback markers only after its preflight and requested source action have completed without issues.

Post mode can optionally move sources to WordPress Trash after verification and explicit confirmation. If a source cannot be moved safely, the job remains active and its rollback markers are preserved so the operator can resolve the issue and retry.

Taxonomy mode keeps the source because WordPress has no reversible term trash mechanism.
