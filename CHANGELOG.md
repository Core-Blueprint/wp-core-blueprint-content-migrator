# Changelog

## 1.0.0-rc1

- Renamed the utility to Core Blueprint Content Migrator.
- Made the migration engine fully standalone; Core Blueprint Base is optional.
- Added WordPress-native `Tools → Content Migrator` administration.
- Added safe post-type to post-type migrations with taxonomy and post-meta mapping.
- Added taxonomy-to-taxonomy migrations with hierarchy, term-meta mapping and optional relationship remapping.
- Added conflict-safe reuse of existing target terms by slug without overwriting their data.
- Added batch processing, verification, rollback and finalize workflows for both modes.
- Added optional Core Blueprint Extension Registry and Governance integration when Base is available.
