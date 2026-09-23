# Changelog

## Unreleased — Golden safety hardening

- Require Core Blueprint Base 1.0.0-rc1+ and compatible Core API 1.1.
- Make Extension Registry, status and Governance audit integration part of the normal runtime contract.
- Track newly created posts and terms before later copy operations can fail so partial copies remain rollbackable.
- Add object-level capability rechecks for copy, relationship mutation, source Trash and rollback operations.
- Add explicit destructive-action confirmations, active-job request pinning and administrator ownership takeover.
- Prevent rollback from deleting migrated terms that gained external relationships or child terms.
- Keep rollback markers until finalization completes without issues.
- Add Golden safety regression and updated conformance checks.
- Update the canonical repository URI and public architecture documentation.

## 1.0.0-rc1

- Renamed the utility to Core Blueprint Content Migrator.
- Made the migration engine fully standalone; Core Blueprint Base is optional.
- Added WordPress-native `Tools → Content Migrator` administration.
- Added safe post-type to post-type migrations with taxonomy and post-meta mapping.
- Added taxonomy-to-taxonomy migrations with hierarchy, term-meta mapping and optional relationship remapping.
- Added conflict-safe reuse of existing target terms by slug without overwriting their data.
- Added batch processing, verification, rollback and finalize workflows for both modes.
- Added optional Core Blueprint Extension Registry and Governance integration when Base is available.
