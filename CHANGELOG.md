# Changelog

All notable changes to this project will be documented in this file.
This project adheres to [Semantic Versioning](https://semver.org/) and
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

### Added
- Client-side reload prompt for self-referencing "Update Self" pages: a new
  `ext.sdu.reload` module shows a spinner and polls a new read-only
  `sduselfupdatestatus` API (`SDU\Api\ApiSduSelfUpdateStatus`, backed by
  `SDU\Hooks::isSelfUpdateReloadPending()`) until the server's self-update
  cycle for the exact saved revision has genuinely ended, then reloads once -
  SMW's own `.smw-postproc` prompt never fires for this case, because SDU's
  own forced self-`UpdateJob` overwrites SMW's `ChangeDiff` cache slot with
  an empty diff before the browser can re-request the page
  [`efd4bd3`](https://github.com/gesinn-it-pub/SemanticDependencyUpdater/commit/efd4bd3),
  [`5486801`](https://github.com/gesinn-it-pub/SemanticDependencyUpdater/commit/5486801)

### Fixed
- Hold back remote "Semantic Dependency" `UpdateJob`s until a self-referencing
  page's own self-update cycle has genuinely ended, instead of pushing both
  together into the same (randomly-ordered) job queue - a remote dependency
  could previously have its forced re-parse run before self's own cycle
  finished, reading stale self data
  [`0f9cde5`](https://github.com/gesinn-it-pub/SemanticDependencyUpdater/commit/0f9cde5)
- Filter SMW's own `_ASK*` query-management bookkeeping diffs
  (`smw_fpt_ask*`) from the self-update diff scan, the same way
  `smw_fpt_mdat` already was - left unfiltered, a query-bookkeeping-only diff
  on a self-referencing page (caused by an unrelated remote `UpdateJob`
  re-parsing it as a side effect) silently left the reload-pending marker to
  expire on its own TTL instead of resolving cleanly
  [`faa9819`](https://github.com/gesinn-it-pub/SemanticDependencyUpdater/commit/faa9819)
- End a self-update cycle early after two consecutive empty diffs
  (`MAX_CONSECUTIVE_EMPTY_DIFFS`) instead of always exhausting the full
  `SELF_UPDATE_MAX_ATTEMPTS` retry budget regardless of whether the derived
  value had already stabilized
  [`5486801`](https://github.com/gesinn-it-pub/SemanticDependencyUpdater/commit/5486801)
- Enforce `SELF_UPDATE_MAX_ATTEMPTS` on the real (non-empty) diff path, not
  only the empty-diff retry path - a self-referencing page with several
  genuine diff passes in a row could previously re-queue itself indefinitely
  [`efd4bd3`](https://github.com/gesinn-it-pub/SemanticDependencyUpdater/commit/efd4bd3)

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
