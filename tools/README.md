# Core Blueprint Content Migrator release tooling

Content Migrator is a first-party Core Blueprint extension. Core Blueprint Base is required through `Requires Plugins: core-blueprint`, plus the versioned Base/Core API runtime contract. Keep the dependency on public Base contracts only.

## Canonical localization

English source is authoritative. The required reviewed locales are:

- `nl_NL`
- `de_DE`
- `fr_FR`
- `es_ES`
- `it_IT`
- `pt_PT`

Canonical operator entrypoints:

```bash
tools/i18n/update
tools/i18n/check
```

They use First-Party Starter i18n tooling v1.1.0. POT and PO files are reviewable source artifacts. `commit_mo:false` keeps MO files out of source authority; the releasebuilder compiles them fresh inside isolated staging.

Do not use live machine translation, placeholder catalogs or English copies to satisfy localization gates.

## Current content hold

Until `languages/core-blueprint-content-migrator.pot` and all six reviewed PO catalogs exist, both canonical i18n validation and the release build must fail closed. This is intentional and is not a tooling defect.

## Customer release build

Run from the repository root:

```bash
bash tools/build-release
```

The builder validates:

- plugin/readme release identity for `1.0.0-rc1`, WordPress 7.0+ and PHP 8.4+;
- the required native `Requires Plugins: core-blueprint` dependency;
- matching text-domain and runtime version identity;
- a clean release-source tree outside `build/`;
- canonical `tools/i18n/check` against current source;
- source PHP syntax, Golden safety regression and product conformance;
- canonical POT plus complete reviewed PO coverage for all six locales;
- fresh staged MO compilation with gettext format/header validation;
- staged PHP syntax;
- deterministic ZIP contents under exactly `core-blueprint-content-migrator/`;
- absence of development-only paths;
- packaged plugin/readme identity after archive acceptance.

Only after all acceptance gates pass does the builder write the SHA-256 checksum.

Output:

```text
build/core-blueprint-content-migrator-1.0.0-rc1.zip
build/core-blueprint-content-migrator-1.0.0-rc1.zip.sha256
```

## Customer runtime boundary

The package allowlist is:

- `core-blueprint-content-migrator.php`
- `uninstall.php`
- `readme.txt`
- `LICENSE`
- `src/`
- canonical POT and reviewed PO files in `languages/`
- freshly compiled MO files in `languages/`

Repository README/changelog material, tests, tools, CI metadata and other development-only content are not customer runtime files.

A failed localization, Base-contract, Golden safety, conformance, syntax, package-boundary or checksum gate is a release blocker. Do not bypass the gate or patch the generated ZIP manually.
