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
        $css = $this->core->getAssetUrl('assets/admin.css') . '?v=' . rawurlencode($this->core->getAssetVersion('assets/admin.css'));
        $this->wire('config')->styles->add($css);
        $lib = $this->core->getAssetUrl('assets/vendor/rrweb-snapshot.js') . '?v=' . rawurlencode($this->core->getAssetVersion('assets/vendor/rrweb-snapshot.js'));
        $this->wire('config')->scripts->add($lib);
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
        // No explicit device: open on the device with the most clicks for this page.
        $device = $sanitizer->option($input->get('device'), ['desktop', 'tablet', 'mobile'])
            ?: $this->core->getDefaultDevice($path, $from, $to);

        $clicks = $this->core->getClickSelectorHeatmap($path, $device, $from, $to);
        $scroll = $this->core->getScrollHeatmap($path, $device, $from, $to);
        $snapshot = $this->core->getSnapshot($path, $device);

        // Controls form
        $deviceCounts = $this->core->getDeviceEventCounts($path, $from, $to);
        $deviceOpts = '';
        foreach(['desktop' => 'Desktop', 'tablet' => 'Tablet', 'mobile' => 'Mobile'] as $v => $label) {
            $count = (int) ($deviceCounts[$v] ?? 0);
            $deviceOpts .= '<option value="' . $v . '"' . ($v === $device ? ' selected' : '') . '>' . $label . ' (' . $count . ')</option>';
        }

        $out  = '<form method="get" class="nab-controls">';
        $out .= '<label class="uk-form-label nab-pathfind">Page '
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
            $psJs = $this->core->getAssetUrl('assets/pathsearch.js') . '?v=' . rawurlencode($this->core->getAssetVersion('assets/pathsearch.js'));
            $out .= '<script src="' . $sanitizer->entities($psJs) . '" defer></script>';
        }

        if(!$paths) {
            return $out . '<p>No behavior data collected yet. Browse the front-end (and click around) to populate heatmaps.</p>';
        }

        if(!$snapshot) {
            return $out . '<p>No snapshot captured yet for <strong>' . $sanitizer->entities($path)
                . '</strong> (' . $sanitizer->entities($device) . '). Visit that page as a logged-out visitor to capture one, then reload this dashboard.</p>';
        }

        $payload = json_encode([
            'clicks' => $clicks,
            'scroll' => $scroll,
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

        // Two side-by-side tables complement the visual heatmap. The clicks
        // table includes elements that don't resolve in the backdrop (the
        // "unmatched" ones) which the overlay can't show. The scroll-reach table
        // is built client-side by heatmap.js (it needs the laid-out backdrop to
        // locate each heading/form), so the right column is just a placeholder.
        $out .= '<div class="nab-tables">';

        $out .= '<div class="nab-tables-col">';
        if($clicks) {
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
        $out .= '</div>';

        $out .= '<div class="nab-tables-col" id="nab-scroll-sections"></div>';

        $out .= '</div>';

        $out .= '<div class="nab-stage">';
        $out .= '<iframe id="nab-frame" sandbox="allow-same-origin"></iframe>';
        $out .= '<canvas id="nab-canvas"></canvas>';
        $out .= '</div>';
        $out .= '<script type="application/json" id="nab-data">' . $payload . '</script>';
        $out .= '<script type="application/json" id="nab-snapshot">' . $snapshot['dom'] . '</script>';

        $js = $this->core->getAssetUrl('assets/heatmap.js') . '?v=' . rawurlencode($this->core->getAssetVersion('assets/heatmap.js'));
        $out .= '<script src="' . $sanitizer->entities($js) . '" defer></script>';

        return $out;
    }
}
