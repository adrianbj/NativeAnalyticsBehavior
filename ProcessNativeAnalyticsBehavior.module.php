<?php namespace ProcessWire;

class ProcessNativeAnalyticsBehavior extends Process {

    public static function getModuleInfo() {
        return [
            'title' => 'NativeAnalyticsBehavior Dashboard',
            'summary' => 'Dashboard for NativeAnalyticsBehavior (heatmaps).',
            'version' => 1,
            'author' => 'Adrian Jones',
            'permission' => 'nativeanalyticsbehavior-view',
            'permissions' => ['nativeanalyticsbehavior-view' => 'View NativeAnalyticsBehavior dashboard'],
            'icon' => 'fire',
            'requires' => ['NativeAnalyticsBehavior'],
            'page' => ['name' => 'behavior-analytics', 'parent' => 'setup', 'title' => 'Behavior'],
        ];
    }

    /** @var NativeAnalyticsBehavior */
    protected $core;

    public function init() {
        parent::init();
        $this->core = $this->wire('modules')->get('NativeAnalyticsBehavior');
        $this->addAssets();
    }

    // Add the backdrop stylesheet and rrweb rebuild library to the page head.
    // Called from init() for the standalone page and again from
    // renderTabContent() for the embedded-tab case (config arrays dedupe by
    // URL, so a double call is harmless).
    protected function addAssets() {
        if(!$this->core) $this->core = $this->wire('modules')->get('NativeAnalyticsBehavior');
        // Load NativeAnalytics' dashboard CSS first so the shared pwna-* toolbar
        // and panel styling is present on our standalone page too. When this view
        // is embedded as a tab in the NativeAnalytics dashboard that stylesheet is
        // already on the page; styles->add dedupes by URL, so the extra call is a
        // no-op there. Our own admin.css layers the backdrop/heatmap styles on top.
        $na = $this->wire('modules')->get('NativeAnalytics');
        if($na) {
            $this->wire('config')->styles->add($na->getAssetUrl('assets/admin.css') . '?v=' . rawurlencode($na->getAssetVersion('assets/admin.css')));
        }
        $this->wire('config')->styles->add($this->core->getVersionedAssetUrl('assets/admin.css'));
    }

    public function ___execute() {
        return $this->renderTabContent();
    }

    // AJAX: tracked paths matching ?q=, as JSON [{path, c}]. Reached at the
    // process page's path-search/ URL segment; backs the dashboard's path
    // autocomplete. Page permission (nativeanalyticsbehavior-view) gates access.
    public function ___executePathSearch() {
        if(!$this->core) $this->core = $this->wire('modules')->get('NativeAnalyticsBehavior');
        $term = $this->wire('sanitizer')->text($this->wire('input')->get('q'));
        $this->sendJsonResponse($this->core->searchTrackedPaths($term, 20));
    }

    // AJAX: sessions that visited ?path within ?from..?to, as JSON. Backs the
    // "Sessions on this page" panel. Page permission gates access.
    public function ___executeSessionList() {
        if(!$this->core) $this->core = $this->wire('modules')->get('NativeAnalyticsBehavior');
        $input = $this->wire('input');
        $sanitizer = $this->wire('sanitizer');
        $path = $sanitizer->text($input->get('path'));
        if($path === '') $path = '/';
        $from = $sanitizer->date($input->get('from'), 'Y-m-d') ?: date('Y-m-d', strtotime('-29 days'));
        $to = $sanitizer->date($input->get('to'), 'Y-m-d') ?: date('Y-m-d');
        $rows = $this->core->getSessionsForPath($path, $from, $to, 50);
        $total = $this->core->countSessionsForPath($path, $from, $to);
        $sessions = [];
        foreach($rows as $r) {
            $h = (string) $r['session_hash'];
            $r['hash_short'] = $h !== '' ? substr($h, 0, 8) : '';
            $sessions[] = $r;
        }
        $this->sendJsonResponse(['sessions' => $sessions, 'total' => $total, 'showing' => count($sessions)]);
    }

    // AJAX: one session's full cross-page journey with per-page interactions, as
    // JSON. ?session must be a 64-char hex hash (400 otherwise).
    public function ___executeSessionJourney() {
        if(!$this->core) $this->core = $this->wire('modules')->get('NativeAnalyticsBehavior');
        $session = (string) $this->wire('input')->get('session');
        if(!preg_match('/^[a-f0-9]{64}$/', $session)) {
            http_response_code(400);
            $this->sendJsonResponse(['error' => 'invalid session']);
        }
        $journey = $this->core->getSessionJourney($session);
        if($journey === null) {
            $this->sendJsonResponse(['error' => 'not found', 'pages' => []]);
        }
        $device = (string) $journey['device'];
        foreach($journey['pages'] as &$p) {
            $rows = $this->core->getSessionInteractions($session, $p['path_hash'], $device, 200);
            $p['interactions'] = $rows;
            $p['more'] = max(0, (int) $p['interaction_count'] - count($rows));
        }
        unset($p);
        $this->sendJsonResponse($journey);
    }

    // AJAX: the stored masked backdrop for ?path + ?device, as JSON. `dom` is the
    // snapshot JSON as a string (the client JSON.parses it, mirroring how
    // heatmap.js reads the inline #nab-snapshot block). {none:true} when absent.
    public function ___executeSnapshot() {
        if(!$this->core) $this->core = $this->wire('modules')->get('NativeAnalyticsBehavior');
        $input = $this->wire('input');
        $sanitizer = $this->wire('sanitizer');
        $path = $sanitizer->text($input->get('path'));
        $device = $sanitizer->option($input->get('device'), ['desktop', 'tablet', 'mobile']) ?: 'desktop';
        if($path === '') $this->sendJsonResponse(['none' => true]);
        $snap = $this->core->getSnapshot($path, $device);
        if(!$snap) $this->sendJsonResponse(['none' => true]);
        $this->sendJsonResponse([
            'dom' => $snap['dom'],
            'capture_width' => (int) $snap['capture_width'],
            'captured_at' => (string) $snap['captured_at'],
        ]);
    }

    protected function sendJsonResponse($data) {
        if(function_exists('session_write_close')) @session_write_close();
        while(ob_get_level() > 0) @ob_end_clean();
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if($json === false) $json = '[]';
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        echo $json;
        exit;
    }

    // Build the heatmap dashboard markup (controls, backdrop stage, embedded
    // data, loader script). Public so NativeAnalyticsBehavior can call it to
    // render the injected "Behavior" tab in the main NativeAnalytics dashboard.
    public function renderTabContent() {
        $this->addAssets();
        $input = $this->wire('input');
        $sanitizer = $this->wire('sanitizer');
        // CSP nonce for the executable <script> tags we emit (our policy is
        // nonce-based for scripts; style/link tags and inert application/json
        // data blocks never carry a nonce). Empty string when no nonce is set.
        $nonce = $this->core->getCspNonce();
        $nonceAttr = $nonce !== '' ? ' nonce="' . $sanitizer->entities($nonce) . '"' : '';

        // rrweb-snapshot and the shared stage helper define window.rrwebSnapshot
        // and window.NABStage, which heatmap.js and session.js call. Emit them
        // inline as deferred scripts BEFORE those consumers so document order
        // guarantees they run first — config->scripts can't promise that ordering
        // relative to these inline scripts inside the embedded NativeAnalytics tab.
        $rrwebJs = $this->core->getVersionedAssetUrl('assets/vendor/rrweb-snapshot.js');
        $stageJs = $this->core->getVersionedAssetUrl('assets/nab-stage.js');
        $deps  = '<script' . $nonceAttr . ' src="' . $sanitizer->entities($rrwebJs) . '" defer></script>';
        $deps .= '<script' . $nonceAttr . ' src="' . $sanitizer->entities($stageJs) . '" defer></script>';

        $paths = $this->core->getTrackedPaths();
        // Prefer an explicit path; otherwise adopt the page selected in the main
        // NativeAnalytics dashboard (page_id), so switching to the Behavior tab
        // lands on the same page. Fall back to the homepage.
        $path = $sanitizer->text($input->get('path'));
        if(!$path) {
            $pageId = (int) $input->get('page_id');
            if($pageId > 0) {
                $selected = $this->wire('pages')->get($pageId);
                if($selected && $selected->id) $path = $selected->url;
            }
        }
        if(!$path) $path = '/';
        // Quick range is the base period (mirrors NativeAnalytics' toolbar): an
        // explicit From and/or To overrides it. The date inputs stay empty while a
        // quick range drives the view, so the dropdown reads as the active control.
        $rangeOpts = ['7d' => 'Last 7 days', '30d' => 'Last 30 days', '90d' => 'Last 90 days'];
        $range = (string) $input->get('range');
        if(!isset($rangeOpts[$range])) $range = '30d';
        $rangeDays = ['7d' => 7, '30d' => 30, '90d' => 90][$range];
        $fromInput = $sanitizer->date($input->get('from'), 'Y-m-d') ?: '';
        $toInput = $sanitizer->date($input->get('to'), 'Y-m-d') ?: '';
        $custom = ($fromInput !== '' || $toInput !== '');
        if($custom) {
            $to = $toInput ?: date('Y-m-d');
            $from = $fromInput ?: date('Y-m-d', strtotime('-' . ($rangeDays - 1) . ' days'));
        } else {
            $to = date('Y-m-d');
            $from = date('Y-m-d', strtotime('-' . ($rangeDays - 1) . ' days'));
        }
        // Device event counts drive both the dropdown labels and the fallback below.
        $deviceCounts = $this->core->getDeviceEventCounts($path, $from, $to);
        // The device with the most events for this page — what we default to and
        // switch back to whenever the page changes.
        $bestDevice = ''; $bestCount = -1;
        foreach(['desktop', 'tablet', 'mobile'] as $v) {
            $c = (int) ($deviceCounts[$v] ?? 0);
            if($c > $bestCount) { $bestCount = $c; $bestDevice = $v; }
        }
        $device = $sanitizer->option($input->get('device'), ['desktop', 'tablet', 'mobile']);
        // When the page changed (the path differs from the one carried in prev_path,
        // or none was carried), ignore the device that came over in the URL and open
        // on whichever device has the most results for the new page. An explicit
        // device change on the same page leaves prev_path === path, so it's kept.
        $prevPath = $sanitizer->text($input->get('prev_path'));
        $pageChanged = ($prevPath === '' || $prevPath !== $path);
        if($pageChanged || $device === '') {
            if($bestDevice !== '') $device = $bestDevice;
        } elseif((int) ($deviceCounts[$device] ?? 0) === 0 && $bestDevice !== '') {
            // Same page, but the chosen device has no data — avoid an empty view.
            $device = $bestDevice;
        }
        if($device === '') $device = 'desktop';

        $clicks = $this->core->getClickSelectorHeatmap($path, $device, $from, $to);
        $copies = $this->core->getCopySelectorHeatmap($path, $device, $from, $to);
        $scroll = $this->core->getScrollHeatmap($path, $device, $from, $to);
        $coords = $this->core->getClickCoordinates($path, $device, $from, $to);
        $deadClicks = $this->core->getDeadClicks($path, $device, $from, $to);
        $rageClicks = $this->core->getRageClicks($path, $device, $from, $to);
        $snapshot = $this->core->getSnapshot($path, $device);

        // Controls form
        $deviceOpts = '';
        foreach(['desktop' => 'Desktop', 'tablet' => 'Tablet', 'mobile' => 'Mobile'] as $v => $label) {
            $count = (int) ($deviceCounts[$v] ?? 0);
            $deviceOpts .= '<option value="' . $v . '"' . ($v === $device ? ' selected' : '') . '>' . $label . ' (' . $count . ')</option>';
        }

        // Top-pages quick-jump: the 25 pages with the most sessions. JS navigates the form to
        // the chosen page on change (see pathsearch.js). The current page is marked
        // selected when it's in the list; otherwise the placeholder shows.
        $topOpts = '<option value="">Top pages by sessions…</option>';
        foreach($this->core->getTopPagesBySessions(25) as $tp) {
            $tpPath = (string) $tp['path'];
            $topOpts .= '<option value="' . $sanitizer->entities($tpPath) . '"' . ($tpPath === $path ? ' selected' : '') . '>'
                . $sanitizer->entities($tpPath) . ' (' . (int) $tp['c'] . ')</option>';
        }

        $rangeOptsHtml = '';
        foreach($rangeOpts as $v => $label) {
            $rangeOptsHtml .= '<option value="' . $v . '"' . ($v === $range ? ' selected' : '') . '>' . $label . '</option>';
        }

        // Toolbar mirrors the NativeAnalytics dashboard tabs: pwna-toolbar panel
        // with the quick-range/period control first, then From/To, then the
        // page/device filters. Date inputs are blank while a quick range drives the
        // view (an explicit date overrides the range — see the date logic above).
        $out  = $deps;
        $out .= '<form method="get" class="pwna-toolbar pwna-panel pwna-toolbar-panel">';
        // Lets the server detect a page change on submit, so the device resets to
        // the one with the most results for the newly chosen page.
        $out .= '<input type="hidden" name="prev_path" value="' . $sanitizer->entities($path) . '">';
        $out .= '<div class="pwna-toolbar-main"><div class="pwna-toolbar-left">';
        $out .= '<label>Quick range <select name="range">' . $rangeOptsHtml . '</select></label>';
        $out .= '<label>From <input type="date" name="from" value="' . $sanitizer->entities($fromInput) . '"></label>';
        $out .= '<label>To <input type="date" name="to" value="' . $sanitizer->entities($toInput) . '"></label>';
        $out .= '<label>Device <select name="device">' . $deviceOpts . '</select></label>';
        $out .= '<label>Top pages <select data-nab-toppages>' . $topOpts . '</select></label>';
        $out .= '<label class="pwna-pagefind nab-pathfind">Find page '
            . '<input type="text" name="path" autocomplete="off" placeholder="Search tracked paths" value="' . $sanitizer->entities($path) . '" data-nab-pathsearch="1">'
            . '<div class="nab-pathfind-results" data-nab-pathsearch-results hidden></div></label>';
        $out .= '<button class="ui-button" type="submit">Apply</button>';
        $out .= '</div></div>';
        $out .= '</form>';

        // Wire up the path autocomplete. Resolve the Behavior process page URL
        // explicitly so the search endpoint is correct whether this view is the
        // standalone page or embedded as a tab in the NativeAnalytics dashboard
        // (where $page->url would point at NativeAnalytics, not us). Emitted in
        // every branch below so the field stays usable even with no snapshot.
        $procPage = $this->wire('pages')->get("template=admin, process=ProcessNativeAnalyticsBehavior, include=all");
        if($procPage && $procPage->id) {
            $cfg = json_encode(['url' => $procPage->url . 'path-search/'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
            $out .= '<script type="application/json" id="nab-pathsearch-config">' . $cfg . '</script>';
            $psJs = $this->core->getVersionedAssetUrl('assets/pathsearch.js');
            $out .= '<script' . $nonceAttr . ' src="' . $sanitizer->entities($psJs) . '" defer></script>';
        }

        if(!$paths) {
            return $out . '<p>No behavior data collected yet. Browse the front-end (and click around) to populate heatmaps.</p>';
        }

        if(!$snapshot) {
            $msg = 'No data captured yet for <strong>' . $sanitizer->entities($path)
                . '</strong> (' . $sanitizer->entities($device) . ').';
            return $out . $this->renderSessionSelector() . '<p>' . $msg . '</p>'
                . $this->renderSessionTrail($path, $from, $to, $nonceAttr);
        }

        $payload = json_encode([
            'clicks' => $clicks,
            'scroll' => $scroll,
            'coords' => $coords,
            'captureWidth' => $snapshot['capture_width'],
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

        $clickTotal = 0;
        foreach($clicks as $c) $clickTotal += (int) $c['c'];
        $scrollTotal = array_sum($scroll);

        $out .= $this->renderSessionSelector();

        // Everything from the aggregate meta through the heatmap stage is one
        // region; session.js hides #nab-aggregate wholesale on drill-down so the
        // click/scroll tables and heatmap give way to the single-session trail.
        $out .= '<div id="nab-aggregate">';

        $out .= '<p class="nab-snapshot-meta">Backdrop captured ' . $sanitizer->entities($snapshot['captured_at'])
            . ' at ' . (int) $snapshot['capture_width'] . 'px (' . $sanitizer->entities($snapshot['device']) . '). '
            . $clickTotal . ' ' . ($clickTotal === 1 ? 'click' : 'clicks')
            . ' · ' . $scrollTotal . ' scroll ' . ($scrollTotal === 1 ? 'session' : 'sessions') . '.'
            . ' <span id="nab-unmatched"></span></p>';

        // Two columns complement the visual heatmap. Left: the top-clicked
        // elements (including ones that don't resolve in the backdrop — the
        // "unmatched" overlay can't show those). Right: frustration signals
        // (dead/rage clicks) stacked above the scroll-reach table, which is built
        // client-side by heatmap.js (it needs the laid-out backdrop to locate
        // each heading/form), so its container is just a placeholder.
        $out .= '<div class="pwna-grid-2">';

        $out .= '<div class="pwna-panel">';
        if($clicks) {
            $out .= '<h3 class="nab-frust-title">Most clicked</h3>';
            $out .= '<div class="pwna-table-wrap"><table class="pwna-table nab-click-table">';
            $out .= '<thead><tr><th>Element</th><th class="nab-click-num">Clicks</th></tr></thead><tbody>';
            foreach(array_slice($clicks, 0, 20) as $c) {
                $out .= $this->clickRow($c, (int) $c['c'], $sanitizer);
            }
            $out .= '</tbody></table></div>';
        }
        if($copies) {
            $out .= '<h3 class="nab-frust-title">Most copied</h3>';
            $out .= '<div class="pwna-table-wrap"><table class="pwna-table nab-click-table">';
            $out .= '<thead><tr><th>Element</th><th class="nab-click-num">Copies</th></tr></thead><tbody>';
            foreach(array_slice($copies, 0, 20) as $c) {
                $out .= $this->clickRow($c, (int) $c['c'], $sanitizer);
            }
            $out .= '</tbody></table></div>';
        }
        $out .= '</div>';

        $out .= '<div class="pwna-panel">';
        // Frustration signals: dead clicks (on non-interactive elements) and rage
        // clicks (rapid repeated taps in one spot). Only shown when present so the
        // dashboard stays quiet on healthy pages.
        if($deadClicks || $rageClicks) {
            $out .= $this->renderFrustrationTable('Dead clicks', 'Clicks on elements that do nothing', $deadClicks, $sanitizer);
            $out .= $this->renderFrustrationTable('Rage clicks', 'Rapid repeated clicks in one spot', $rageClicks, $sanitizer);
        }
        $out .= '<div id="nab-scroll-sections"></div>';
        $out .= '</div>';

        $out .= '</div>';

        $out .= '<div class="pwna-panel">';
        $out .= '<div class="nab-stage-controls">';
        $out .= '<span class="nab-mode" role="group" aria-label="Heatmap view">';
        $out .= '<button type="button" class="nab-mode-btn ui-button" data-mode="outlines" aria-pressed="true">Element outlines</button>';
        $out .= '<button type="button" class="nab-mode-btn ui-button" data-mode="density" aria-pressed="false">Click density</button>';
        $out .= '</span>';
        $out .= '<button type="button" id="nab-toggle-heat" class="ui-button" aria-pressed="true">Hide heatmap</button>';
        $out .= '</div>';

        $out .= '<div class="nab-stage">';
        $out .= '<iframe id="nab-frame" sandbox="allow-same-origin"></iframe>';
        $out .= '<canvas id="nab-canvas"></canvas>';
        $out .= '</div>';
        $out .= '</div>';

        $out .= '</div>'; // #nab-aggregate

        $out .= '<script type="application/json" id="nab-data">' . $payload . '</script>';
        $out .= '<script type="application/json" id="nab-snapshot">' . $snapshot['dom'] . '</script>';

        $js = $this->core->getVersionedAssetUrl('assets/heatmap.js');
        $out .= '<script' . $nonceAttr . ' src="' . $sanitizer->entities($js) . '" defer></script>';

        $out .= $this->renderSessionTrail($path, $from, $to, $nonceAttr);

        return $out;
    }

    /**
     * One element row for the click/copy/frustration tables: the readable label
     * (falling back to the raw selector) and the count. When a selector is known
     * the row carries it in data-nab-sel and is made focusable, so heatmap.js can
     * scroll the backdrop to that element on click.
     */
    protected function clickRow($row, $count, $sanitizer) {
        $label = trim((string) ($row['label'] ?? ''));
        $selector = (string) ($row['selector'] ?? '');
        $cell = $label !== ''
            ? '<span class="nab-click-label">' . $sanitizer->entities($label) . '</span>'
            : '<code class="nab-click-sel">' . $sanitizer->entities($selector) . '</code>';
        $attrs = $selector !== ''
            ? ' class="nab-click-row" data-nab-sel="' . $sanitizer->entities($selector) . '" tabindex="0"'
            : '';
        return '<tr' . $attrs . '><td>' . $cell . '</td>'
            . '<td class="nab-click-num">' . $count . '</td></tr>';
    }

    /**
     * One frustration-signal section (dead or rage clicks), stacked in the right
     * column above the scroll-reach table. $rows are selector/label/count rows
     * from the core; the cell renders the readable label, falling back to the raw
     * selector, and shows "None detected." when there are no rows.
     */
    protected function renderFrustrationTable($title, $subtitle, $rows, $sanitizer) {
        $out = '<div class="nab-frust-section">';
        $out .= '<h3 class="nab-frust-title">' . $sanitizer->entities($title) . '</h3>';
        $out .= '<p class="nab-frust-sub">' . $sanitizer->entities($subtitle) . '</p>';
        if($rows) {
            $out .= '<div class="pwna-table-wrap"><table class="pwna-table nab-click-table">';
            $out .= '<thead><tr><th>Element</th><th class="nab-click-num">Clicks</th></tr></thead><tbody>';
            foreach(array_slice($rows, 0, 20) as $r) {
                $out .= $this->clickRow($r, (int) $r['c'], $sanitizer);
            }
            $out .= '</tbody></table></div>';
        } else {
            $out .= '<p class="nab-frust-none">None detected.</p>';
        }
        $out .= '</div>';
        return $out;
    }

    /**
     * The "Sessions on this page" selector. Rendered above the aggregate region
     * so it reads as a mode switch: picking a session swaps the whole aggregate
     * view (click/scroll tables + heatmap) for that session's trail.
     */
    protected function renderSessionSelector() {
        $out  = '<div class="pwna-panel nab-sessions" id="nab-sessions">';
        $out .= '<h3 class="nab-frust-title">Sessions on this page</h3>';
        $out .= '<div id="nab-session-list" class="nab-session-list"><p class="nab-frust-none">Loading sessions…</p></div>';
        $out .= '</div>';
        return $out;
    }

    /**
     * The trail stage (own sandboxed iframe + DOM marker overlay) shown in place
     * of the aggregate region on drill-down, plus the JSON config and the
     * deferred session.js. Rendered after the aggregate region so the trail sits
     * directly below the selector once the aggregate is hidden.
     */
    protected function renderSessionTrail($path, $from, $to, $nonceAttr) {
        $sanitizer = $this->wire('sanitizer');
        $procPage = $this->wire('pages')->get("template=admin, process=ProcessNativeAnalyticsBehavior, include=all");
        if(!$procPage || !$procPage->id) return '';
        $base = $procPage->url;

        $out  = '<div class="pwna-panel nab-trail" id="nab-trail" hidden>';
        $out .= '<div class="nab-trail-top">';
        $out .= '<div class="nab-trail-meta" id="nab-trail-meta"></div>';
        $out .= '</div>';
        $out .= '<div class="nab-trail-rail" id="nab-trail-rail"></div>';
        $out .= '<div class="nab-trail-table-wrap">';
        $out .= '<div id="nab-trail-scroll" class="nab-trail-scroll"></div>';
        $out .= '<div id="nab-trail-table"></div>';
        $out .= '</div>';
        $out .= '<div class="nab-trail-controls">';
        $out .= '<button type="button" class="ui-button" id="nab-trail-prev">&lsaquo; Prev</button>';
        $out .= '<span class="nab-trail-pos" id="nab-trail-pos">0 / 0</span>';
        $out .= '<button type="button" class="ui-button" id="nab-trail-next">Next &rsaquo;</button>';
        $out .= '<span class="nab-trail-spinner" id="nab-trail-spinner" hidden></span>';
        $out .= '<span class="nab-trail-legend" id="nab-trail-legend"></span>';
        $out .= '</div>';
        $out .= '<div class="nab-trail-stage" id="nab-trail-stage">';
        $out .= '<iframe id="nab-trail-frame" sandbox="allow-same-origin"></iframe>';
        $out .= '<div id="nab-trail-markers" class="nab-trail-markers"></div>';
        $out .= '<div id="nab-trail-placeholder" class="nab-trail-placeholder" hidden></div>';
        $out .= '</div>';
        $out .= '</div>';

        $cfg = json_encode([
            'listUrl' => $base . 'session-list/',
            'journeyUrl' => $base . 'session-journey/',
            'snapshotUrl' => $base . 'snapshot/',
            'path' => (string) $path,
            'from' => (string) $from,
            'to' => (string) $to,
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $out .= '<script type="application/json" id="nab-session-config">' . $cfg . '</script>';

        $sjs = $this->core->getVersionedAssetUrl('assets/session.js');
        $out .= '<script' . $nonceAttr . ' src="' . $sanitizer->entities($sjs) . '" defer></script>';

        return $out;
    }
}
