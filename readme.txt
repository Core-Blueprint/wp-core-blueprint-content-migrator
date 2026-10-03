=== Core Blueprint Content Migrator ===
Contributors: coreblueprint
Tags: migration, post type, taxonomy, terms, content
Requires at least: 7.0
Tested up to: 7.1
Requires PHP: 8.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Safely migrate WordPress posts and taxonomies with explicit mapping, verification and rollback.

== Description ==

Core Blueprint Content Migrator is a Core Blueprint extension for safely copying content between registered post types and taxonomies on the current WordPress site.

Post migrations support selecting individual source posts, explicit taxonomy mapping and explicit post-meta mapping. Taxonomy migrations support selecting individual source terms, automatic required parent dependencies, term hierarchy, explicit term-meta mapping and optional relationship remapping for shared post types.

Every migration follows Analyze -> Review -> Copy -> Verify -> Roll back or Finalize. Analysis is read-only, and source content remains intact while the migration is being tested.

Core Blueprint Base 1.0.0 or newer is required. Content Migrator registers as a first-party extension and records migration lifecycle and failure events in the Base Governance audit log.

== Installation ==

1. Install and activate Core Blueprint Base 1.0.0 or newer.
2. Install and activate Core Blueprint Content Migrator.
3. Open Tools > Content Migrator in WordPress admin.
4. Choose a post-type migration or taxonomy migration and analyze the source and target before creating a migration job.

== How it works ==

= Post migrations =

* Analyze the source and target post types before any writes happen.
* Select the source posts that should be included.
* Map taxonomies and post meta explicitly.
* Copy content in bounded batches.
* Verify the copied targets before finalizing.
* Roll back job-created targets, or finalize and optionally move verified source posts to WordPress Trash.

= Taxonomy migrations =

* Analyze the source and target taxonomies before any writes happen.
* Select the source terms that should be included.
* Preserve required parent terms when both taxonomies are hierarchical.
* Map term meta explicitly.
* Optionally add equivalent relationships where the source and target taxonomies support the same post types.
* Verify the copied terms and relationships before finalizing or rolling back.

== Safety and rollback ==

Content Migrator does not guess data mappings. A taxonomy or custom field is skipped unless an administrator maps it.

The copy phase does not delete or change source posts. Source posts can only be moved to normal WordPress Trash after successful verification and explicit confirmation. Source taxonomy terms are never deleted because WordPress has no reversible term Trash.

Rollback only removes targets and relationships tracked as created by the active migration job. Existing target terms reused by slug are never deleted. A job-created term is also preserved if other content or child terms started depending on it after the migration began.

Active migration state is intentionally preserved when the plugin is uninstalled so rollback information is not lost. Reinstall the plugin to continue, verify or roll back an unfinished migration.

== Privacy ==

Content Migrator does not send telemetry, tracking data or migration content to external services.

The plugin stores the current administrator's analyzed migration plan in WordPress user meta and stores the active migration job in WordPress options. Temporary rollback markers are stored on job-created target posts and terms until the migration is finalized or rolled back.

Migration lifecycle, failure and ownership events are recorded in the Core Blueprint Governance audit log.

== Frequently Asked Questions ==

= Does Content Migrator move content between different WordPress sites? =

No. Version 1.0 migrates registered post types and taxonomies within the same WordPress site.

= Does it permanently delete source content? =

No. Source posts are never permanently deleted. After successful verification, an administrator may explicitly choose to move source posts to normal WordPress Trash. Source taxonomy terms are always kept.

= Can rollback delete existing target content? =

Rollback only removes targets tracked as created by the active migration job. Existing target terms that were reused by matching slug are not deleted or overwritten.

= Does it require Gutenberg, Bricks or another page builder? =

No. Content Migrator is a WordPress admin utility and has no public frontend component.

== Changelog ==

= 1.0.0 =
* First stable public release.
* Requires Core Blueprint Base 1.0.0 or newer with compatible Core API 1.1.
* Adds Post and Taxonomy migration modes with explicit mapping, selective source migration and bounded batch processing.
* Adds verification, guarded finalization and conflict-safe rollback.
* Adds active-job ownership, stale-job protection, object-level capability rechecks and explicit destructive-action confirmations.
* Records migration lifecycle and failure events in the Core Blueprint Governance audit log.
