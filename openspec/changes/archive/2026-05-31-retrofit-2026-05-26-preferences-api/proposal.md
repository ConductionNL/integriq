# retrofit-2026-05-26-preferences-api

## Why

This app exposes a generic per-user preferences endpoint (read/write a small key/value flag, backed by Nextcloud `IConfig` user values) consumed by shared `@conduction/nextcloud-vue` widgets that need to persist a cross-device UI flag without a bespoke endpoint per feature. This is a real backend capability lacking a written spec; this change reverse-specs it.

## What Changes

- Document the get/set preference endpoints (authentication, key sanitization to a `pref_` namespace, empty-value-clears semantics).
- No code changes — annotation-only retrofit.

## Impact

- **Affected specs**: new capability `preferences-api`
- **Affected code**: `lib/Controller/PreferencesController.php` (docblock `@spec` annotations only)
- **Risk**: none — comment-only.
