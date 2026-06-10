# NativeAnalyticsBehavior

Behavioral analytics companion for the [NativeAnalytics](https://github.com/adrianbj/NativeAnalytics) ProcessWire module. It reuses NativeAnalytics' visitor/session identity and consent gating, but stores its own data and ships its own dashboard.

## Phase 1 (this release): Heatmaps
- Client collector captures click positions (no page text) and scroll depth.
- Self-contained ingest endpoint at `/nab-collect`.
- Click + scroll-depth heatmaps per page and device, in **Setup > Behavior**.
- Configurable sampling rate, retention window, and path/template/role/IP exclusions (superusers are always excluded).
- Daily retention purge via LazyCron.

Planned later phases: behavior insights (rage/dead clicks, quick-backs, JS errors), then full session recordings (rrweb), then segments + funnels.

## Privacy
The collector stores **no page text**: click targets are recorded as CSS selectors only, and visitor/session IDs are stored as salted SHA-256 hashes. Use `NativeAnalyticsBehavior::eraseVisitor($rawId)` to satisfy data-subject erasure requests.

## Install
1. Copy this folder to `/site/modules/NativeAnalyticsBehavior/`.
2. Modules > Refresh.
3. Install **NativeAnalyticsBehavior**, then **NativeAnalyticsBehavior Dashboard**.
4. Configure under Modules > Configure > NativeAnalyticsBehavior.
5. Ensure LazyCron is installed (it is a dependency) so retention purge runs.

## Requirements
ProcessWire >= 3.0.173, PHP >= 7.4, NativeAnalytics, LazyCron.
