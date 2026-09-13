# Core Blueprint Content Migrator release tooling

Content Migrator is intentionally standalone and Core Blueprint Base-optional. Do not add `Requires Plugins: core-blueprint`, an activation dependency gate, or a hard Base runtime dependency.

## Localization

English is the source language. The required reviewed locales are `nl_NL`, `de_DE`, `fr_FR`, `es_ES`, `it_IT` and `pt_PT`.

Canonical operator entrypoints:

```bash
tools/i18n/update
tools/i18n/check
```

The implementation is the First-Party Starter i18n tooling v1.1.0. POT and PO files are reviewable source artifacts. MO files are release-generated for this standalone public plugin and are not committed as translation authority.

Do not use live machine translation or fill missing translations with English to satisfy the gate.

## Current content hold

Until `languages/core-blueprint-content-migrator.pot` and all six reviewed PO catalogs exist, `tools/i18n/check` and `tools/build-release` must fail closed. This is intentional.

## Release build

Run:

```bash
bash tools/build-release
```

The builder requires PHP 8.4+, Python 3, WP-CLI i18n, GNU gettext, Git, rsync, zip/unzip and sha256sum. It runs the canonical localization check, PHP lint and `tools/conformance.php` before staging customer-facing files.

The release ZIP uses the canonical root `core-blueprint-content-migrator/`, excludes developer-only directories and contains verified POT/PO sources plus freshly compiled MO catalogs for the exact six required locales.

A failed localization, conformance, syntax, package-boundary or checksum gate is a release blocker. Do not bypass it or patch the generated ZIP manually.
