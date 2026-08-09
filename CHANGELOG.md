# Changelog

All notable changes to this project will be documented in this file.
This project adheres to [Semantic Versioning](https://semver.org/) and
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

## [5.0.2] - 2026-08-10

### Fixed
- Don't crash `onAfterDataUpdateComplete()` on the default
  `$wgSDUIgnoredProperties` value (`___REVID`) when SemanticExtraSpecial-
  Properties is not installed - contrary to what `extension.json`'s config
  description claimed, this silently broke SDU entirely on any such
  installation
  [`f631775`](https://github.com/gesinn-it-pub/SemanticDependencyUpdater/commit/f631775)

### Tests
- Harden `SduIntegrationTestCase` against stale SMW test-process caches
  (`CachingSemanticDataLookup`, `StoreFactory`, `PropertyRegistry`) that
  intermittently caused property type declarations to appear invisible to
  later test methods in the same PHPUnit process
  [`4c87b14`](https://github.com/gesinn-it-pub/SemanticDependencyUpdater/commit/4c87b14),
  [`b995395`](https://github.com/gesinn-it-pub/SemanticDependencyUpdater/commit/b995395)

## [5.0.1] - 2026-08-09

Hardens the "Update Self" self-update-pending mechanism against several
interacting edge cases found during a systematic audit, and fixes
deletion-triggered dependency rebuilds, which were never actually wired up.

### Fixed
- Register the page-deletion dependency rebuild hook (`ArticleDelete`, not
  `PageDeleteComplete`, for correct CLI/job-queue timing) - it was never
  wired up, so deletion-triggered dependency rebuilds were completely dead
  [`4a1de4f`](https://github.com/gesinn-it-pub/SemanticDependencyUpdater/commit/4a1de4f)
- Guard the self-update-pending marker against being wiped or falsely
  advanced by ignored-only property changes, and against being falsely set
  on ordinary (non-self-referencing) pages
  [`4a1de4f`](https://github.com/gesinn-it-pub/SemanticDependencyUpdater/commit/4a1de4f)
- Preserve (rather than reset) the self-update-pending marker's attempt
  count across consecutive genuine changes on a still-self-referencing
  page, so it is actually bounded across job-queue-runner process
  restarts [`4a1de4f`](https://github.com/gesinn-it-pub/SemanticDependencyUpdater/commit/4a1de4f)
- Remove the ineffective `$wgSDUUseJobQueue` config option - disabling it
  made the extension a complete no-op with no working synchronous
  fallback [`4a1de4f`](https://github.com/gesinn-it-pub/SemanticDependencyUpdater/commit/4a1de4f)
- Make "Update Self" actually work as documented, with a store-timing
  retry, and resolve ignored-property exclusion (e.g. SESP's `___REVID`)
  via configurable property names instead of a hardcoded, incorrect
  property ID [`659ba4b`](https://github.com/gesinn-it-pub/SemanticDependencyUpdater/commit/659ba4b)

### Changed
- Replace PHPStan/Psalm with Phan for static analysis
  [`c4868d1`](https://github.com/gesinn-it-pub/SemanticDependencyUpdater/commit/c4868d1)

[Unreleased]: https://github.com/gesinn-it-pub/SemanticDependencyUpdater/compare/5.0.2...HEAD
[5.0.2]: https://github.com/gesinn-it-pub/SemanticDependencyUpdater/compare/5.0.1...5.0.2
[5.0.1]: https://github.com/gesinn-it-pub/SemanticDependencyUpdater/compare/5.0.0...5.0.1
[5.0.0]: https://github.com/gesinn-it-pub/SemanticDependencyUpdater/releases/tag/5.0.0
