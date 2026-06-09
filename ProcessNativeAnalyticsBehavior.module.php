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
        // Task 11 fills in the full heatmaps view. Scaffold confirms routing.
        return '<h2>Behavior — Heatmaps</h2><p>Heatmaps view loads here.</p>';
    }
}
