# NativeAnalyticsBehavior

Behavioral analytics companion for the [NativeAnalytics](https://github.com/adrianbj/NativeAnalytics) ProcessWire module. It reuses NativeAnalytics' visitor/session identity and consent gating, but stores its own data and ships its own dashboard under **Setup > Behavior**.

## Features
- **Heatmaps** — click and scroll-depth heatmaps per page and device. Clicks are anchored to the element that was clicked (selector + intra-element offset), so blobs stay put even when the rebuilt layout shifts.
- **Frustration signals** — rage clicks (rapid repeated clicks in one spot), dead clicks (clicks on non-interactive elements), and copy events, each surfaced per element.
- **Single-session trail viewer** — replay one visitor's cross-page journey over a rebuilt, masked snapshot of each page, with click/copy pins placed in time order and per-page scroll depth.
- **Versioned page snapshots** — the collector captures a masked DOM snapshot (via rrweb-snapshot) once per session per page. The server stores a new version only when the markup actually changes (content-hash dedup), and the trail viewer shows the version that was live during each session's visit.
- **Bot exclusion** — sessions NativeAnalytics flagged as bots can be hidden.
- **Configurable** sampling rate, retention window, and path/template/role/IP exclusions (superusers are always excluded).
- **Daily retention purge** via LazyCron.

## Privacy
The collector stores **no page text**: click targets are recorded as CSS selectors only, and visitor/session IDs are stored as salted SHA-256 hashes.

Snapshots are masked at capture: all input values are masked, `[data-na-mask]` regions have their text redacted, and `[data-na-block]` regions are blocked entirely. Captured `<script>` elements are stripped, and the trail viewer rebuilds snapshots into a sandboxed iframe that runs no scripts.

Use `NativeAnalyticsBehavior::eraseVisitor($rawId)` to satisfy data-subject erasure requests.

## Install
1. Copy this folder to `/site/modules/NativeAnalyticsBehavior/`.
2. Modules > Refresh.
3. Install **NativeAnalyticsBehavior**, then **NativeAnalyticsBehavior Dashboard**.
4. Configure under Modules > Configure > NativeAnalyticsBehavior.
5. Ensure LazyCron is installed (it is a dependency) so retention purge runs.

## Requirements
ProcessWire >= 3.0.173, PHP >= 7.4, NativeAnalytics, LazyCron.
