=== Core Blueprint Content Migrator ===
Contributors: coreblueprint
Tags: migration, post type, taxonomy, terms, content
Requires at least: 7.0
Requires PHP: 8.4
Stable tag: 1.0.0-rc1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Safely migrate WordPress posts and taxonomies with explicit mapping, verification and rollback.

== Description ==

Core Blueprint Content Migrator is a standalone WordPress utility for copying content between registered post types and taxonomies on the current site.

Post migrations support explicit taxonomy and post-meta mapping. Taxonomy migrations support term hierarchy, explicit term-meta mapping and optional relationship remapping for shared post types.

Every migration uses Analyze → Review → Copy → Verify → Roll back or Finalize. Source content remains intact while the migration is being tested.

Core Blueprint Base 1.0.0-rc1 or newer is required. Content Migrator registers as a first-party extension and records migration lifecycle and failure events in the Base Governance audit log.

== Changelog ==

= 1.0.0-rc1 =
* First public release candidate with Post and Taxonomy migration modes.
* Requires Core Blueprint Base and uses its Extension Registry and Governance audit contracts.
* Adds guarded migration ownership, stale-job protection and conflict-safe rollback behavior.
