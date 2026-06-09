<?php namespace ProcessWire;

class NativeAnalyticsBehavior extends WireData implements Module, ConfigurableModule {

    const VERSION = 1;

    const EVENTS_TABLE = 'nab_events';
    const COLLECT_ROUTE = '/nab-collect';
    const SNAPSHOT_TABLE = 'nab_snapshots';
    const SNAPSHOT_ROUTE = '/nab-snapshot';
    const SNAPSHOT_MAX_BYTES = 4194304; // 4 MB raw upload cap (DOM + inlined CSS)
    const SNAPSHOT_BACKSTOP_DAYS = 30;  // re-capture if older than this regardless of edits

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
        $this->maybeHandleSnapshot();

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

    public function getSnapshotEndpointUrl() {
        return rtrim((string) $this->wire('config')->urls->root, '/') . self::SNAPSHOT_ROUTE . '/';
    }

    /**
     * Per-device freshness for the snapshot of a given path.
     * Returns ['fresh' => ['desktop'=>bool,'tablet'=>bool,'mobile'=>bool], 'pageModified' => 'Y-m-d H:i:s'|null].
     * A device is fresh when a stored snapshot exists, is not older than $page->modified, and is within the backstop window.
     */
    public function snapshotFreshnessForPath($path) {
        $fresh = ['desktop' => false, 'tablet' => false, 'mobile' => false];
        $page = $this->wire('page');
        $pageModified = ($page && $page->id && $page->modified)
            ? date('Y-m-d H:i:s', (int) $page->modified)
            : null;
        $cutoff = date('Y-m-d H:i:s', strtotime('-' . self::SNAPSHOT_BACKSTOP_DAYS . ' days'));
        $db = $this->wire('database');
        $stmt = $db->prepare("SELECT `device`,`captured_modified`,`captured_at`
            FROM `" . self::SNAPSHOT_TABLE . "` WHERE `path_hash`=:ph");
        $stmt->execute([':ph' => md5('/' . ltrim((string) $path, '/'))]);
        foreach($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $dev = (string) $row['device'];
            if(!array_key_exists($dev, $fresh)) continue;
            $ageOk = ((string) $row['captured_at']) >= $cutoff;
            $modOk = ($pageModified === null)
                || ($row['captured_modified'] !== null && ((string) $row['captured_modified']) >= $pageModified);
            $fresh[$dev] = $ageOk && $modOk;
        }
        return ['fresh' => $fresh, 'pageModified' => $pageModified];
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
        $db->exec("CREATE TABLE IF NOT EXISTS `" . self::SNAPSHOT_TABLE . "` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `path` VARCHAR(767) NOT NULL DEFAULT '',
            `path_hash` CHAR(32) NOT NULL DEFAULT '',
            `device` VARCHAR(16) NOT NULL DEFAULT '',
            `capture_width` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            `captured_modified` DATETIME NULL DEFAULT NULL,
            `captured_at` DATETIME NOT NULL,
            `dom_gz` MEDIUMBLOB NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `bucket` (`path_hash`, `device`),
            KEY `captured_at` (`captured_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $done = true;
    }
    protected function shouldInjectCurrentRequest() {
        if(!$this->enabled || !$this->enableHeatmaps) return false;
        if($this->wire('config')->ajax) return false;
        $page = $this->wire('page');
        if(!$page || !$page->id) return false;
        if($this->isExcludedTemplate($page)) return false;
        if($this->isExcludedPath()) return false;
        return true;
    }

    protected function configLines($key) {
        $raw = (string) $this->get($key);
        if($raw === '') return [];
        $out = [];
        foreach(preg_split('/\r\n|\r|\n/', $raw) as $line) {
            $line = trim($line);
            if($line !== '') $out[] = $line;
        }
        return $out;
    }

    protected function isExcludedTemplate(Page $page) {
        $name = $page->template ? (string) $page->template->name : '';
        if($name === '') return false;
        return in_array($name, $this->configLines('excludedTemplates'), true);
    }

    protected function isExcludedPath() {
        $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
        $path = $this->normalizePath((string) parse_url($uri, PHP_URL_PATH));
        foreach($this->configLines('excludedPaths') as $prefix) {
            $prefix = $this->normalizePath($prefix);
            if($prefix !== '/' && strpos($path, $prefix) === 0) return true;
        }
        return false;
    }

    public function injectCollector(HookEvent $event) {
        $html = (string) $event->return;
        if($html === '') return;
        if(stripos($html, '</body>') === false) return;
        if(stripos($html, 'data-nab-collector="1"') !== false) return;

        $payload = [
            'collectEndpoint' => $this->getCollectEndpointUrl(),
            'sampleRate' => (int) $this->sampleRate,
            'heatmaps' => (bool) $this->enableHeatmaps,
        ];

        if($this->wire('user')->isGuest()) {
            $page = $this->wire('page');
            $reqPath = ($page && $page->id)
                ? $this->normalizePath($page->path)
                : $this->normalizePath((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH));
            $info = $this->snapshotFreshnessForPath($reqPath);
            $payload['snapshotEndpoint'] = $this->getSnapshotEndpointUrl();
            $payload['snapshotLib'] = $this->getAssetUrl('assets/vendor/rrweb-snapshot.js')
                . '?v=' . rawurlencode($this->getAssetVersion('assets/vendor/rrweb-snapshot.js'));
            $payload['snapshotPath'] = $reqPath;
            $payload['snapshotFresh'] = $info['fresh'];
            $payload['pageModified'] = $info['pageModified']; // string or null
        }

        $jsonFlags = JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
        $configJson = json_encode($payload, $jsonFlags);
        if($configJson === false) return;

        $scriptUrl = $this->getAssetUrl('assets/collector.js') . '?v=' . rawurlencode($this->getAssetVersion('assets/collector.js'));
        $nonceAttr = $this->getScriptNonceAttribute();

        $injected = "\n<script" . $nonceAttr . ">window.NAB_CONFIG = " . $configJson . ";</script>\n";
        $injected .= '<script' . $nonceAttr . ' src="' . $this->wire('sanitizer')->entities($scriptUrl) . '" data-nab-collector="1" defer></script>' . "\n";

        $event->return = preg_replace('~</body>~i', $injected . '</body>', $html, 1);
    }
    protected function maybeHandleCollect() {
        $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
        if($uri === '') return;
        $path = rtrim($this->normalizePath((string) parse_url($uri, PHP_URL_PATH)), '/');
        if($path !== self::COLLECT_ROUTE) return;
        $this->handleCollectRequest();
    }

    protected function maybeHandleSnapshot() {
        $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
        if($uri === '') return;
        $path = rtrim($this->normalizePath((string) parse_url($uri, PHP_URL_PATH)), '/');
        if($path !== self::SNAPSHOT_ROUTE) return;
        $this->handleSnapshotRequest();
    }

    protected function sendJson($status, array $data) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($data);
        exit;
    }

    protected function clientIp() {
        return isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
    }

    protected function hashId($value) {
        $value = trim((string) $value);
        if($value === '') return '';
        return hash('sha256', (string) $this->get('hashSalt') . '|' . $value);
    }

    protected function handleCollectRequest() {
        if(!$this->enabled || !$this->enableHeatmaps) $this->sendJson(204, ['ok' => true]);
        if(($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') $this->sendJson(405, ['ok' => false]);
        if(in_array($this->clientIp(), $this->configLines('blockedIps'), true)) $this->sendJson(204, ['ok' => true]);

        $raw = file_get_contents('php://input');
        if($raw === false || strlen($raw) > 262144) $this->sendJson(413, ['ok' => false]);
        $data = json_decode($raw, true);
        if(!is_array($data) || empty($data['events']) || !is_array($data['events'])) $this->sendJson(400, ['ok' => false]);

        $sanitizer = $this->wire('sanitizer');
        $db = $this->wire('database');
        $now = date('Y-m-d H:i:s');
        $today = date('Y-m-d');
        $allowedTypes = ['click', 'scroll'];
        $allowedDevices = ['desktop', 'tablet', 'mobile'];

        $sql = "INSERT INTO `" . self::EVENTS_TABLE . "`
            (`created_at`,`created_date`,`type`,`path`,`path_hash`,`device`,`x_frac`,`y_px`,`vw`,`dh`,`scroll_pct`,`selector`,`visitor_hash`,`session_hash`)
            VALUES (:created_at,:created_date,:type,:path,:path_hash,:device,:x_frac,:y_px,:vw,:dh,:scroll_pct,:selector,:visitor_hash,:session_hash)";
        $stmt = $db->prepare($sql);

        $inserted = 0;
        foreach(array_slice($data['events'], 0, 200) as $ev) {
            if(!is_array($ev)) continue;
            $type = (string) ($ev['type'] ?? '');
            if(!in_array($type, $allowedTypes, true)) continue;

            $path = '/' . ltrim((string) ($ev['path'] ?? '/'), '/');
            $path = substr($path, 0, 767);
            $device = (string) ($ev['device'] ?? '');
            if(!in_array($device, $allowedDevices, true)) $device = 'desktop';

            $stmt->execute([
                ':created_at' => $now,
                ':created_date' => $today,
                ':type' => $type,
                ':path' => $path,
                ':path_hash' => md5($path),
                ':device' => $device,
                ':x_frac' => max(0, min(1000, (int) ($ev['x_frac'] ?? 0))),
                ':y_px' => max(0, (int) ($ev['y_px'] ?? 0)),
                ':vw' => max(0, min(65535, (int) ($ev['vw'] ?? 0))),
                ':dh' => max(0, (int) ($ev['dh'] ?? 0)),
                ':scroll_pct' => max(0, min(100, (int) ($ev['scroll_pct'] ?? 0))),
                ':selector' => substr($sanitizer->text((string) ($ev['selector'] ?? '')), 0, 255),
                ':visitor_hash' => $this->hashId($ev['visitorId'] ?? ''),
                ':session_hash' => $this->hashId($ev['sessionId'] ?? ''),
            ]);
            $inserted++;
        }
        $this->sendJson(200, ['ok' => true, 'stored' => $inserted]);
    }

    protected function handleSnapshotRequest() {
        if(!$this->enabled || !$this->enableHeatmaps) $this->sendJson(204, ['ok' => true]);
        if(($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') $this->sendJson(405, ['ok' => false]);
        if(in_array($this->clientIp(), $this->configLines('blockedIps'), true)) $this->sendJson(204, ['ok' => true]);

        $raw = file_get_contents('php://input');
        if($raw === false || strlen($raw) > self::SNAPSHOT_MAX_BYTES) $this->sendJson(413, ['ok' => false]);
        $data = json_decode($raw, true);
        if(!is_array($data) || !isset($data['dom']) || !is_array($data['dom'])) $this->sendJson(400, ['ok' => false]);

        $allowedDevices = ['desktop', 'tablet', 'mobile'];
        $device = (string) ($data['device'] ?? '');
        if(!in_array($device, $allowedDevices, true)) $device = 'desktop';

        $path = '/' . ltrim((string) ($data['path'] ?? '/'), '/');
        $path = substr($path, 0, 767);
        $width = max(0, min(65535, (int) ($data['capture_width'] ?? 0)));

        $pm = (string) ($data['pageModified'] ?? '');
        $capturedModified = preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $pm) ? $pm : null;

        $domJson = json_encode($data['dom'], JSON_UNESCAPED_SLASHES);
        if($domJson === false) $this->sendJson(400, ['ok' => false]);
        $gz = gzencode($domJson, 6);
        if($gz === false) $this->sendJson(500, ['ok' => false]);

        $db = $this->wire('database');
        $sql = "INSERT INTO `" . self::SNAPSHOT_TABLE . "`
            (`path`,`path_hash`,`device`,`capture_width`,`captured_modified`,`captured_at`,`dom_gz`)
            VALUES (:path,:ph,:device,:w,:cm,:now,:dom)
            ON DUPLICATE KEY UPDATE
              `path`=VALUES(`path`), `capture_width`=VALUES(`capture_width`),
              `captured_modified`=VALUES(`captured_modified`), `captured_at`=VALUES(`captured_at`),
              `dom_gz`=VALUES(`dom_gz`)";
        $stmt = $db->prepare($sql);
        $stmt->bindValue(':path', $path);
        $stmt->bindValue(':ph', md5($path));
        $stmt->bindValue(':device', $device);
        $stmt->bindValue(':w', $width, \PDO::PARAM_INT);
        if($capturedModified === null) $stmt->bindValue(':cm', null, \PDO::PARAM_NULL);
        else $stmt->bindValue(':cm', $capturedModified);
        $stmt->bindValue(':now', date('Y-m-d H:i:s'));
        $stmt->bindValue(':dom', $gz, \PDO::PARAM_LOB);
        $stmt->execute();

        $this->sendJson(200, ['ok' => true]);
    }

    public function handleDailyCron(HookEvent $event) {
        $days = max(1, (int) $this->retentionDays);
        $cutoff = date('Y-m-d H:i:s', strtotime('-' . $days . ' days'));
        try {
            $db = $this->wire('database');
            $stmt = $db->prepare("DELETE FROM `" . self::EVENTS_TABLE . "` WHERE `created_at` < :cutoff");
            $stmt->execute([':cutoff' => $cutoff]);
            $stmt2 = $db->prepare("DELETE FROM `" . self::SNAPSHOT_TABLE . "` WHERE `captured_at` < :cutoff");
            $stmt2->execute([':cutoff' => $cutoff]);
        } catch(\Throwable $e) {
            $this->wire('log')->save('native-analytics-behavior', 'Purge failed: ' . $e->getMessage());
        }
    }

    /**
     * Delete all collected events for a given raw visitor or session ID.
     * Pass the same un-hashed ID the client used (e.g. the pwna_vid value).
     * Returns the number of rows deleted.
     */
    public function eraseVisitor($visitorId) {
        $hash = $this->hashId($visitorId);
        if($hash === '') return 0;
        $db = $this->wire('database');
        $stmt = $db->prepare("DELETE FROM `" . self::EVENTS_TABLE . "` WHERE `visitor_hash`=:h");
        $stmt->execute([':h' => $hash]);
        return $stmt->rowCount();
    }

    /** Distinct paths that have collected data, most-active first. */
    public function getTrackedPaths($limit = 200) {
        $db = $this->wire('database');
        $stmt = $db->prepare("SELECT `path`, COUNT(*) AS c FROM `" . self::EVENTS_TABLE . "`
            GROUP BY `path` ORDER BY c DESC LIMIT :lim");
        $stmt->bindValue(':lim', (int) $limit, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    /**
     * Click buckets for a path/device/date range.
     * Returns rows: ['x_bucket'=>0..99, 'y_bucket'=>(floor(y_px/20)), 'c'=>count, 'dh'=>maxDocHeight].
     */
    public function getClickHeatmap($path, $device, $fromDate, $toDate) {
        $db = $this->wire('database');
        $sql = "SELECT FLOOR(`x_frac`/10) AS x_bucket, FLOOR(`y_px`/20) AS y_bucket, COUNT(*) AS c, MAX(`dh`) AS dh
            FROM `" . self::EVENTS_TABLE . "`
            WHERE `type`='click' AND `path_hash`=:ph AND `device`=:dev
              AND `created_date` BETWEEN :from AND :to
            GROUP BY x_bucket, y_bucket";
        $stmt = $db->prepare($sql);
        $stmt->execute([
            ':ph' => md5('/' . ltrim((string) $path, '/')),
            ':dev' => (string) $device,
            ':from' => (string) $fromDate,
            ':to' => (string) $toDate,
        ]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * The stored DOM snapshot for a path/device, gunzipped.
     * Returns ['capture_width'=>int, 'captured_at'=>string, 'dom'=>string(JSON)] or null.
     */
    public function getSnapshot($path, $device) {
        $db = $this->wire('database');
        $stmt = $db->prepare("SELECT `capture_width`,`captured_at`,`dom_gz`
            FROM `" . self::SNAPSHOT_TABLE . "` WHERE `path_hash`=:ph AND `device`=:dev LIMIT 1");
        $stmt->execute([
            ':ph' => md5('/' . ltrim((string) $path, '/')),
            ':dev' => (string) $device,
        ]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if(!$row) return null;
        $json = gzdecode($row['dom_gz']);
        if($json === false) return null;
        return [
            'capture_width' => (int) $row['capture_width'],
            'captured_at' => (string) $row['captured_at'],
            'dom' => $json,
        ];
    }

    /**
     * Click counts grouped by CSS selector for a path/device/date range.
     * Returns rows: ['selector'=>string, 'c'=>count], descending by count.
     */
    public function getClickSelectorHeatmap($path, $device, $fromDate, $toDate) {
        $db = $this->wire('database');
        $sql = "SELECT `selector`, COUNT(*) AS c FROM `" . self::EVENTS_TABLE . "`
            WHERE `type`='click' AND `path_hash`=:ph AND `device`=:dev
              AND `created_date` BETWEEN :from AND :to AND `selector` <> ''
            GROUP BY `selector` ORDER BY c DESC";
        $stmt = $db->prepare($sql);
        $stmt->execute([
            ':ph' => md5('/' . ltrim((string) $path, '/')),
            ':dev' => (string) $device,
            ':from' => (string) $fromDate,
            ':to' => (string) $toDate,
        ]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Scroll-depth distribution: count of pageviews reaching each 10% bucket.
     * Returns an 11-element array indexed 0..10 (0%,10%..100%).
     */
    public function getScrollHeatmap($path, $device, $fromDate, $toDate) {
        $db = $this->wire('database');
        $sql = "SELECT FLOOR(`scroll_pct`/10) AS depth_bucket, COUNT(*) AS c
            FROM `" . self::EVENTS_TABLE . "`
            WHERE `type`='scroll' AND `path_hash`=:ph AND `device`=:dev
              AND `created_date` BETWEEN :from AND :to
            GROUP BY depth_bucket";
        $stmt = $db->prepare($sql);
        $stmt->execute([
            ':ph' => md5('/' . ltrim((string) $path, '/')),
            ':dev' => (string) $device,
            ':from' => (string) $fromDate,
            ':to' => (string) $toDate,
        ]);
        $out = array_fill(0, 11, 0);
        foreach($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $b = max(0, min(10, (int) $row['depth_bucket']));
            $out[$b] += (int) $row['c'];
        }
        return $out;
    }

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
        try {
            $this->wire('database')->exec("DROP TABLE IF EXISTS `" . self::EVENTS_TABLE . "`");
            $this->wire('database')->exec("DROP TABLE IF EXISTS `" . self::SNAPSHOT_TABLE . "`");
        } catch(\Throwable $e) {
            $this->wire('log')->save('native-analytics-behavior', 'Uninstall drop failed: ' . $e->getMessage());
        }
    }
}
