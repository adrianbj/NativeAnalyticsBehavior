<?php namespace ProcessWire;

class NativeAnalyticsBehavior extends WireData implements Module, ConfigurableModule {

    const VERSION = 1;

    const EVENTS_TABLE = 'nab_events';
    const COLLECT_ROUTE = '/nab-collect';

    protected $defaults = [
        'enabled' => 1,
        'enableHeatmaps' => 1,
        'sampleRate' => 100,      // percent of pageloads that collect
        'retentionDays' => 60,
        'excludedPaths' => '',    // newline-separated path prefixes
        'excludedTemplates' => '', // newline-separated template names
        'blockedIps' => '',       // newline-separated IPs
    ];

    public static function getModuleInfo() {
        return [
            'title' => 'NativeAnalyticsBehavior',
            'summary' => 'Behavioral analytics companion for NativeAnalytics: heatmaps, insights and session recordings.',
            'version' => self::VERSION,
            'author' => 'Adrian Jones',
            'icon' => 'fire',
            'autoload' => true,
            'singular' => true,
            'requires' => ['ProcessWire>=3.0.173', 'PHP>=7.4', 'NativeAnalytics', 'LazyCron'],
        ];
    }

    public function init() {
        $this->applyDefaults();
        $this->ensureSchema();
        $this->maybeHandleCollect();

        $this->addHookAfter('LazyCron::everyDay', $this, 'handleDailyCron');
    }

    public function ready() {
        $this->applyDefaults();
        if(!$this->enabled) return;
        if($this->wire('config')->admin) return;
        if($this->shouldInjectCurrentRequest()) {
            $this->addHookAfter('Page::render', $this, 'injectCollector');
        }
    }

    protected function applyDefaults() {
        foreach($this->defaults as $key => $value) {
            if($this->get($key) === null) $this->set($key, $value);
        }
        if(!$this->get('hashSalt')) {
            $this->set('hashSalt', hash('sha256', $this->wire('config')->userAuthSalt . '|' . __FILE__));
        }
    }

    // --- asset + CSP helpers (mirrors NativeAnalytics) ---

    public function getModuleDirName() {
        return basename(__DIR__);
    }

    public function getAssetUrl($relativePath) {
        return $this->wire('config')->urls->siteModules . $this->getModuleDirName() . '/' . ltrim($relativePath, '/');
    }

    public function getAssetVersion($relativePath = '') {
        $version = (string) self::VERSION;
        $relativePath = ltrim((string) $relativePath, '/');
        if($relativePath !== '') {
            $file = __DIR__ . '/' . $relativePath;
            if(is_file($file)) $version .= '-' . filemtime($file);
        }
        return $version;
    }

    public function getCspNonce() {
        $nonce = '';
        $config = $this->wire('config');
        try {
            if($config && method_exists($config, 'cspNonce')) {
                $value = $config->cspNonce();
                if(is_string($value) && $value !== '') $nonce = $value;
            }
        } catch(\Throwable $e) {
            $nonce = '';
        }
        if($nonce === '' && $config) {
            try {
                $value = $config->get('cspNonce');
                if(is_string($value) && $value !== '') $nonce = $value;
            } catch(\Throwable $e) {
                $nonce = '';
            }
        }
        return is_string($nonce) ? $nonce : '';
    }

    protected function getScriptNonceAttribute() {
        $nonce = $this->getCspNonce();
        if($nonce === '') return '';
        return ' nonce="' . $this->wire('sanitizer')->entities($nonce) . '"';
    }

    protected function normalizePath($path) {
        $path = '/' . ltrim((string) $path, '/');
        $root = (string) $this->wire('config')->urls->root;
        if($root !== '/' && $root !== '' && strpos($path, $root) === 0) {
            $path = '/' . ltrim(substr($path, strlen($root)), '/');
        }
        return $path;
    }

    public function getCollectEndpointUrl() {
        return rtrim((string) $this->wire('config')->urls->root, '/') . self::COLLECT_ROUTE . '/';
    }

    // --- filled in by later tasks ---
    protected function ensureSchema($force = false) { /* Task 2 */ }
    protected function shouldInjectCurrentRequest() { return false; /* Task 4 */ }
    public function injectCollector(HookEvent $event) { /* Task 4 */ }
    protected function maybeHandleCollect() { /* Task 6 */ }
    public function handleDailyCron(HookEvent $event) { /* Task 7 */ }

    public function getModuleConfigInputfields(array $data) { /* Task 3 */ return new InputfieldWrapper(); }

    public function ___install() {}
    public function ___uninstall() {
        // Task 11 fills in table drop.
    }
}
