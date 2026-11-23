# Changelog

All notable changes to this project will be documented in this file.
This project adheres to [Semantic Versioning](https://semver.org/) and
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

### Fixed
- Find pages whose name contains `&`, `'`, `"`, `=` or `;` when they are named in the dependency property, for example through `{{FULLPAGENAME}}`. The character references that `{{FULLPAGENAME}}` writes for these characters (such as `&#38;`) were cut at their own `;` or not decoded, so the query matched no page and no "Update Self" cycle ran
- Do not split page names that contain `AND` or `OR` as part of a word (such as `ORDER`, `BRAND`) or as a separate word when the page exists, into query operators
- Keep a "Property::Value1;Value2" list working with the list separator, also with these characters in the names

### Known limitations
- A page that does not exist yet and is not the page being saved is read as a list of values when its name contains ` OR ` or ` AND ` as a separate word (also a raw `;`, as typed or read from the store). A dependency naming it does not update that page: with ` OR ` it updates the pages named by its parts, with ` AND ` the result is whatever the conjunction query returns. Avoid ` OR `, ` AND ` and `;` in page names. In a property condition (`Property::A OR B`) ` OR ` / ` AND ` is always an operator, also for an existing page. See "Page names with special characters" in the README

## [5.2.0] - 2026-11-23

Makes the reload after saving a self-referencing ("Update Self") page reliable on larger pages and with job runners that work off many jobs per process, and makes it faster and tunable.

### Added
- Add `$wgSDUSettlePasses` (default 2): how many empty re-parses end an "Update Self" cycle. 1 saves one re-parse per cycle (about 0.7 to 0.9 s in a measured setup) and is recommended with Semantic MediaWiki 7.4.0 or newer and a single database
  [`5cc0a73`](https://github.com/gesinn-it-pub/SemanticDependencyUpdater/commit/5cc0a73)
- Add `$wgSDUReloadPollIntervalMs` (default 250, between 100 and 10000): how often the browser asks whether an "Update Self" cycle is done
  [`5cc0a73`](https://github.com/gesinn-it-pub/SemanticDependencyUpdater/commit/5cc0a73)
- Add a `reload` field to the `sduselfupdatestatus` API response: whether the page may have changed
  [`8d4ed96`](https://github.com/gesinn-it-pub/SemanticDependencyUpdater/commit/8d4ed96)

### Changed
- Check every 250 ms instead of every second whether an "Update Self" cycle is done, so the page reloads sooner
  [`5cc0a73`](https://github.com/gesinn-it-pub/SemanticDependencyUpdater/commit/5cc0a73)
- Count only the pass of a job that the cycle queued itself as evidence that it has settled (Semantic MediaWiki 7.4.0 or newer). Other store updates of the page, such as the refresh after a purge, no longer end a cycle too early
  [`e6d924a`](https://github.com/gesinn-it-pub/SemanticDependencyUpdater/commit/e6d924a)

### Fixed
- Show the reload prompt after saving a self-referencing page even when the browser asks for the page before SDU has run, typical for larger pages. Until now the prompt was missing and the editor kept seeing the old values
  [`8d4ed96`](https://github.com/gesinn-it-pub/SemanticDependencyUpdater/commit/8d4ed96)
- Do not reload the page when SDU found nothing to update after the prompt was shown
  [`8d4ed96`](https://github.com/gesinn-it-pub/SemanticDependencyUpdater/commit/8d4ed96)
- Keep the reload prompt of a save until that save's own update has decided whether a reload is needed, so an update of the same page in another process can no longer take it away
  [`d53eeda`](https://github.com/gesinn-it-pub/SemanticDependencyUpdater/commit/d53eeda)
- Let an "Update Self" cycle run to its end when one process works off many jobs (`runJobs.php` without limits or with `--wait`). Until now such a cycle was cut short, never ended, and the targets held back for it were never updated. A long-running job runner also no longer stops propagating a page for good after its third change
  [`f7accbf`](https://github.com/gesinn-it-pub/SemanticDependencyUpdater/commit/f7accbf)
- End or continue a running "Update Self" cycle when a pass changes only ignored properties or the page loses its dependency property, instead of leaving it pending with an empty job queue
  [`a3e946c`](https://github.com/gesinn-it-pub/SemanticDependencyUpdater/commit/a3e946c)
- Give every save the full attempt budget of a running "Update Self" cycle, so two saves in quick succession no longer end it before a pass has confirmed the result
  [`38c6239`](https://github.com/gesinn-it-pub/SemanticDependencyUpdater/commit/38c6239)

### Documentation
- Describe `$wgSDUSettlePasses = 1` as a trade-off with the measurements behind it and recommend it for Semantic MediaWiki 7.4.0 or newer with a single database; keep 2 for a replicated database or an older Semantic MediaWiki
  [`6f02329`](https://github.com/gesinn-it-pub/SemanticDependencyUpdater/commit/6f02329), [`9a51970`](https://github.com/gesinn-it-pub/SemanticDependencyUpdater/commit/9a51970), [`f99890a`](https://github.com/gesinn-it-pub/SemanticDependencyUpdater/commit/f99890a)

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

[Unreleased]: https://github.com/gesinn-it-pub/SemanticDependencyUpdater/compare/5.2.0...HEAD
[5.2.0]: https://github.com/gesinn-it-pub/SemanticDependencyUpdater/compare/5.1.2...5.2.0
[5.1.2]: https://github.com/gesinn-it-pub/SemanticDependencyUpdater/compare/5.1.1...5.1.2
[5.1.1]: https://github.com/gesinn-it-pub/SemanticDependencyUpdater/compare/5.1.0...5.1.1
[5.1.0]: https://github.com/gesinn-it-pub/SemanticDependencyUpdater/compare/5.0.2...5.1.0
[5.0.2]: https://github.com/gesinn-it-pub/SemanticDependencyUpdater/compare/5.0.1...5.0.2
[5.0.1]: https://github.com/gesinn-it-pub/SemanticDependencyUpdater/compare/5.0.0...5.0.1
[5.0.0]: https://github.com/gesinn-it-pub/SemanticDependencyUpdater/releases/tag/5.0.0
