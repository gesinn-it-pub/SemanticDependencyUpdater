# Performance

This document records how SDU's performance was measured, what was found, and
how to repeat the measurement before and after a change. The numbers are tied
to one commit and one environment; re-measure before relying on them.

## Summary

SDU's own overhead is small. The cost of a save cycle comes from the feature
itself (every dependent page is re-parsed) and from Semantic MediaWiki's own
queries, not from SDU's bookkeeping. No code-level optimization was found
that is worth the risk of touching the forced-update and purge logic.

Measured on commit `40e2cf6` (5.1.0), MediaWiki 1.43, SMW 7.2.0, PHP 8.2,
MariaDB 11.2, default `SqlBagOStuff` stash, one self-referencing page with 35
remote dependents:

| What | Result | SDU's share |
|---|---|---|
| Page view (plain page) | 214 ms, 166 DB queries | 1 query (the reload-marker lookup), about 0.3 % of the time |
| Save cycle, duration | about 6.5 s | 3 self passes, 35 dependent re-parses |
| Save cycle, DB queries | about 5700 | about 30 are SDU marker operations (0.5 %) |
| Browser, per save | 2 purges, 2-3 status polls, 2 parsed views | |
| Hook on a page without the SDU property | 47 µs, 0 queries | |
| Ignored-property ID resolution | 61 µs, 0 queries | |
| Page deletion, loading semantic data | 4.9 ms / 4 queries (full) vs 1.0 ms / 1 query (SDU property only) | |

Run-to-run noise: counts are exactly reproducible; timings vary by 1-3 %
(browser timings by up to about 9 %).

## Findings worth knowing before "optimizing"

- **Reload-marker lookup on every view** (`onOutputPageParserOutput`): one
  stash read per view. Measurable, but about 0.3 % of a view. With a
  non-database stash it is cheaper still.
- **Initial purge in `ext.sdu.reload.js`**: it costs one API request. It does
  not cause an extra render, because a render only happens on the next view,
  which comes after the final purge.
- **Duplicate update jobs**: not a concern, SMW's `UpdateJob` sets
  `removeDuplicates`, so the queue removes them.
- **Settle logic** (`MAX_CONSECUTIVE_EMPTY_DIFFS`, retry delays): this is where
  most of the self-update latency comes from (one real pass plus two empty
  confirmation passes). It is also the logic that guarantees the final value
  is correct, so it must not be shortened without the integration tests and a
  before/after run of the harness below.

## Measuring

The harness lives in `tools/perf/` and runs against the project's own test
wiki (`make install`, which provides a MediaWiki container and a database
container). Container names can be overridden with `SDU_WIKI` and `SDU_DB`.

```
tools/perf/perf.sh setup                 # copy the current working tree into the test wiki
tools/perf/perf.sh view   <label> [N]    # N anonymous page views
tools/perf/perf.sh micro  <label>        # per-call cost of the view hook
tools/perf/perf.sh save   <label> [runs] # server-side save cycle, no browser
tools/perf/perf.sh client <label> [runs] # real browser edit incl. reload mechanism
tools/perf/perf.sh compare <labelA> <labelB>
```

Before the first run, install `tools/perf/perf-include.php` into the wiki
(it is `require`d from `LocalSettings.php`) and create the fixture with
`tools/perf/fixture.php`; the `client` scenario additionally needs Playwright
and Chromium inside the wiki container. Results are written to
`tools/perf/results/<label>/` (not tracked).

What is captured:

- **DB queries** by category (total, select, write, object cache, SDU's own
  cache keys, job queue, SMW tables, page/revision), from the database
  general log.
- **Parser invocations** and **web requests** by kind (view, API purge, API
  status poll), with total server time.
- **Jobs executed** by type and **wall time** until the job queue is empty.
- In the browser scenario: time from save to the end of the client-side
  reload mechanism, number of navigations, purges and status polls.

Debug logging is switched off during measurements, because it changes SDU's
own cost.

### Comparing a change

1. Run the scenarios on the unchanged code with a label such as `baseline`
   (and once more as `baseline-b` to see the noise).
2. Apply the change, run `perf.sh setup`, repeat the scenarios with a new label.
3. `perf.sh compare baseline <label>`.

Counts (queries, parses, jobs, requests) are deterministic, so any difference
in them is real. Treat timing differences below the noise band as no change.
A change that touches forced updates, cache invalidation or purges must in
addition keep the integration tests passing, and the browser scenario must
still end with the reload mechanism finishing cleanly.

## Scope of the measurements

- The browser scenario reports when the reload mechanism ends. The remote
  dependents are released only after the self-update cycle ends, so they can
  still be updating at that moment; the server-side scenario measures until the
  whole queue is empty.
- A single fixture was used. A page with many more dependents or very heavy
  queries will shift the proportions, though not the fact that the re-parses
  are the dominant cost.
