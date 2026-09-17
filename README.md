# NativeAnalyticsBehavior

Behavioral analytics companion for the [NativeAnalytics](https://github.com/adrianbj/NativeAnalytics) ProcessWire module. It reuses NativeAnalytics' visitor/session identity and consent gating, stores its own data, and surfaces its reports as a **Behavior** tab inside the NativeAnalytics dashboard.

## Features
- **Heatmaps** — click and scroll-depth heatmaps per page and device. Clicks are anchored to the element that was clicked (selector + intra-element offset), so blobs stay put even when the rebuilt layout shifts.
- **Frustration signals** — rage clicks (rapid repeated clicks in one spot), dead clicks (clicks on non-interactive elements), and copy events, each surfaced per element.
- **Site-search terms** — the terms NativeAnalytics records from results-page URLs (its `searchQueryVars` setting) are surfaced in the behavior tables, per page and per session. Nothing extra is collected.
- **Single-session trail viewer** — replay one visitor's cross-page journey over a rebuilt, masked snapshot of each page, with click/copy pins placed in time order and per-page scroll depth.
- **Versioned page snapshots** — the collector captures a masked DOM snapshot (via rrweb-snapshot) once per session per page. The server stores a new version only when the markup actually changes (content-hash dedup), and the trail viewer shows the version that was live during each session's visit.
- **Bot exclusion** — sessions NativeAnalytics flagged as bots can be hidden.
- **Configurable** sampling rate, retention window, and path/template/role/IP exclusions (superusers are always excluded).
- **Daily retention purge**, batched, via LazyCron or your own cron job (see Maintenance).

## Privacy
The collector stores **no page text**: click targets are recorded as CSS selectors only, and visitor/session IDs are stored as salted SHA-256 hashes.

Snapshots are masked at capture: all input values are masked, `[data-na-mask]` regions have their text redacted, and `[data-na-block]` regions are blocked entirely. Captured `<script>` elements are stripped, and the trail viewer rebuilds snapshots into a sandboxed iframe that runs no scripts.

Use `NativeAnalyticsBehavior::eraseVisitor($rawId)` to satisfy data-subject erasure requests.

## Install
1. Copy this folder to `/site/modules/NativeAnalyticsBehavior/`.
2. Modules > Refresh.
3. Install **NativeAnalyticsBehavior**, then **NativeAnalyticsBehavior Dashboard**.
4. Configure under Modules > Configure > NativeAnalyticsBehavior.
5. Ensure LazyCron is installed (it is a dependency) so retention purge runs, or set up a cron job instead (see Maintenance).

## Maintenance
The retention purge deletes events and snapshot versions older than the retention window, in batches. The newest snapshot version of every page/device bucket is always kept so a page that has not changed still has a heatmap backdrop.

It also trims every bucket to its newest **Snapshot versions kept per page** (default 5), whatever their age. A session resolves to the version live at its time, or the earliest later one, so a few versions per bucket serve the whole event window.

## Snapshots
The collector serializes the page once per session per page/device bucket, canonicalizes it (scripts removed, node ids renumbered, CSP nonces and `data-csrf-*` attributes dropped), and hashes an identity form of the tree that ignores rrweb's scroll and size attributes. It sends only the hash first; the server answers whether it wants the body, so the multi-hundred-KB upload only happens for a page it has not stored. A new version is stored when the hash is new for the bucket and no version was stored within **Minimum hours between new snapshot versions** (default 24).

Mark an element `data-na-volatile` when its content legitimately differs between visitors of the same page (a shuffled list, a video that swaps its placeholder for an iframe once played). It stays in the stored snapshot and the backdrop, but counts by its tag alone for identity, so it does not make two captures of the same page look like different pages. `data-na-block` still replaces an element with a sized placeholder, and `data-na-mask` still masks text.

By default the purge runs from LazyCron, which means it runs inside whichever visitor request happens to cross the day boundary and holds that visitor's PHP worker and session lock until it finishes. On a busy site, uncheck **Run the retention purge from LazyCron** in the module config and call the purge from a real cron job instead, for example from a ProcessWire-bootstrapped CLI script:

```php
$result = $modules->get('NativeAnalyticsBehavior')->purgeExpired(2000, 600); // batch size, time limit in seconds
```

`purgeExpired()` returns the rows deleted per table and whether it finished; a run that hits the time limit resumes on the next call. `countExpired()` reports what a run would delete.

The schema check in `init()` runs once per `SCHEMA_VERSION` and is then skipped, so a request does no schema queries in normal operation. Bump `SCHEMA_VERSION` when changing `ensureSchema()`.

## Requirements
ProcessWire >= 3.0.173, PHP >= 7.4, NativeAnalytics, LazyCron.
