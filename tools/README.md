# Release tooling

## Conformance

```bash
php tools/conformance.php
```

Checks the required Core Blueprint Base/API contract, required runtime files, migration safety primitives, public Base integration boundaries and the Golden safety regression.

## Build

```bash
bash tools/build-release
```

Requirements: `php`, `rsync`, `zip`.

The builder recreates `build/`, stages the plugin under the canonical `core-blueprint-content-migrator/` folder, lints staged PHP, runs conformance and creates `build/core-blueprint-content-migrator-<version>.zip`.

A lint, Base-contract, Golden safety or conformance failure stops the build before packaging.
