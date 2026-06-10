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
        $this->wire('config')->styles->add($this->core->getVersionedAssetUrl('assets/admin.css'));
        $this->wire('config')->scripts->add($this->core->getVersionedAssetUrl('assets/vendor/rrweb-snapshot.js'));
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

        $paths = $this->core->getTrackedPaths();
        // Prefer an explicit path; otherwise adopt the page selected in the main
        // NativeAnalytics dashboard (page_id), so switching to the Behavior tab
        // lands on the same page. Fall back to the most-tracked path.
        $path = $sanitizer->text($input->get('path'));
        if(!$path) {
            $pageId = (int) $input->get('page_id');
            if($pageId > 0) {
                $selected = $this->wire('pages')->get($pageId);
                if($selected && $selected->id) $path = $selected->url;
            }
        }
        if(!$path) $path = $paths[0] ?? '/';
        $to = $sanitizer->date($input->get('to'), 'Y-m-d') ?: date('Y-m-d');
        $from = $sanitizer->date($input->get('from'), 'Y-m-d') ?: date('Y-m-d', strtotime('-30 days'));
        // A range preset button (7/30/90 days) overrides the date inputs.
        $preset = (int) $input->get('preset');
        if(in_array($preset, [7, 30, 90], true)) {
            $to = date('Y-m-d');
            $from = date('Y-m-d', strtotime('-' . $preset . ' days'));
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

        // Top-pages quick-jump: the 25 most-clicked pages. JS navigates the form to
        // the chosen page on change (see pathsearch.js). The current page is marked
        // selected when it's in the list; otherwise the placeholder shows.
        $topOpts = '<option value="">Top pages by clicks…</option>';
        foreach($this->core->getTopClickedPages(25) as $tp) {
            $tpPath = (string) $tp['path'];
            $topOpts .= '<option value="' . $sanitizer->entities($tpPath) . '"' . ($tpPath === $path ? ' selected' : '') . '>'
                . $sanitizer->entities($tpPath) . ' (' . (int) $tp['c'] . ')</option>';
        }

        $out  = '<form method="get" class="nab-controls">';
        // Lets the server detect a page change on submit, so the device resets to
        // the one with the most results for the newly chosen page.
        $out .= '<input type="hidden" name="prev_path" value="' . $sanitizer->entities($path) . '">';
        $out .= '<label class="uk-form-label">Top pages <select class="uk-select uk-form-width-medium" data-nab-toppages>' . $topOpts . '</select></label> ';
        $out .= '<label class="uk-form-label nab-pathfind">Page search '
            . '<input type="text" name="path" autocomplete="off" class="uk-input uk-form-width-medium" placeholder="Search tracked paths" value="' . $sanitizer->entities($path) . '" data-nab-pathsearch="1">'
            . '<div class="nab-pathfind-results" data-nab-pathsearch-results hidden></div></label> ';
        $out .= '<label class="uk-form-label">Device <select name="device" class="uk-select uk-form-width-small">' . $deviceOpts . '</select></label> ';
        $out .= '<label class="uk-form-label">From <input type="date" name="from" class="uk-input uk-form-width-small" value="' . $sanitizer->entities($from) . '"></label> ';
        $out .= '<label class="uk-form-label">To <input type="date" name="to" class="uk-input uk-form-width-small" value="' . $sanitizer->entities($to) . '"></label> ';
        $out .= '<button type="submit" class="uk-button uk-button-primary">Apply</button>';
        $out .= '<span class="nab-presets">'
            . '<button type="submit" name="preset" value="7" class="uk-button uk-button-default uk-button-small">7d</button>'
            . '<button type="submit" name="preset" value="30" class="uk-button uk-button-default uk-button-small">30d</button>'
            . '<button type="submit" name="preset" value="90" class="uk-button uk-button-default uk-button-small">90d</button>'
            . '</span>';
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
            return $out . '<p>' . $msg . '</p>';
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
        $out .= '<div class="nab-tables">';

        $out .= '<div class="nab-tables-col">';
        if($clicks) {
            $out .= '<h3 class="nab-frust-title">Most clicked</h3>';
            $out .= '<table class="nab-click-table uk-table uk-table-small uk-table-divider">';
            $out .= '<thead><tr><th>Element</th><th class="nab-click-num">Clicks</th></tr></thead><tbody>';
            foreach(array_slice($clicks, 0, 20) as $c) {
                $label = trim((string) ($c['label'] ?? ''));
                $cell = $label !== ''
                    ? '<span class="nab-click-label">' . $sanitizer->entities($label) . '</span>'
                    : '<code class="nab-click-sel">' . $sanitizer->entities($c['selector']) . '</code>';
                $out .= '<tr><td>' . $cell . '</td>'
                    . '<td class="nab-click-num">' . (int) $c['c'] . '</td></tr>';
            }
            $out .= '</tbody></table>';
        }
        if($copies) {
            $out .= '<h3 class="nab-frust-title">Most copied</h3>';
            $out .= '<table class="nab-click-table uk-table uk-table-small uk-table-divider">';
            $out .= '<thead><tr><th>Element</th><th class="nab-click-num">Copies</th></tr></thead><tbody>';
            foreach(array_slice($copies, 0, 20) as $c) {
                $label = trim((string) ($c['label'] ?? ''));
                $cell = $label !== ''
                    ? '<span class="nab-click-label">' . $sanitizer->entities($label) . '</span>'
                    : '<code class="nab-click-sel">' . $sanitizer->entities($c['selector']) . '</code>';
                $out .= '<tr><td>' . $cell . '</td>'
                    . '<td class="nab-click-num">' . (int) $c['c'] . '</td></tr>';
            }
            $out .= '</tbody></table>';
        }
        $out .= '</div>';

        $out .= '<div class="nab-tables-col">';
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

        $out .= '<div class="nab-stage-controls">';
        $out .= '<span class="nab-mode" role="group" aria-label="Heatmap view">';
        $out .= '<button type="button" class="nab-mode-btn uk-button uk-button-default uk-button-small" data-mode="outlines" aria-pressed="true">Element outlines</button>';
        $out .= '<button type="button" class="nab-mode-btn uk-button uk-button-default uk-button-small" data-mode="density" aria-pressed="false">Click density</button>';
        $out .= '</span>';
        $out .= '<button type="button" id="nab-toggle-heat" class="uk-button uk-button-default uk-button-small" aria-pressed="true">Hide heatmap</button>';
        $out .= '</div>';

        $out .= '<div class="nab-stage">';
        $out .= '<iframe id="nab-frame" sandbox="allow-same-origin"></iframe>';
        $out .= '<canvas id="nab-canvas"></canvas>';
        $out .= '</div>';

        $out .= '<script type="application/json" id="nab-data">' . $payload . '</script>';
        $out .= '<script type="application/json" id="nab-snapshot">' . $snapshot['dom'] . '</script>';

        $js = $this->core->getVersionedAssetUrl('assets/heatmap.js');
        $out .= '<script' . $nonceAttr . ' src="' . $sanitizer->entities($js) . '" defer></script>';

        return $out;
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
            $out .= '<table class="nab-click-table uk-table uk-table-small uk-table-divider">';
            $out .= '<thead><tr><th>Element</th><th class="nab-click-num">Clicks</th></tr></thead><tbody>';
            foreach(array_slice($rows, 0, 20) as $r) {
                $label = trim((string) ($r['label'] ?? ''));
                $cell = $label !== ''
                    ? '<span class="nab-click-label">' . $sanitizer->entities($label) . '</span>'
                    : '<code class="nab-click-sel">' . $sanitizer->entities($r['selector']) . '</code>';
                $out .= '<tr><td>' . $cell . '</td>'
                    . '<td class="nab-click-num">' . (int) $r['c'] . '</td></tr>';
            }
            $out .= '</tbody></table>';
        } else {
            $out .= '<p class="nab-frust-none">None detected.</p>';
        }
        $out .= '</div>';
        return $out;
    }
}
