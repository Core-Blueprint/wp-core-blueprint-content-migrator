# Core Blueprint Content Migrator

Core Blueprint Content Migrator is a safety-first, standalone WordPress utility for migrating registered post types and taxonomies on the same site. Core Blueprint Base is optional and adds suite registration and Governance logging when available.

## v1.0.0-rc1 scope

### Post migrations

- Analyze a source and target post type before any writes happen.
- Copy normal WordPress post data while keeping the source untouched.
- Explicit source-taxonomy → target-taxonomy mapping.
- Explicit source-meta → target-meta mapping with registered target meta suggestions.
- Reuse the same featured-image attachment when supported.
- Copy in configurable batches (10–200 posts per request).
- Preserve hierarchical parent relations when the target post type is hierarchical.
- Verify copied core fields, mapped taxonomies and mapped meta.
- Roll back only target posts and taxonomy terms created by the active migration job; existing target terms are never deleted.
- Finalize while keeping the source, or move source posts to WordPress Trash.
- Optional Core Blueprint ExtensionRegistry and Governance integration when a compatible Base is present.

### Taxonomy migrations

- Analyze a source and target taxonomy before any writes happen.
- Copy terms and preserve hierarchy when the target taxonomy is hierarchical.
- Reuse existing target terms with matching slugs without overwriting their name, description, hierarchy or meta.
- Explicit source term-meta → target term-meta mapping for newly created target terms.
- Optionally add equivalent target-taxonomy relationships for post types supported by both taxonomies.
- Verify migrated terms, hierarchy, mapped term meta and relationships.
- Roll back only relationships and target terms created by the active migration job.
- Finalize while always keeping the source taxonomy intact; WordPress has no reversible term Trash.

## Safety boundaries

Content Migrator does not guess data mappings. A taxonomy or custom field is skipped unless the operator maps it.

The copy phase never deletes or changes source posts. RC1 never permanently deletes source content. The most destructive source action available is moving source posts to normal WordPress Trash after a successful verification.

Rollback uses internal per-job markers and refuses to delete a target whose marker no longer matches the active job.

## Dictionary migration example

To move an existing custom Content Models dictionary into Core Blueprint Dictionary:

1. Select the existing dictionary CPT as Source.
2. Select `cb_dictionary` as Target.
3. Analyze.
4. Map the old category taxonomy to `cb_dictionary_category`.
5. Map any old tag taxonomy to `cb_dictionary_tag`.
6. Do **not** map `cb_dictionary_letter`; Dictionary owns and assigns its A–Z/0–9 taxonomy automatically when each target post is inserted.
7. Map old custom-field meta keys to the desired `cb_dictionary_*` keys.
8. Copy all batches.
9. Verify.
10. Finalize and keep the source until the new Dictionary frontend has been tested.

## Not included in RC1

- Cross-site migration.
- Attachment-file duplication (the same Media Library attachment can be reused as featured image).
- Comment copying.
- Automatic semantic field guessing.
- Permanent deletion of source content.

## Requirements

- WordPress 7.0+
- PHP 8.4+

Core Blueprint Base is **not required**. When a compatible Core API 1.x Base is active, Content Migrator optionally registers with the suite and records Governance events.
