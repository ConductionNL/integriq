# Changelog

## [Unreleased]
### Added
- Pre-flight `storage_migrated` assertion in `Application::register()`: the app now
  fails fast with a `\LogicException` (naming the `occ openconnector:migrate-storage`
  runbook command) when the legacy→OpenRegister storage migration has not run.
  Bypassable in CI/test via `OPENCONNECTOR_SKIP_STORAGE_MIGRATED_ASSERT=1`.
  (openconnector-services-direct-or-usage, Task 1)

## 0.1.7 – 2024-09-19
### Added
- New features for this release

### Changed
- Changes in existing functionality for this release

### Fixed
- Bug fixes for this release

## 0.1.6 – 2024-09-07
### Added
- New features for this release

### Changed
- Changes in existing functionality for this release

### Fixed
- Bug fixes for this release

### Added
- Initial release
