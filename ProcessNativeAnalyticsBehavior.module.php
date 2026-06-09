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
        $css = $this->core->getAssetUrl('assets/admin.css') . '?v=' . rawurlencode($this->core->getAssetVersion('assets/admin.css'));
        $this->wire('config')->styles->add($css);
        $lib = $this->core->getAssetUrl('assets/vendor/rrweb-snapshot.js') . '?v=' . rawurlencode($this->core->getAssetVersion('assets/vendor/rrweb-snapshot.js'));
        $this->wire('config')->scripts->add($lib);
    }

    public function ___execute() {
        $input = $this->wire('input');
        $sanitizer = $this->wire('sanitizer');

        $paths = $this->core->getTrackedPaths();
        $path = $sanitizer->text($input->get('path')) ?: ($paths[0] ?? '/');
        $device = $sanitizer->option($input->get('device'), ['desktop', 'tablet', 'mobile']) ?: 'desktop';
        $to = $sanitizer->date($input->get('to'), 'Y-m-d') ?: date('Y-m-d');
        $from = $sanitizer->date($input->get('from'), 'Y-m-d') ?: date('Y-m-d', strtotime('-30 days'));

        $clicks = $this->core->getClickSelectorHeatmap($path, $device, $from, $to);
        $scroll = $this->core->getScrollHeatmap($path, $device, $from, $to);
        $snapshot = $this->core->getSnapshot($path, $device);

        // Controls form
        $deviceOpts = '';
        foreach(['desktop' => 'Desktop', 'tablet' => 'Tablet', 'mobile' => 'Mobile'] as $v => $label) {
            $deviceOpts .= '<option value="' . $v . '"' . ($v === $device ? ' selected' : '') . '>' . $label . '</option>';
        }
        $pathOpts = '';
        foreach($paths as $p) {
            $pe = $sanitizer->entities($p);
            $pathOpts .= '<option value="' . $pe . '"' . ($p === $path ? ' selected' : '') . '>' . $pe . '</option>';
        }

        $out  = '<form method="get" class="nab-controls">';
        $out .= '<label class="uk-form-label">Page <select name="path" class="uk-select uk-form-width-medium">' . $pathOpts . '</select></label> ';
        $out .= '<label class="uk-form-label">Device <select name="device" class="uk-select uk-form-width-small">' . $deviceOpts . '</select></label> ';
        $out .= '<label class="uk-form-label">From <input type="date" name="from" class="uk-input uk-form-width-small" value="' . $sanitizer->entities($from) . '"></label> ';
        $out .= '<label class="uk-form-label">To <input type="date" name="to" class="uk-input uk-form-width-small" value="' . $sanitizer->entities($to) . '"></label> ';
        $out .= '<button type="submit" class="uk-button uk-button-primary">Apply</button>';
        $out .= '</form>';

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

        $out .= '<p class="nab-snapshot-meta">Backdrop captured ' . $sanitizer->entities($snapshot['captured_at'])
            . ' at ' . (int) $snapshot['capture_width'] . 'px. <span id="nab-unmatched"></span></p>';
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
