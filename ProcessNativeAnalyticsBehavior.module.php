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
    }

    public function ___execute() {
        $input = $this->wire('input');
        $sanitizer = $this->wire('sanitizer');

        $paths = $this->core->getTrackedPaths();
        $path = $sanitizer->text($input->get('path')) ?: ($paths[0] ?? '/');
        $device = $sanitizer->option($input->get('device'), ['desktop', 'tablet', 'mobile']) ?: 'desktop';
        $to = $sanitizer->date($input->get('to'), 'Y-m-d') ?: date('Y-m-d');
        $from = $sanitizer->date($input->get('from'), 'Y-m-d') ?: date('Y-m-d', strtotime('-30 days'));

        $clicks = $this->core->getClickHeatmap($path, $device, $from, $to);
        $scroll = $this->core->getScrollHeatmap($path, $device, $from, $to);
        $iframeUrl = rtrim((string) $this->wire('config')->urls->httpRoot, '/') . '/' . ltrim($path, '/');

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
        $out .= '<label>Page <select name="path">' . $pathOpts . '</select></label> ';
        $out .= '<label>Device <select name="device">' . $deviceOpts . '</select></label> ';
        $out .= '<label>From <input type="date" name="from" value="' . $sanitizer->entities($from) . '"></label> ';
        $out .= '<label>To <input type="date" name="to" value="' . $sanitizer->entities($to) . '"></label> ';
        $out .= '<button type="submit" class="ui-button">Apply</button>';
        $out .= '</form>';

        if(!$paths) {
            return $out . '<p>No behavior data collected yet. Browse the front-end (and click around) to populate heatmaps.</p>';
        }

        $payload = json_encode(['clicks' => $clicks, 'scroll' => $scroll], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

        $out .= '<div class="nab-heatmap-wrap">';
        $out .= '<div class="nab-stage">';
        $out .= '<iframe id="nab-frame" src="' . $sanitizer->entities($iframeUrl) . '" sandbox="allow-same-origin"></iframe>';
        $out .= '<canvas id="nab-canvas"></canvas>';
        $out .= '</div>';
        $out .= '<div class="nab-scroll" id="nab-scroll"><h3>Scroll depth</h3><div class="nab-scroll-bars"></div></div>';
        $out .= '</div>';
        $out .= '<script type="application/json" id="nab-data">' . $payload . '</script>';

        $js = $this->core->getAssetUrl('assets/heatmap.js') . '?v=' . rawurlencode($this->core->getAssetVersion('assets/heatmap.js'));
        $out .= '<script src="' . $sanitizer->entities($js) . '" defer></script>';

        return $out;
    }
}
