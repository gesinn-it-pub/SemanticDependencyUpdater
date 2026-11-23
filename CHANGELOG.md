# Changelog

All notable changes to this project will be documented in this file.
This project adheres to [Semantic Versioning](https://semver.org/) and
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

### Added
- `$wgSDUSettlePasses` (default 2): number of consecutive empty re-parses that end
  an "Update Self" cycle; 1 saves one re-parse per cycle but can end a cycle too early
- `$wgSDUReloadPollIntervalMs` (default 250, between 100 and 10000): how often the
  browser asks whether an "Update Self" cycle is done
- `reload` field in the `sduselfupdatestatus` API response: whether the page may have changed

### Changed
- During a running "Update Self" cycle only the pass of a job that cycle queued counts as evidence that
  the cycle has settled (Semantic MediaWiki 7.4.0 or newer). Other store updates of the page - the refresh
  SMW runs after a purge, an update from another request, a job of an earlier cycle - can be empty
  without saying anything about the state the cycle waits for; they no longer end the cycle or use up an
  attempt
- Check the status of a pending "Update Self" reload every 250 ms instead of every
  second, so the page reloads sooner once the server is done

### Documentation
- Recommend `$wgSDUSettlePasses = 1` for Semantic MediaWiki 7.4.0 or newer with a single database, with the
  measurements behind it, and keep 2 for a replicated database or an older Semantic MediaWiki
- Describe `$wgSDUSettlePasses = 1` as a trade-off instead of a safe choice for a single database: in one
  measured setup it ended a cycle too early in about 5 % of the saves

### Fixed
- Give every save of a page the full attempt budget of a running "Update Self" cycle. Two saves in quick
  succession used to share one budget, and the cycle was ended by the limit on a pass that still changed
  data, before a pass had confirmed the result; the reload prompt was then missing for a moment
- Keep the reload prompt of a save until that save's own update has decided whether a reload is needed. A
  store update of the same page in another process (for example a job of an earlier cycle finishing just
  then) could take the prompt away, so the editor was told there was nothing to wait for while the page
  still showed the old values
- End or continue a running "Update Self" cycle when a pass changes nothing but ignored properties
  (usually the revision ID after two quick saves in a row) or when the page loses its dependency
  property. Until now the cycle stayed pending with an empty job queue, the reload prompt waited
  for a reload that never came, and the targets held back for it were released only by chance
- Let an "Update Self" cycle run to its end when one process works off all its jobs (`runJobs.php`
  without limits or with `--wait`). Until now a cycle needing more than two genuine passes was cut
  short there: it never ended and the dependency targets held back for it were never updated
- Stop a long-running job runner from refusing to propagate a page for good after that page had its
  third genuine change; the loop guard now only counts passes within one minute
- Show the reload prompt after saving a page that names itself as a dependency even when the
  browser asks for the page before SDU has run (typical for heavier pages, e.g. saved through a
  form). Until now the prompt was missing in that case and the editor kept seeing the old values
- Do not reload the page when SDU found nothing to update after the prompt was shown

## [5.1.2] - 2026-11-05

Fixes pages that stayed stale because they were created by the same save as their trigger.

### Fixed
- Update pages that a save creates itself (for example through a template) so they no longer keep stale values
  [`bf91211`](https://github.com/gesinn-it-pub/SemanticDependencyUpdater/commit/bf91211) ([#28](https://github.com/gesinn-it-pub/SemanticDependencyUpdater/issues/28))
- Refresh a display title again after a few minutes when it changes back and forth between the same values
  [`89625d6`](https://github.com/gesinn-it-pub/SemanticDependencyUpdater/commit/89625d6) ([#28](https://github.com/gesinn-it-pub/SemanticDependencyUpdater/issues/28))

## [5.1.1] - 2026-11-03

Fixes stale display titles on pages that SDU updates.

### Fixed
- Refresh `page_props.displaytitle` of pages whose display title depends on other
  pages, so consumers such as PageForms autocompletion and the DisplayTitle
  extension no longer show a stale title after SDU updated the page
  [`0dc75c0`](https://github.com/gesinn-it-pub/SemanticDependencyUpdater/commit/0dc75c0) ([#27](https://github.com/gesinn-it-pub/SemanticDependencyUpdater/issues/27))

### Changed
- Remove an unreachable "no semantic data" branch from the page deletion handler
  [`861a0d2`](https://github.com/gesinn-it-pub/SemanticDependencyUpdater/commit/861a0d2)
- Test against Semantic MediaWiki 7.4.0 instead of 7.3.1
  [`9ff15a9`](https://github.com/gesinn-it-pub/SemanticDependencyUpdater/commit/9ff15a9)
- Test against Semantic MediaWiki 7.3.1 instead of 7.2.0
  [`bb57e87`](https://github.com/gesinn-it-pub/SemanticDependencyUpdater/commit/bb57e87)
- Update the CI tooling so that test failures in the coverage job fail the
  build instead of being ignored
  [`850590f`](https://github.com/gesinn-it-pub/SemanticDependencyUpdater/commit/850590f)

### Documentation
- Document SDU's measured performance characteristics and how to benchmark a
  change before and after, including a benchmark harness in `tools/perf`
  [`b0e9450`](https://github.com/gesinn-it-pub/SemanticDependencyUpdater/commit/b0e9450)

## [5.1.0] - 2026-10-03

Adds a reload prompt for self-referencing "Update Self" pages and fixes several
self-update cycle issues, including an endless reload loop with ApprovedRevs.

### Added
- Pages that reference themselves now show a spinner after saving and reload
  once, as soon as their self-update has actually finished, so the editor sees
  the final values [`efd4bd3`](https://github.com/gesinn-it-pub/SemanticDependencyUpdater/commit/efd4bd3), [`5486801`](https://github.com/gesinn-it-pub/SemanticDependencyUpdater/commit/5486801)

### Fixed
- Fix an endless reload loop after saving a page that ApprovedRevs shows at an
  older revision; the page now reloads at most once per revision
  [`3a16510`](https://github.com/gesinn-it-pub/SemanticDependencyUpdater/commit/3a16510) ([#26](https://github.com/gesinn-it-pub/SemanticDependencyUpdater/issues/26))
- Update other pages listed in "Semantic Dependency" only after a
  self-referencing page's own update has finished, so they no longer read
  stale data [`0f9cde5`](https://github.com/gesinn-it-pub/SemanticDependencyUpdater/commit/0f9cde5)
- Fix the reload prompt timing out instead of reloading on self-referencing
  pages that contain queries [`faa9819`](https://github.com/gesinn-it-pub/SemanticDependencyUpdater/commit/faa9819)
- Stop a self-update early once the derived value has stabilised instead of
  always using all retries [`5486801`](https://github.com/gesinn-it-pub/SemanticDependencyUpdater/commit/5486801)
- Limit the number of self-update retries when the page keeps changing, which
  could previously re-queue indefinitely [`efd4bd3`](https://github.com/gesinn-it-pub/SemanticDependencyUpdater/commit/efd4bd3)

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

[Unreleased]: https://github.com/gesinn-it-pub/SemanticDependencyUpdater/compare/5.1.2...HEAD
[5.1.2]: https://github.com/gesinn-it-pub/SemanticDependencyUpdater/compare/5.1.1...5.1.2
[5.1.1]: https://github.com/gesinn-it-pub/SemanticDependencyUpdater/compare/5.1.0...5.1.1
[5.1.0]: https://github.com/gesinn-it-pub/SemanticDependencyUpdater/compare/5.0.2...5.1.0
[5.0.2]: https://github.com/gesinn-it-pub/SemanticDependencyUpdater/compare/5.0.1...5.0.2
[5.0.1]: https://github.com/gesinn-it-pub/SemanticDependencyUpdater/compare/5.0.0...5.0.1
[5.0.0]: https://github.com/gesinn-it-pub/SemanticDependencyUpdater/releases/tag/5.0.0
