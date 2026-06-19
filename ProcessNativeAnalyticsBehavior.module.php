<?php namespace ProcessWire;

class ProcessNativeAnalyticsBehavior extends Process {

    public static function getModuleInfo() {
        return [
            'title' => 'NativeAnalyticsBehavior Dashboard',
            'summary' => 'Dashboard for NativeAnalyticsBehavior (heatmaps).',
            'version' => '0.1.0',
            'author' => 'Adrian Jones',
            'permission' => 'nativeanalyticsbehavior-view',
            'permissions' => ['nativeanalyticsbehavior-view' => 'View NativeAnalyticsBehavior dashboard'],
            'icon' => 'fire',
            'requires' => ['NativeAnalyticsBehavior'],
            // Hidden: the dashboard is reached through the NativeAnalytics
            // "Behavior" tab and its AJAX endpoints, never navigated to directly,
            // so it stays out of the Setup menu. Endpoint lookups use include=all.
            'page' => ['name' => 'behavior-analytics', 'parent' => 'setup', 'title' => 'Behavior', 'status' => Page::statusHidden],
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
        // Empty path => all-pages overview (getSessionsForPath drops the path filter).
        // The 'all' flag lets a caller request all-pages explicitly even if a path leaks in.
        if(((int) $input->get('all')) === 1) $path = '';
        $from = $sanitizer->date($input->get('from'), 'Y-m-d') ?: date('Y-m-d', strtotime('-29 days'));
        $to = $sanitizer->date($input->get('to'), 'Y-m-d') ?: date('Y-m-d');
        // Engagement filters: each ?param=1 activates one criterion; active
        // criteria AND together. Thresholds are fixed here (10s / 25%).
        $filters = [
            'min_seconds' => ((int) $input->get('min_time')) === 1 ? 10 : 0,
            'interacted' => ((int) $input->get('interacted')) === 1,
            'min_scroll' => ((int) $input->get('min_scroll')) === 1 ? 25 : 0,
            'multi_page' => ((int) $input->get('multi_page')) === 1,
        ];
        $device = $sanitizer->option($input->get('device'), ['desktop', 'tablet', 'mobile']) ?: '';
        if($path === '') $device = '';
        $rows = $this->core->getSessionsForPath($path, $from, $to, 50, $filters, $device);
        $stats = $this->core->getSessionStatsForPath($path, $from, $to, $filters, $device);
        $sessions = [];
        foreach($rows as $r) {
            $h = (string) $r['session_hash'];
            $r['hash_short'] = $h !== '' ? substr($h, 0, 8) : '';
            $sessions[] = $r;
        }
        $this->sendJsonResponse([
            'sessions' => $sessions,
            'total' => $stats['total'],
            'showing' => count($sessions),
            'median_duration' => $stats['median_duration'],
            'median_pages' => $stats['median_pages'],
            'scroll_median' => $stats['scroll_median'],
            'median_clicks' => $stats['median_clicks'],
        ]);
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
        // Site searches come from pwna_hits (recorded by NativeAnalytics),
        // attached to the page the visitor searched FROM (origin_hash: the session's
        // preceding pageview, falling back to the results page for direct landings)
        // and merged into the page's interactions in time order. A page specialized to a URL-segment
        // variant carries a different hash and drops its searches rather than
        // misattaching them; query-string search pages keep their canonical
        // pathname, so the normal case always matches.
        $searchesByPage = [];
        foreach($this->core->getSessionSearches($session) as $s) {
            $searchesByPage[$s['origin_hash']][] = $s;
        }
        foreach($journey['pages'] as &$p) {
            $rows = $this->core->getSessionInteractions($session, $p['path_hash'], $device, 200);
            // "+N more" reflects only the capped nab interactions; searches
            // are appended afterwards and counted into interaction_count.
            $p['more'] = max(0, (int) $p['interaction_count'] - count($rows));
            $pageSearches = $searchesByPage[(string) $p['path_hash']] ?? [];
            foreach($pageSearches as $s) {
                $rows[] = [
                    'type' => 'search',
                    'x_frac' => 0,
                    'y_px' => 0,
                    'dh' => 0,
                    'offx' => 500,
                    'offy' => 500,
                    'selector' => '',
                    'label' => $s['label'],
                    'dead' => 0,
                    'rage' => 0,
                    't' => $s['t'],
                ];
            }
            if($pageSearches) {
                // usort is unstable before PHP 8.0 and timestamps are
                // second-granularity, so tie-break on the pre-sort index to
                // keep same-second interactions in their recorded order.
                foreach($rows as $i => &$rr) $rr['_i'] = $i;
                unset($rr);
                usort($rows, function($a, $b) {
                    return strcmp((string) $a['t'], (string) $b['t']) ?: ($a['_i'] <=> $b['_i']);
                });
                foreach($rows as &$rr) unset($rr['_i']);
                unset($rr);
                $p['interaction_count'] = (int) $p['interaction_count'] + count($pageSearches);
            }
            $p['interactions'] = $rows;
        }
        unset($p);
        $this->sendJsonResponse($journey);
    }

    // AJAX: the stored masked backdrop for ?path + ?device, as JSON. `dom` is the
    // snapshot JSON as a string (the client JSON.parses it, mirroring how
    // heatmap.js reads the inline #nab-snapshot block). {none:true} when absent.
    // ?at (a 'Y-m-d H:i:s' session-on-page time) selects the DOM version live at
    // that moment; omitted, the latest version is returned (D2 versioned snapshots).
    public function ___executeSnapshot() {
        if(!$this->core) $this->core = $this->wire('modules')->get('NativeAnalyticsBehavior');
        $input = $this->wire('input');
        $sanitizer = $this->wire('sanitizer');
        $path = $sanitizer->text($input->get('path'));
        $device = $sanitizer->option($input->get('device'), ['desktop', 'tablet', 'mobile']) ?: 'desktop';
        $at = (string) $input->get('at');
        if(!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $at)) $at = null;
        if($path === '') $this->sendJsonResponse(['none' => true]);
        $snap = $this->core->getSnapshot($path, $device, $at);
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

    /**
     * The controls form shared by the per-page view and the all-pages overview.
     * $opts: rangeOptsHtml, fromInput, toInput, path, topOpts (option HTML),
     * deviceOpts (option HTML; '' omits the Device control, as in the overview).
     */
    protected function renderToolbar(array $opts) {
        $sanitizer = $this->wire('sanitizer');
        $out  = '<form method="get" class="pwna-toolbar pwna-panel pwna-toolbar-panel">';
        $out .= '<input type="hidden" name="prev_path" value="' . $sanitizer->entities($opts['path']) . '">';
        $out .= '<div class="pwna-toolbar-main"><div class="pwna-toolbar-left">';
        $out .= '<label>Quick range <select name="range">' . $opts['rangeOptsHtml'] . '</select></label>';
        $out .= '<label>From <input type="date" name="from" value="' . $sanitizer->entities($opts['fromInput']) . '"></label>';
        $out .= '<label>To <input type="date" name="to" value="' . $sanitizer->entities($opts['toInput']) . '"></label>';
        if($opts['deviceOpts'] !== '') {
            $out .= '<label>Device <select name="device">' . $opts['deviceOpts'] . '</select></label>';
        }
        $out .= '<label>Top pages <select data-nab-toppages>' . $opts['topOpts'] . '</select></label>';
        $out .= '<label class="pwna-pagefind nab-pathfind">Find page '
            . '<input type="text" name="path" autocomplete="off" placeholder="Search tracked paths" value="' . $sanitizer->entities($opts['path']) . '" data-nab-pathsearch="1">'
            . '<div class="nab-pathfind-results" data-nab-pathsearch-results hidden></div></label>';
        $out .= '<button class="ui-button" type="submit">Apply</button>';
        $out .= '</div></div>';
        $out .= '</form>';
        return $out;
    }

    /**
     * The path-autocomplete config + pathsearch.js, emitted in both the overview
     * and per-page views so the Find page field always works. '' when the
     * process page can't be resolved.
     */
    protected function renderPathSearchScript($nonceAttr) {
        $sanitizer = $this->wire('sanitizer');
        $procPage = $this->wire('pages')->get("template=admin, process=ProcessNativeAnalyticsBehavior, include=all");
        if(!$procPage || !$procPage->id) return '';
        $cfg = json_encode(['url' => $procPage->url . 'path-search/'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $out  = '<script type="application/json" id="nab-pathsearch-config">' . $cfg . '</script>';
        $psJs = $this->core->getVersionedAssetUrl('assets/pathsearch.js');
        $out .= '<script' . $nonceAttr . ' src="' . $sanitizer->entities($psJs) . '" defer></script>';
        return $out;
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
        // No explicit path and no adopted page_id => all-pages overview mode.
        $allPages = ($path === '');
        // Quick range is the base period (mirrors NativeAnalytics' toolbar): an
        // explicit From and/or To overrides it. The date inputs stay empty while a
        // quick range drives the view, so the dropdown reads as the active control.
        $rangeOpts = ['7d' => 'Last 7 days', '30d' => 'Last 30 days', '90d' => 'Last 90 days'];
        $range = (string) $input->get('range');
        if(!isset($rangeOpts[$range])) $range = '30d';
        $rangeDays = ['7d' => 7, '30d' => 30, '90d' => 90][$range];
        $rangeOptsHtml = '';
        foreach($rangeOpts as $v => $label) {
            $rangeOptsHtml .= '<option value="' . $v . '"' . ($v === $range ? ' selected' : '') . '>' . $label . '</option>';
        }
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
        if($allPages) {
            $out  = $deps;
            $out .= $this->renderPathSearchScript($nonceAttr);
            if(!$paths) {
                return $out . '<p>No behavior data collected yet. Browse the front-end (and click around) to populate heatmaps.</p>';
            }
            return $out . $this->renderOverview($from, $to, $rangeOptsHtml, $fromInput, $toInput, $nonceAttr);
        }
        // Device session counts drive both the dropdown labels and the fallback below.
        $deviceCounts = $this->core->getDeviceSessionCounts($path, $from, $to);
        // The device with the most sessions for this page — what we default to and
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
        // Hybrid search attribution: rows for searches initiated on this page
        // (origin perspective) and rows for searches that landed here
        // (results perspective), labeled distinctly in the table. They only
        // overlap on the results page itself.
        $searches = [];
        foreach($this->core->getSearchOriginsForPath($path, $device, $from, $to) as $r) {
            $searches[] = ['label' => (string) $r['label'], 'c' => (int) $r['c'], 'mode' => 'origin'];
        }
        foreach($this->core->getSearchTermsForPath($path, $device, $from, $to) as $r) {
            $searches[] = ['label' => (string) $r['label'], 'c' => (int) $r['c'], 'mode' => 'results'];
        }
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

        // Top-pages quick-jump: the 25 pages with the most sessions. JS navigates
        // the form to the chosen page on change (see pathsearch.js). The current
        // page is marked selected when it's in the list; when it isn't, it gets
        // its own selected option below so the dropdown still reflects where you are.
        $topOpts = '<option value="__all__">All pages (overview)</option>';
        $pageOpts = '';
        $inList = false;
        foreach($this->core->getTopPagesBySessions(25, $from, $to) as $tp) {
            $tpPath = (string) $tp['path'];
            $sel = $tpPath === $path;
            if($sel) $inList = true;
            $pageOpts .= '<option value="' . $sanitizer->entities($tpPath) . '"' . ($sel ? ' selected' : '') . '>'
                . $sanitizer->entities($tpPath) . ' (' . (int) $tp['c'] . ')</option>';
        }
        if(!$inList && $path !== '') {
            $topOpts .= '<option value="' . $sanitizer->entities($path) . '" selected>' . $sanitizer->entities($path) . '</option>';
        }
        $topOpts .= $pageOpts;

        // Toolbar mirrors the NativeAnalytics dashboard tabs: pwna-toolbar panel
        // with the quick-range/period control first, then From/To, then the
        // page/device filters. Date inputs are blank while a quick range drives the
        // view (an explicit date overrides the range — see the date logic above).
        $out  = $deps;
        $out .= $this->renderToolbar([
            'rangeOptsHtml' => $rangeOptsHtml,
            'fromInput' => $fromInput,
            'toInput' => $toInput,
            'path' => $path,
            'topOpts' => $topOpts,
            'deviceOpts' => $deviceOpts,
        ]);

        // Wire up the path autocomplete. Emitted in every branch below so the
        // field stays usable even with no snapshot.
        $out .= $this->renderPathSearchScript($nonceAttr);

        if(!$paths) {
            return $out . '<p>No behavior data collected yet. Browse the front-end (and click around) to populate heatmaps.</p>';
        }

        if(!$snapshot) {
            $msg = 'No data captured yet for <strong>' . $sanitizer->entities($path)
                . '</strong> (' . $sanitizer->entities($device) . ').';
            return $out . $this->renderSessionSelector($device) . '<p>' . $msg . '</p>'
                . $this->renderSessionTrail($path, $from, $to, $nonceAttr, $device);
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

        $out .= $this->renderSessionSelector($device);

        // Everything from the aggregate meta through the heatmap stage is one
        // region; session.js hides #nab-aggregate wholesale on drill-down so the
        // click/scroll tables and heatmap give way to the single-session trail.
        $out .= '<div id="nab-aggregate">';

        $out .= '<p class="nab-snapshot-meta">Backdrop captured ' . $sanitizer->entities($snapshot['captured_at'])
            . ' at ' . (int) $snapshot['capture_width'] . 'px (' . $sanitizer->entities($snapshot['device']) . '). '
            . $clickTotal . ' ' . ($clickTotal === 1 ? 'click' : 'clicks')
            . ' · ' . $scrollTotal . ' scroll ' . ($scrollTotal === 1 ? 'session' : 'sessions') . '.'
            . ' <span id="nab-unmatched"></span></p>';

        // Two columns complement the visual heatmap. Left: one unified table of
        // clicks and copies (including elements that don't resolve in the
        // backdrop — the "unmatched" overlay can't show those), with dead/rage
        // frustration signals folded in as badges, mirroring the session-view
        // table. Right: the scroll-reach table, which is built client-side by
        // heatmap.js (it needs the laid-out backdrop to locate each
        // heading/form), so its container is just a placeholder.
        $out .= '<div class="pwna-grid-2">';

        $out .= '<div class="pwna-panel">';
        $out .= '<h3 class="nab-frust-title">Top interactions</h3>';
        $interactions = $this->buildInteractionRows($clicks, $copies, $searches, $deadClicks, $rageClicks);
        if($interactions) {
            $out .= '<div class="pwna-table-wrap"><table class="pwna-table nab-click-table">';
            $out .= '<thead><tr><th>Element</th><th>Type</th><th class="nab-click-num">Count</th></tr></thead><tbody>';
            foreach($interactions as $r) {
                $out .= $this->interactionRow($r, $sanitizer);
            }
            $out .= '</tbody></table></div>';
        } else {
            $out .= '<p class="nab-frust-none">No interactions recorded.</p>';
        }
        $out .= '</div>';

        $out .= '<div class="pwna-panel">';
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

        $out .= $this->renderSessionTrail($path, $from, $to, $nonceAttr, $device);

        return $out;
    }

    /**
     * The all-pages overview: toolbar (no device control), interactions grouped
     * by page, and all-sessions totals. No backdrop/heatmap stage — there is no
     * single captured layout to bind to.
     */
    protected function renderOverview($from, $to, $rangeOptsHtml, $fromInput, $toInput, $nonceAttr) {
        $sanitizer = $this->wire('sanitizer');
        // No "choose a page" placeholder here — the overview's own option is the
        // selected default, so a placeholder above it would just be an inert row.
        $topOpts  = '<option value="__all__" selected>All pages (overview)</option>';
        foreach($this->core->getTopPagesBySessions(25, $from, $to) as $tp) {
            $tpPath = (string) $tp['path'];
            $topOpts .= '<option value="' . $sanitizer->entities($tpPath) . '">'
                . $sanitizer->entities($tpPath) . ' (' . (int) $tp['c'] . ')</option>';
        }
        $out  = $this->renderToolbar([
            'rangeOptsHtml' => $rangeOptsHtml,
            'fromInput' => $fromInput,
            'toInput' => $toInput,
            'path' => '',
            'topOpts' => $topOpts,
            'deviceOpts' => '', // overview aggregates all devices
        ]);
        $out .= $this->renderOverviewInteractions($from, $to); // Task 4
        $out .= $this->renderOverviewSessions($from, $to, $nonceAttr); // Task 5
        return $out;
    }

    /**
     * A row-hover tooltip listing the pages an aggregated interaction happened
     * on, "path (count)" busiest first, joined with a middot. Capped so a
     * site-wide element (nav/footer) doesn't produce an enormous tooltip. The
     * UIkit tooltip renders the title as one wrapping string, so entries are
     * separated rather than newline-listed. Plain text — the caller escapes it.
     */
    protected function pagesTooltip(array $pageCounts) {
        arsort($pageCounts);
        $total = count($pageCounts);
        $cap = 15;
        $parts = [];
        foreach($pageCounts as $path => $c) {
            if(count($parts) >= $cap) {
                $parts[] = '…and ' . ($total - $cap) . ' more';
                break;
            }
            $parts[] = $path . ' (' . (int) $c . ')';
        }
        return implode(' · ', $parts);
    }

    /**
     * One titled interactions group (a table) for the overview. $rows are in the
     * interactionRow() shape. $heading is plain text (e.g. "Clicks", "Copies").
     */
    protected function renderInteractionGroup($heading, $subhead, $rows, $sanitizer) {
        if(!$rows) return '';
        // Wrapped in a column so the groups can sit side-by-side (see .nab-overview-col).
        $out  = '<div class="nab-overview-col">';
        $out .= '<h3 class="nab-frust-title">' . $sanitizer->entities($heading) . '</h3>';
        if($subhead !== '') $out .= '<p class="nab-snapshot-meta">' . $sanitizer->entities($subhead) . '</p>';
        $out .= '<div class="pwna-table-wrap"><table class="pwna-table nab-click-table">';
        // Each overview table is a single interaction type, so the Type column
        // would be redundant — drop it here (and in interactionRow via $showType).
        $out .= '<thead><tr><th>Element</th><th class="nab-click-num">Count</th></tr></thead><tbody>';
        foreach($rows as $r) $out .= $this->interactionRow($r, $sanitizer, false, false);
        $out .= '</tbody></table></div>';
        $out .= '</div>';
        return $out;
    }

    protected function renderOverviewInteractions($from, $to) {
        $sanitizer = $this->wire('sanitizer');

        // Fold dead/rage counts onto their (path, selector) click rows.
        $dead = [];
        foreach($this->core->getDeadClicksAllPages($from, $to) as $r) {
            $dead[$r['path'] . "\0" . $r['selector']] = (int) $r['c'];
        }
        $rage = [];
        foreach($this->core->getRageClicksAllPages($from, $to) as $r) {
            $rage[$r['path'] . "\0" . $r['selector']] = (int) $r['c'];
        }

        // Build interactionRow-shaped rows tagged with their path.
        $rows = [];
        foreach($this->core->getClickSelectorHeatmapAllPages($from, $to) as $r) {
            $k = $r['path'] . "\0" . $r['selector'];
            $rows[] = [
                'path' => (string) $r['path'], 'selector' => (string) $r['selector'],
                'label' => (string) ($r['label'] ?? ''), 'c' => (int) $r['c'], 'type' => 'click',
                'dead' => $dead[$k] ?? 0, 'rage' => $rage[$k] ?? 0,
            ];
        }
        foreach($this->core->getCopySelectorHeatmapAllPages($from, $to) as $r) {
            $rows[] = [
                'path' => (string) $r['path'], 'selector' => (string) $r['selector'],
                'label' => (string) ($r['label'] ?? ''), 'c' => (int) $r['c'], 'type' => 'copy',
                'dead' => 0, 'rage' => 0,
            ];
        }

        // Aggregate by visible label (or the raw selector when unlabeled) within
        // each interaction type, so the same element repeated across pages —
        // header/footer links, a shared form field, anything — sums into one row.
        // Clicks and copies get their own tables. Overview rows are inert, so the
        // specific selector no longer matters for display.
        $byType = ['click' => [], 'copy' => []];
        foreach($rows as $r) {
            $type = $r['type'] === 'copy' ? 'copy' : 'click';
            $shown = $r['label'] !== '' ? $r['label'] : $r['selector'];
            if(!isset($byType[$type][$shown])) {
                $byType[$type][$shown] = ['selector' => $r['selector'], 'label' => $r['label'],
                    'c' => 0, 'type' => $type, 'dead' => 0, 'rage' => 0, 'pages' => []];
            }
            $byType[$type][$shown]['c'] += $r['c'];
            $byType[$type][$shown]['dead'] += $r['dead'];
            $byType[$type][$shown]['rage'] += $r['rage'];
            // Per-page counts for the row tooltip; same path can arrive under
            // several selectors, so accumulate rather than overwrite.
            $byType[$type][$shown]['pages'][$r['path']] = ($byType[$type][$shown]['pages'][$r['path']] ?? 0) + $r['c'];
        }

        $sortDesc = function($a, $b) { return $b['c'] <=> $a['c']; };
        $clicks = array_values($byType['click']);
        usort($clicks, $sortDesc);
        $copies = array_values($byType['copy']);
        usort($copies, $sortDesc);
        // Turn each row's page map into a hover tooltip listing where it happened.
        foreach($clicks as &$r) $r['title'] = $this->pagesTooltip($r['pages']);
        unset($r);
        foreach($copies as &$r) $r['title'] = $this->pagesTooltip($r['pages']);
        unset($r);

        // Each group renders only when it has rows, so an absent table (e.g. no
        // copies in range) simply doesn't appear.
        $out  = $this->renderInteractionGroup('Clicks', 'Across all pages, combined.', $clicks, $sanitizer);
        $out .= $this->renderInteractionGroup('Copies', 'Text copied from these elements.', $copies, $sanitizer);

        if($out === '') $out = '<p class="nab-frust-none">No interactions recorded.</p>';
        return '<div class="nab-overview-interactions">' . $out . '</div>';
    }

    protected function renderOverviewSessions($from, $to, $nonceAttr) {
        return $this->renderSessionSelector('', true)
            . $this->renderSessionTrail('', $from, $to, $nonceAttr, '');
    }

    /**
     * Merge click, copy, and search rows into one list for the unified
     * "Top interactions" table. Dead/rage counts are folded into their click
     * rows by selector (frustration flags only exist on click events). Rows
     * sort by count descending; the list is capped at $cap rows, except rows
     * carrying a dead/rage badge, which are always kept so frustration
     * signals never drop out of view.
     */
    protected function buildInteractionRows($clicks, $copies, $searches, $deadClicks, $rageClicks, $cap = 25) {
        $dead = [];
        foreach($deadClicks as $r) $dead[(string) $r['selector']] = (int) $r['c'];
        $rage = [];
        foreach($rageClicks as $r) $rage[(string) $r['selector']] = (int) $r['c'];
        $rows = [];
        foreach($clicks as $r) {
            $sel = (string) $r['selector'];
            $rows[] = [
                'selector' => $sel,
                'label' => (string) ($r['label'] ?? ''),
                'c' => (int) $r['c'],
                'type' => 'click',
                'dead' => isset($dead[$sel]) ? $dead[$sel] : 0,
                'rage' => isset($rage[$sel]) ? $rage[$sel] : 0,
            ];
        }
        foreach($copies as $r) {
            $rows[] = [
                'selector' => (string) $r['selector'],
                'label' => (string) ($r['label'] ?? ''),
                'c' => (int) $r['c'],
                'type' => 'copy',
                'dead' => 0,
                'rage' => 0,
            ];
        }
        foreach($searches as $r) {
            $rows[] = [
                'selector' => '',
                'label' => (string) ($r['label'] ?? ''),
                'c' => (int) $r['c'],
                'type' => 'search',
                'mode' => (string) ($r['mode'] ?? 'origin'),
                'dead' => 0,
                'rage' => 0,
            ];
        }
        usort($rows, function($a, $b) { return $b['c'] <=> $a['c']; });
        $kept = array_slice($rows, 0, $cap);
        foreach(array_slice($rows, $cap) as $r) {
            if($r['dead'] > 0 || $r['rage'] > 0) $kept[] = $r;
        }
        return $kept;
    }

    /**
     * One row of the unified interactions table (click, copy, or search): the readable label (falling
     * back to the raw selector) with inline dead/rage badge counts, the
     * interaction type, and the count. When a selector is known the row
     * carries it in data-nab-sel and is made focusable, so heatmap.js can
     * scroll the backdrop to that element on click. Pass $interactive=false
     * (the all-pages overview, which has no backdrop) to render an inert row
     * with no click affordance, and $showType=false to omit the Type cell (the
     * overview's per-type tables don't need it).
     */
    protected function interactionRow($row, $sanitizer, $interactive = true, $showType = true) {
        $label = trim((string) $row['label']);
        $selector = (string) $row['selector'];
        if(($row['type'] ?? '') === 'search') {
            // The searched term, quoted; wording reflects the perspective —
            // initiated here (origin) vs landed here (results). No selector,
            // so the row gets no data-nab-sel below.
            $word = (($row['mode'] ?? 'origin') === 'results') ? 'Search results' : 'Searched for';
            $cell = '<span class="nab-click-label">' . $word . ' "' . $sanitizer->entities($label) . '"</span>';
        } else {
            $badges = '';
            if($row['dead'] > 0) $badges .= ' <span class="nab-row-sig is-dead">dead &times;' . (int) $row['dead'] . '</span>';
            if($row['rage'] > 0) $badges .= ' <span class="nab-row-sig is-rage">rage &times;' . (int) $row['rage'] . '</span>';
            $cell = $label !== ''
                ? '<span class="nab-click-label">' . $sanitizer->entities($label) . $badges . '</span>'
                : '<code class="nab-click-sel">' . $sanitizer->entities($selector) . $badges . '</code>';
        }
        $labelAttr = $label !== '' ? ' data-nab-label="' . $sanitizer->entities($label) . '"' : '';
        $attrs = ($interactive && $selector !== '')
            ? ' class="nab-click-row" data-nab-sel="' . $sanitizer->entities($selector) . '"' . $labelAttr . ' tabindex="0"'
            : '';
        // Optional hover tooltip (e.g. the overview's per-page breakdown). UIkit
        // reads the title attribute and styles it; the native title is the
        // fallback when UIkit isn't present.
        $title = trim((string) ($row['title'] ?? ''));
        if($title !== '') $attrs .= ' title="' . $sanitizer->entities($title) . '" uk-tooltip';
        $typeCell = $showType ? '<td>' . $sanitizer->entities($row['type']) . '</td>' : '';
        return '<tr' . $attrs . '><td>' . $cell . '</td>'
            . $typeCell
            . '<td class="nab-click-num">' . (int) $row['c'] . '</td></tr>';
    }

    /**
     * The "Sessions on this page" selector. Rendered above the aggregate region
     * so it reads as a mode switch: picking a session swaps the whole aggregate
     * view (click/scroll tables + heatmap) for that session's trail.
     */
    protected function renderSessionSelector($device = '', $allPages = false) {
        $dev = $device !== '' ? $this->wire('sanitizer')->entities($device) . ' ' : '';
        $title = $allPages ? 'All sessions in range' : ucfirst($dev . 'sessions on this page');
        $out  = '<div class="pwna-panel nab-sessions" id="nab-sessions">';
        $out .= '<h3 class="nab-frust-title">' . $title . '</h3>';
        $out .= '<div id="nab-session-list" class="nab-session-list"><p class="nab-frust-none">Loading ' . $dev . 'sessions…</p></div>';
        $out .= '</div>';
        return $out;
    }

    /**
     * The trail stage (own sandboxed iframe + DOM marker overlay) shown in place
     * of the aggregate region on drill-down, plus the JSON config and the
     * deferred session.js. Rendered after the aggregate region so the trail sits
     * directly below the selector once the aggregate is hidden.
     */
    protected function renderSessionTrail($path, $from, $to, $nonceAttr, $device = '') {
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
            'device' => (string) $device,
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $out .= '<script type="application/json" id="nab-session-config">' . $cfg . '</script>';

        $sjs = $this->core->getVersionedAssetUrl('assets/session.js');
        $out .= '<script' . $nonceAttr . ' src="' . $sanitizer->entities($sjs) . '" defer></script>';

        return $out;
    }
}
