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
    protected function ensureSchema($force = false) {
        static $done = false;
        if($done && !$force) return;
        $db = $this->wire('database');
        $db->exec("CREATE TABLE IF NOT EXISTS `" . self::EVENTS_TABLE . "` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `created_at` DATETIME NOT NULL,
            `created_date` DATE NOT NULL,
            `type` VARCHAR(16) NOT NULL DEFAULT '',
            `path` VARCHAR(767) NOT NULL DEFAULT '',
            `path_hash` CHAR(32) NOT NULL DEFAULT '',
            `device` VARCHAR(16) NOT NULL DEFAULT '',
            `x_frac` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            `y_px` INT UNSIGNED NOT NULL DEFAULT 0,
            `vw` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            `dh` INT UNSIGNED NOT NULL DEFAULT 0,
            `scroll_pct` TINYINT UNSIGNED NOT NULL DEFAULT 0,
            `selector` VARCHAR(255) NOT NULL DEFAULT '',
            `visitor_hash` CHAR(64) NOT NULL DEFAULT '',
            `session_hash` CHAR(64) NOT NULL DEFAULT '',
            PRIMARY KEY (`id`),
            KEY `created_at` (`created_at`),
            KEY `created_date` (`created_date`),
            KEY `type_path_device` (`type`, `path_hash`, `device`),
            KEY `visitor_hash` (`visitor_hash`),
            KEY `session_hash` (`session_hash`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $done = true;
    }
    protected function shouldInjectCurrentRequest() { return false; /* Task 4 */ }
    public function injectCollector(HookEvent $event) { /* Task 4 */ }
    protected function maybeHandleCollect() { /* Task 6 */ }
    public function handleDailyCron(HookEvent $event) { /* Task 7 */ }

    public function getModuleConfigInputfields(array $data) {
        $modules = $this->wire('modules');
        $data = array_merge($this->defaults, $data);
        $wrap = new InputfieldWrapper();

        $f = $modules->get('InputfieldCheckbox');
        $f->name = 'enabled';
        $f->label = 'Enable behavior tracking';
        $f->attr('checked', !empty($data['enabled']));
        $wrap->add($f);

        $f = $modules->get('InputfieldCheckbox');
        $f->name = 'enableHeatmaps';
        $f->label = 'Enable heatmaps (click + scroll)';
        $f->attr('checked', !empty($data['enableHeatmaps']));
        $wrap->add($f);

        $f = $modules->get('InputfieldInteger');
        $f->name = 'sampleRate';
        $f->label = 'Sample rate (% of pageloads collected)';
        $f->min = 1; $f->max = 100;
        $f->value = (int) $data['sampleRate'];
        $wrap->add($f);

        $f = $modules->get('InputfieldInteger');
        $f->name = 'retentionDays';
        $f->label = 'Retention (days) — raw events older than this are purged daily';
        $f->min = 1; $f->max = 730;
        $f->value = (int) $data['retentionDays'];
        $wrap->add($f);

        $f = $modules->get('InputfieldTextarea');
        $f->name = 'excludedPaths';
        $f->label = 'Excluded path prefixes (one per line)';
        $f->value = (string) $data['excludedPaths'];
        $wrap->add($f);

        $f = $modules->get('InputfieldTextarea');
        $f->name = 'excludedTemplates';
        $f->label = 'Excluded templates (one per line)';
        $f->value = (string) $data['excludedTemplates'];
        $wrap->add($f);

        $f = $modules->get('InputfieldTextarea');
        $f->name = 'blockedIps';
        $f->label = 'Blocked IPs (one per line)';
        $f->value = (string) $data['blockedIps'];
        $wrap->add($f);

        return $wrap;
    }

    public function ___install() {}
    public function ___uninstall() {
        // Task 11 fills in table drop.
    }
}
