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
        'excludeNaBots' => 1,     // hide sessions NativeAnalytics flagged as bots
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

        // Inject a "Behavior" tab into the main NativeAnalytics dashboard. The
        // hooks are lazy (only fire when the dashboard renders its tabs), so
        // registering them unconditionally here is cheap; per-user gating lives
        // in the handlers.
        $this->addHookAfter('ProcessNativeAnalytics::getTabLabels', $this, 'hookTabLabels');
        $this->addHookAfter('ProcessNativeAnalytics::getTabs', $this, 'hookTabs');
    }

    protected function canViewBehaviorTab() {
        if(!$this->enabled || !$this->enableHeatmaps) return false;
        return $this->wire('user')->hasPermission('nativeanalyticsbehavior-view');
    }

    public function hookTabLabels(HookEvent $event) {
        if(!$this->canViewBehaviorTab()) return;
        $labels = $event->return;
        if(!is_array($labels)) return;
        $labels['behavior'] = 'Behavior';
        $event->return = $labels;
    }

    public function hookTabs(HookEvent $event) {
        if(!$this->canViewBehaviorTab()) return;
        $tabs = $event->return;
        if(!is_array($tabs)) return;
        $proc = $this->wire('modules')->get('ProcessNativeAnalyticsBehavior');
        if(!$proc) return;
        $tabs['behavior'] = $proc->renderTabContent();
        $event->return = $tabs;
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

    // Filename-based cache busting that matches the site's versionAsset() format:
    // assets/foo.js -> assets/foo.<mtime>.js. The site .htaccess rewrites the
    // versioned name back to the real file, so the URL path (not a query string)
    // changes when the file does. CloudFront keys on the path, so it busts
    // properly — a ?v= query string would be dropped/ignored at the CDN. The
    // mtime is a 10-digit timestamp, which the rewrite rule requires.
    public function getVersionedAssetUrl($relativePath) {
        $relativePath = ltrim((string) $relativePath, '/');
        $url = $this->getAssetUrl($relativePath);
        $file = __DIR__ . '/' . $relativePath;
        if(!is_file($file)) return $url;
        $ext = strtolower(pathinfo($relativePath, PATHINFO_EXTENSION));
        if($ext === '') return $url;
        return preg_replace('/\.' . preg_quote($ext, '/') . '$/', '.' . filemtime($file) . '.' . $ext, $url);
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

    /**
     * Server-trusted "page modified" timestamp for a captured path, used to gate
     * snapshot overwrites without trusting the client. The /nab-snapshot endpoint
     * is public, so the posted pageModified can't be believed: resolve the path to
     * a real page and read its own modified time instead. URL-segment / segment
     * paths don't resolve via get(), so they return null (caller falls back to an
     * age-only freshness check). Returns 'Y-m-d H:i:s' or null.
     */
    protected function pageModifiedForPath($path) {
        $path = $this->normalizePath((string) $path);
        try {
            $p = $this->wire('pages')->get($path);
        } catch(\Throwable $e) {
            return null;
        }
        return ($p && $p->id && $p->modified) ? date('Y-m-d H:i:s', (int) $p->modified) : null;
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
            `offx` SMALLINT UNSIGNED NOT NULL DEFAULT 500,
            `offy` SMALLINT UNSIGNED NOT NULL DEFAULT 500,
            `label` VARCHAR(255) NOT NULL DEFAULT '',
            `dead` TINYINT UNSIGNED NOT NULL DEFAULT 0,
            `rage` TINYINT UNSIGNED NOT NULL DEFAULT 0,
            `visitor_hash` CHAR(64) NOT NULL DEFAULT '',
            `session_hash` CHAR(64) NOT NULL DEFAULT '',
            `na_session_hash` CHAR(64) NOT NULL DEFAULT '',
            PRIMARY KEY (`id`),
            KEY `created_at` (`created_at`),
            KEY `created_date` (`created_date`),
            KEY `type_path_device` (`type`, `path_hash`, `device`),
            KEY `visitor_hash` (`visitor_hash`),
            KEY `session_hash` (`session_hash`),
            KEY `na_session_hash` (`na_session_hash`)
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
        // `label` was added after the events table shipped, so the CREATE above
        // is a no-op on existing installs. Add it idempotently for those.
        $col = $db->query("SHOW COLUMNS FROM `" . self::EVENTS_TABLE . "` LIKE 'label'");
        if($col && $col->rowCount() === 0) {
            $db->exec("ALTER TABLE `" . self::EVENTS_TABLE . "` ADD `label` VARCHAR(255) NOT NULL DEFAULT '' AFTER `selector`");
        }
        // `dead`/`rage` frustration flags shipped after labels, so add them
        // idempotently on existing installs too.
        $col = $db->query("SHOW COLUMNS FROM `" . self::EVENTS_TABLE . "` LIKE 'dead'");
        if($col && $col->rowCount() === 0) {
            $db->exec("ALTER TABLE `" . self::EVENTS_TABLE . "` ADD `dead` TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER `label`");
        }
        $col = $db->query("SHOW COLUMNS FROM `" . self::EVENTS_TABLE . "` LIKE 'rage'");
        if($col && $col->rowCount() === 0) {
            $db->exec("ALTER TABLE `" . self::EVENTS_TABLE . "` ADD `rage` TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER `dead`");
        }
        // `offx`/`offy` shipped with element-anchored density: where in the clicked
        // element the cursor landed (0..1000 fractions of its box), so the density
        // heatmap can pin each blob to the element in the rebuilt backdrop instead
        // of to absolute page coordinates that drift. Add them idempotently on
        // existing installs. Pre-existing rows default to the centre (500), which
        // anchors them to their element's middle — drift-free location, just
        // without intra-element spread.
        $col = $db->query("SHOW COLUMNS FROM `" . self::EVENTS_TABLE . "` LIKE 'offx'");
        if($col && $col->rowCount() === 0) {
            $db->exec("ALTER TABLE `" . self::EVENTS_TABLE . "` ADD `offx` SMALLINT UNSIGNED NOT NULL DEFAULT 500 AFTER `selector`");
            $db->exec("ALTER TABLE `" . self::EVENTS_TABLE . "` ADD `offy` SMALLINT UNSIGNED NOT NULL DEFAULT 500 AFTER `offx`");
        }
        // `na_session_hash` shipped with the bot-flag reuse: the session hash under
        // NativeAnalytics' salt so we can exclude sessions NA flagged as bots. Add
        // it (and its index) idempotently on existing installs. Pre-existing rows
        // keep '' and are never treated as bots — we never stored the raw IDs to
        // re-derive the NA-salted hash, so the filter is forward-only by design.
        $col = $db->query("SHOW COLUMNS FROM `" . self::EVENTS_TABLE . "` LIKE 'na_session_hash'");
        if($col && $col->rowCount() === 0) {
            $db->exec("ALTER TABLE `" . self::EVENTS_TABLE . "` ADD `na_session_hash` CHAR(64) NOT NULL DEFAULT '' AFTER `session_hash`");
            $db->exec("ALTER TABLE `" . self::EVENTS_TABLE . "` ADD KEY `na_session_hash` (`na_session_hash`)");
        }
        $done = true;
    }
    protected function shouldInjectCurrentRequest() {
        if(!$this->enabled || !$this->enableHeatmaps) return false;
        if($this->wire('config')->ajax) return false;
        // Never track staff/superuser sessions: their admin and testing browsing
        // would pollute the data and cause confusion. Subscribers (role 'user')
        // and guests are tracked normally.
        $u = $this->wire('user');
        if($u->isSuperuser() || $u->hasRole('editor')) return false;
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

        // Snapshot capture runs for every tracked visitor — guests and logged-in
        // subscribers alike (staff/superuser are already excluded upstream).
        // Logged-in pages (story forms, dashboards) are login-gated, so excluding
        // them would leave those pages with no heatmap backdrop at all; instead we
        // rely on the masking floor — maskAllInputs redacts every field value and
        // [data-na-mask]/[data-na-block] redact rendered PII regions.
        // Match the client's window.location.pathname (full path incl. URL
        // segments, site-root prefix) so the freshness check keys on the same
        // path_hash that clicks and the stored snapshot will use.
        $reqPath = '/' . ltrim((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH), '/');
        $info = $this->snapshotFreshnessForPath($reqPath);
        $payload['snapshotEndpoint'] = $this->getSnapshotEndpointUrl();
        $payload['snapshotLib'] = $this->getVersionedAssetUrl('assets/vendor/rrweb-snapshot.js');
        $payload['snapshotFresh'] = $info['fresh'];
        $payload['pageModified'] = $info['pageModified']; // string or null

        $jsonFlags = JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
        $configJson = json_encode($payload, $jsonFlags);
        if($configJson === false) return;

        $scriptUrl = $this->getVersionedAssetUrl('assets/collector.js');
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

    /**
     * NativeAnalytics' effective hash salt, read from the live module instance
     * (NA is autoload and sets this in init()/ready(), so it is populated by the
     * time ingest runs). Cached per request. Returns '' if NA is unavailable.
     * This is deliberately NOT our own hashSalt: matching NA's salt is what lets
     * a session hash here line up with NA's session_hash for the bot-flag join.
     */
    protected function naHashSalt() {
        static $salt = null;
        if($salt !== null) return $salt;
        $salt = '';
        $modules = $this->wire('modules');
        if($modules->isInstalled('NativeAnalytics')) {
            $na = $modules->get('NativeAnalytics');
            if($na) $salt = (string) $na->get('hashSalt');
        }
        return $salt;
    }

    /**
     * Session hash under NA's salt, matching NativeAnalytics::hashValue() exactly
     * (sha256 of salt . '|' . rawId). Used only to join our rows to NA's bot
     * flags. Returns '' when the raw id or NA's salt is missing, in which case
     * the row is never excluded as a bot.
     */
    protected function naSessionHash($sessionId) {
        $sessionId = trim((string) $sessionId);
        $salt = $this->naHashSalt();
        if($sessionId === '' || $salt === '') return '';
        return hash('sha256', $salt . '|' . $sessionId);
    }

    /**
     * True when NativeAnalytics' hits table exists. Cached per request. The
     * session-trail queries and the bot filter all depend on pwna_hits, so they
     * degrade gracefully (empty result) when NA is not installed.
     */
    protected function hasHitsTable() {
        static $has = null;
        if($has !== null) return $has;
        $has = false;
        try {
            $t = $this->wire('database')->query("SHOW TABLES LIKE 'pwna_hits'");
            $has = ($t && $t->rowCount() > 0);
        } catch(\Throwable $e) {
            $has = false;
        }
        return $has;
    }

    /**
     * A " COLLATE <name>" fragment that forces our na_session_hash to the live
     * collation of pwna_hits.session_hash, so a column-to-column comparison
     * between the two is well-defined (a bare comparison raises MySQL error
     * 1267 when their table-default collations differ). Returns '' when the
     * column can't be inspected. Cached per request.
     */
    protected function naHashCollation() {
        static $collate = null;
        if($collate !== null) return $collate;
        $collate = '';
        if(!$this->hasHitsTable()) return $collate;
        try {
            $col = $this->wire('database')->query("SHOW FULL COLUMNS FROM `pwna_hits` LIKE 'session_hash'");
        } catch(\Throwable $e) {
            return $collate;
        }
        if($col && $col->rowCount() > 0) {
            $row = $col->fetch(\PDO::FETCH_ASSOC);
            $c = isset($row['Collation']) ? (string) $row['Collation'] : '';
            if($c !== '' && preg_match('/^[A-Za-z0-9_]+$/', $c)) $collate = " COLLATE $c";
        }
        return $collate;
    }

    /**
     * WHERE fragment (with a leading AND) that drops rows whose session
     * NativeAnalytics flagged as a bot, for appending to any events query.
     * Returns '' (no filtering) when the toggle is off or NA's hits table is
     * absent, so the dashboard degrades gracefully. Evaluated at query time
     * against the live flags, so NA's hourly classifier backfilling is_bot is
     * reflected on the next dashboard load with no sync job here.
     *
     * The filter only excludes rows POSITIVELY matched as a bot: a row with an
     * empty na_session_hash (anything stored before this shipped) has no NA-salt
     * hash to match and is always kept. The IN form (rather than NOT IN) also
     * sidesteps the NULL-in-subquery trap. The bot-session subquery is on a
     * separate table so there is no column-name collision with na_session_hash.
     */
    protected function botExclusionSql() {
        static $sql = null;
        if($sql !== null) return $sql;
        $sql = '';
        if(empty($this->excludeNaBots)) return $sql;
        if(!$this->hasHitsTable()) return $sql;
        $collate = $this->naHashCollation();
        $sql = " AND NOT (`na_session_hash` <> '' AND `na_session_hash`$collate IN ("
             . "SELECT `session_hash` FROM `pwna_hits` WHERE `is_bot`=1 AND `session_hash` <> ''))";
        return $sql;
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
        $allowedTypes = ['click', 'scroll', 'copy'];
        $allowedDevices = ['desktop', 'tablet', 'mobile'];

        $sql = "INSERT INTO `" . self::EVENTS_TABLE . "`
            (`created_at`,`created_date`,`type`,`path`,`path_hash`,`device`,`x_frac`,`y_px`,`vw`,`dh`,`scroll_pct`,`selector`,`offx`,`offy`,`label`,`dead`,`rage`,`visitor_hash`,`session_hash`,`na_session_hash`)
            VALUES (:created_at,:created_date,:type,:path,:path_hash,:device,:x_frac,:y_px,:vw,:dh,:scroll_pct,:selector,:offx,:offy,:label,:dead,:rage,:visitor_hash,:session_hash,:na_session_hash)";
        $stmt = $db->prepare($sql);
        // Scroll depth is re-sent on every flush so it survives a missed
        // pagehide; keep one max-depth row per (session, path, device) rather
        // than inserting a row per flush, which would count one pageview many
        // times. Clicks stay plain multi-row inserts.
        $findScroll = $db->prepare("SELECT `id`,`scroll_pct` FROM `" . self::EVENTS_TABLE . "`
            WHERE `type`='scroll' AND `session_hash`=:sh AND `path_hash`=:ph AND `device`=:dev LIMIT 1");
        $bumpScroll = $db->prepare("UPDATE `" . self::EVENTS_TABLE . "`
            SET `scroll_pct`=:pct, `created_at`=:ca WHERE `id`=:id");

        $inserted = 0;
        foreach(array_slice($data['events'], 0, 200) as $ev) {
            if(!is_array($ev)) continue;
            $type = (string) ($ev['type'] ?? '');
            if(!in_array($type, $allowedTypes, true)) continue;

            $path = '/' . ltrim((string) ($ev['path'] ?? '/'), '/');
            $path = substr($path, 0, 767);
            $pathHash = md5($path);
            $device = (string) ($ev['device'] ?? '');
            if(!in_array($device, $allowedDevices, true)) $device = 'desktop';
            $rawSessionId = $ev['sessionId'] ?? '';
            $sessionHash = $this->hashId($rawSessionId);
            $naSessionHash = $this->naSessionHash($rawSessionId);
            $scrollPct = max(0, min(100, (int) ($ev['scroll_pct'] ?? 0)));

            if($type === 'scroll' && $sessionHash !== '') {
                $findScroll->execute([':sh' => $sessionHash, ':ph' => $pathHash, ':dev' => $device]);
                $existing = $findScroll->fetch(\PDO::FETCH_ASSOC);
                if($existing) {
                    if($scrollPct > (int) $existing['scroll_pct']) {
                        $bumpScroll->execute([':pct' => $scrollPct, ':ca' => $now, ':id' => $existing['id']]);
                    }
                    continue;
                }
            }

            $stmt->execute([
                ':created_at' => $now,
                ':created_date' => $today,
                ':type' => $type,
                ':path' => $path,
                ':path_hash' => $pathHash,
                ':device' => $device,
                ':x_frac' => max(0, min(1000, (int) ($ev['x_frac'] ?? 0))),
                ':y_px' => max(0, (int) ($ev['y_px'] ?? 0)),
                ':vw' => max(0, min(65535, (int) ($ev['vw'] ?? 0))),
                ':dh' => max(0, (int) ($ev['dh'] ?? 0)),
                ':scroll_pct' => $scrollPct,
                ':selector' => substr($sanitizer->text((string) ($ev['selector'] ?? '')), 0, 255),
                ':offx' => max(0, min(1000, (int) ($ev['offx'] ?? 500))),
                ':offy' => max(0, min(1000, (int) ($ev['offy'] ?? 500))),
                ':label' => substr($sanitizer->text((string) ($ev['label'] ?? '')), 0, 255),
                ':dead' => !empty($ev['dead']) ? 1 : 0,
                ':rage' => !empty($ev['rage']) ? 1 : 0,
                ':visitor_hash' => $this->hashId($ev['visitorId'] ?? ''),
                ':session_hash' => $sessionHash,
                ':na_session_hash' => $naSessionHash,
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

        // The freshness marker can't be taken from the client: a forged future
        // value would pin a spoofed snapshot as permanently "fresh". When the path
        // resolves to a real page, use that page's modified time; otherwise (URL
        // segment / segment paths) accept the posted value but never a future one.
        $now = date('Y-m-d H:i:s');
        $capturedModified = $this->pageModifiedForPath($path);
        if($capturedModified === null) {
            $pm = (string) ($data['pageModified'] ?? '');
            if(preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $pm) && $pm <= $now) $capturedModified = $pm;
        }

        $db = $this->wire('database');
        $pathHash = md5($path);

        // Freshness gate: refuse to overwrite a snapshot that is still fresh. The
        // endpoint is public, so without this any client could clobber a good
        // snapshot with a forged DOM. "Fresh" matches snapshotFreshnessForPath():
        // within the backstop window AND captured no older than the page's edit.
        // A real refresh still happens once the page is edited (its modified
        // advances past the stored marker) or the snapshot ages out.
        $cutoff = date('Y-m-d H:i:s', strtotime('-' . self::SNAPSHOT_BACKSTOP_DAYS . ' days'));
        $existing = $db->prepare("SELECT `captured_modified`,`captured_at`
            FROM `" . self::SNAPSHOT_TABLE . "` WHERE `path_hash`=:ph AND `device`=:device LIMIT 1");
        $existing->execute([':ph' => $pathHash, ':device' => $device]);
        if($row = $existing->fetch(\PDO::FETCH_ASSOC)) {
            $ageOk = ((string) $row['captured_at']) >= $cutoff;
            $modOk = ($capturedModified === null)
                || ($row['captured_modified'] !== null && ((string) $row['captured_modified']) >= $capturedModified);
            if($ageOk && $modOk) $this->sendJson(200, ['ok' => true, 'fresh' => true]);
        }

        // JSON_HEX_TAG escapes < and > so the stored DOM can be safely embedded in a
        // <script type="application/json"> block on the admin dashboard; without it,
        // page text containing the literal "</script>" would break out of the tag.
        $domJson = json_encode($data['dom'], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
        if($domJson === false) $this->sendJson(400, ['ok' => false]);
        $gz = gzencode($domJson, 6);
        if($gz === false) $this->sendJson(500, ['ok' => false]);

        $sql = "INSERT INTO `" . self::SNAPSHOT_TABLE . "`
            (`path`,`path_hash`,`device`,`capture_width`,`captured_modified`,`captured_at`,`dom_gz`)
            VALUES (:path,:ph,:device,:w,:cm,:now,:dom)
            ON DUPLICATE KEY UPDATE
              `path`=VALUES(`path`), `capture_width`=VALUES(`capture_width`),
              `captured_modified`=VALUES(`captured_modified`), `captured_at`=VALUES(`captured_at`),
              `dom_gz`=VALUES(`dom_gz`)";
        $stmt = $db->prepare($sql);
        $stmt->bindValue(':path', $path);
        $stmt->bindValue(':ph', $pathHash);
        $stmt->bindValue(':device', $device);
        $stmt->bindValue(':w', $width, \PDO::PARAM_INT);
        if($capturedModified === null) $stmt->bindValue(':cm', null, \PDO::PARAM_NULL);
        else $stmt->bindValue(':cm', $capturedModified);
        $stmt->bindValue(':now', $now);
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
     * Pages with the most distinct sessions, descending. Backs the dashboard's
     * "top pages" quick-jump dropdown. Counts unique session_hash values across
     * all event types and honors the bot-exclusion setting so the ranking matches
     * the heatmaps.
     *
     * @return array<int,array{path:string,c:int}>
     */
    public function getTopPagesBySessions($limit = 25) {
        $limit = max(1, min(100, (int) $limit));
        $db = $this->wire('database');
        $stmt = $db->prepare("SELECT `path`, COUNT(DISTINCT `session_hash`) AS c FROM `" . self::EVENTS_TABLE . "`
            WHERE `session_hash` <> ''" . $this->botExclusionSql() . "
            GROUP BY `path` ORDER BY c DESC LIMIT " . $limit);
        $stmt->execute();
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        $out = [];
        foreach($rows as $row) {
            $out[] = ['path' => (string) $row['path'], 'c' => (int) $row['c']];
        }
        return $out;
    }

    /**
     * Tracked paths matching a search term, most-collected first. Backs the
     * dashboard's path autocomplete: searching keeps the long tail reachable
     * where the volume-capped getTrackedPaths() list would drop it.
     *
     * @return array<int,array{path:string,c:int}>
     */
    public function searchTrackedPaths($term, $limit = 20) {
        $term = trim((string) $term);
        $len = function_exists('mb_strlen') ? mb_strlen($term) : strlen($term);
        if($len < 1) return [];
        $limit = max(1, min(50, (int) $limit));
        $like = '%' . $this->escapeLikeTerm($term) . '%';
        $db = $this->wire('database');
        $stmt = $db->prepare("SELECT `path`, COUNT(*) AS c FROM `" . self::EVENTS_TABLE . "`
            WHERE `path` LIKE :like
            GROUP BY `path` ORDER BY c DESC LIMIT " . $limit);
        $stmt->execute([':like' => $like]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        $out = [];
        foreach($rows as $row) {
            $out[] = ['path' => (string) $row['path'], 'c' => (int) $row['c']];
        }
        return $out;
    }

    // Escape MySQL LIKE wildcards (% and _) and the escape char itself so a
    // user-typed term matches literally. Backslash first, so escapes added for
    // % and _ are not doubled.
    protected function escapeLikeTerm($term) {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], (string) $term);
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
              AND `created_date` BETWEEN :from AND :to" . $this->botExclusionSql() . "
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
     * Returns ['device'=>string, 'capture_width'=>int, 'captured_at'=>string, 'dom'=>string(JSON)] or null.
     */
    public function getSnapshot($path, $device) {
        $db = $this->wire('database');
        $stmt = $db->prepare("SELECT `device`,`capture_width`,`captured_at`,`dom_gz`
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
            'device' => (string) $row['device'],
            'capture_width' => (int) $row['capture_width'],
            'captured_at' => (string) $row['captured_at'],
            'dom' => $json,
        ];
    }

    /**
     * Total tracked events (clicks + scroll rows) per device for a path/range.
     * Returns a device=>count map; devices with no events are absent.
     */
    public function getDeviceEventCounts($path, $fromDate, $toDate) {
        $db = $this->wire('database');
        $stmt = $db->prepare("SELECT `device`, COUNT(*) AS c FROM `" . self::EVENTS_TABLE . "`
            WHERE `path_hash`=:ph AND `created_date` BETWEEN :from AND :to" . $this->botExclusionSql() . "
            GROUP BY `device`");
        $stmt->execute([
            ':ph' => md5('/' . ltrim((string) $path, '/')),
            ':from' => (string) $fromDate,
            ':to' => (string) $toDate,
        ]);
        return $stmt->fetchAll(\PDO::FETCH_KEY_PAIR);
    }

    /**
     * Click counts grouped by CSS selector for a path/device/date range.
     * Returns rows: ['selector'=>string, 'label'=>string, 'c'=>count], descending
     * by count. `label` is a representative human-readable label (link/button text,
     * aria-label, etc.); MAX() prefers a non-empty one, and it stays '' for older
     * clicks captured before labels were collected.
     */
    public function getClickSelectorHeatmap($path, $device, $fromDate, $toDate) {
        $db = $this->wire('database');
        $sql = "SELECT `selector`, MAX(`label`) AS label, COUNT(*) AS c FROM `" . self::EVENTS_TABLE . "`
            WHERE `type`='click' AND `path_hash`=:ph AND `device`=:dev
              AND `created_date` BETWEEN :from AND :to AND `selector` <> ''" . $this->botExclusionSql() . "
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
     * Copy counts grouped by CSS selector for a path/device/date range. Mirrors
     * getClickSelectorHeatmap but for `type='copy'` events: which elements
     * visitors copied text from, by frequency. The copied text itself is never
     * stored — only the source element's selector and the same label clicks use.
     */
    public function getCopySelectorHeatmap($path, $device, $fromDate, $toDate) {
        $db = $this->wire('database');
        $sql = "SELECT `selector`, MAX(`label`) AS label, COUNT(*) AS c FROM `" . self::EVENTS_TABLE . "`
            WHERE `type`='copy' AND `path_hash`=:ph AND `device`=:dev
              AND `created_date` BETWEEN :from AND :to AND `selector` <> ''" . $this->botExclusionSql() . "
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
              AND `created_date` BETWEEN :from AND :to" . $this->botExclusionSql() . "
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

    /**
     * Frustration-signal clicks (dead or rage) grouped by selector for a
     * path/device/date range. $flag is 'dead' or 'rage'. Returns rows:
     * ['selector'=>string, 'label'=>string, 'c'=>count] descending by count.
     */
    protected function getFrustrationClicks($flag, $path, $device, $fromDate, $toDate) {
        $col = $flag === 'rage' ? 'rage' : 'dead';
        $db = $this->wire('database');
        $sql = "SELECT `selector`, MAX(`label`) AS label, COUNT(*) AS c FROM `" . self::EVENTS_TABLE . "`
            WHERE `type`='click' AND `$col`=1 AND `path_hash`=:ph AND `device`=:dev
              AND `created_date` BETWEEN :from AND :to AND `selector` <> ''" . $this->botExclusionSql() . "
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

    public function getDeadClicks($path, $device, $fromDate, $toDate) {
        return $this->getFrustrationClicks('dead', $path, $device, $fromDate, $toDate);
    }

    public function getRageClicks($path, $device, $fromDate, $toDate) {
        return $this->getFrustrationClicks('rage', $path, $device, $fromDate, $toDate);
    }

    /**
     * Raw click data for a pixel-density heatmap. Returns up to $limit compact
     * [x_frac, y_px, dh, offx, offy, selector] tuples, newest first. The overlay
     * anchors each blob to the clicked element resolved by `selector` in the
     * rebuilt backdrop, placing it at the recorded element-relative offset
     * (offx/offy are 0..1000 fractions of the element's box); when the selector
     * no longer resolves it falls back to the page-fraction coordinates (x_frac
     * is 0..1000 of doc width; y_px / dh gives the vertical fraction). Each row
     * is one click, so repeated clicks naturally weight the density.
     */
    public function getClickCoordinates($path, $device, $fromDate, $toDate, $limit = 5000) {
        $db = $this->wire('database');
        $limit = max(1, min(20000, (int) $limit));
        $sql = "SELECT `x_frac`, `y_px`, `dh`, `offx`, `offy`, `selector` FROM `" . self::EVENTS_TABLE . "`
            WHERE `type`='click' AND `path_hash`=:ph AND `device`=:dev
              AND `created_date` BETWEEN :from AND :to AND `dh` > 0" . $this->botExclusionSql() . "
            ORDER BY `id` DESC LIMIT $limit";
        $stmt = $db->prepare($sql);
        $stmt->execute([
            ':ph' => md5('/' . ltrim((string) $path, '/')),
            ':dev' => (string) $device,
            ':from' => (string) $fromDate,
            ':to' => (string) $toDate,
        ]);
        $out = [];
        foreach($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $out[] = [(int) $r['x_frac'], (int) $r['y_px'], (int) $r['dh'], (int) $r['offx'], (int) $r['offy'], (string) $r['selector']];
        }
        return $out;
    }

    /**
     * Whether a stored backdrop exists for a (path_hash, device) bucket. Cheap
     * existence check used to flag journey pages that can be replayed vs. shown
     * on a placeholder — it does NOT decode the DOM blob (getSnapshot does).
     */
    public function snapshotExistsForHash($pathHash, $device) {
        $db = $this->wire('database');
        $stmt = $db->prepare("SELECT 1 FROM `" . self::SNAPSHOT_TABLE . "`
            WHERE `path_hash`=:ph AND `device`=:dev LIMIT 1");
        $stmt->execute([':ph' => (string) $pathHash, ':dev' => (string) $device]);
        return (bool) $stmt->fetchColumn();
    }

    /**
     * Sessions that interacted with $path within [$from,$to], most-recently
     * active first. The page-and-range filter selects na_session_hashes from
     * nab_events (NOT pwna_hits): nab_events keys on the full path including URL
     * segments, so segment landing pages like /products/sale are distinguishable,
     * whereas pwna_hits collapses them to the canonical page (/sign-up/) and so
     * can't isolate a single segment. The outer aggregate still reports each
     * qualifying session's FULL stats (start time, distinct page count, device)
     * from pwna_hits across all its hits, joined on the shared session_hash, so
     * the list row reflects the whole visit even if it began before $from.
     * Interaction counts and frustration flags are left-joined from nab_events via
     * the collation-coerced na_session_hash; sessions with no captured clicks/copies
     * still appear with a zero count. Only sessions recorded since na_session_hash
     * shipped qualify (older interactions carry an empty hash).
     *
     * @return array<int,array{session_hash:string,started_at:string,last_at:string,device:string,page_count:int,click_count:int,copy_count:int,max_scroll:int,has_dead:int,has_rage:int}>
     */
    public function getSessionsForPath($path, $from, $to, $limit = 50) {
        if(!$this->hasHitsTable()) return [];
        $db = $this->wire('database');
        $limit = max(1, min(200, (int) $limit));
        $collate = $this->naHashCollation();
        $sql = "SELECT h.`session_hash` AS session_hash,
                       MIN(h.`created_at`) AS started_at,
                       MAX(h.`created_at`) AS last_at,
                       MAX(h.`device_type`) AS device,
                       COUNT(DISTINCT h.`path_hash`) AS page_count,
                       COALESCE(e.`clicks`, 0) AS click_count,
                       COALESCE(e.`copies`, 0) AS copy_count,
                       COALESCE(e.`max_scroll`, 0) AS max_scroll,
                       COALESCE(e.`has_dead`, 0) AS has_dead,
                       COALESCE(e.`has_rage`, 0) AS has_rage,
                       entry.`referrer_host` AS referrer_host,
                       entry.`utm_source` AS utm_source,
                       entry.`utm_medium` AS utm_medium,
                       entry.`utm_campaign` AS utm_campaign
                FROM `pwna_hits` h
                LEFT JOIN (
                    SELECT `na_session_hash`,
                           SUM(`type`='click') AS clicks,
                           SUM(`type`='copy') AS copies,
                           MAX(`dead`) AS has_dead,
                           MAX(`rage`) AS has_rage,
                           MAX(`scroll_pct`) AS max_scroll
                    FROM `" . self::EVENTS_TABLE . "`
                    WHERE `na_session_hash` <> ''
                    GROUP BY `na_session_hash`
                ) e ON e.`na_session_hash`$collate = h.`session_hash`
                LEFT JOIN (
                    SELECT eh.`session_hash` AS sh,
                           eh.`referrer_host` AS referrer_host,
                           eh.`utm_source` AS utm_source,
                           eh.`utm_medium` AS utm_medium,
                           eh.`utm_campaign` AS utm_campaign
                    FROM `pwna_hits` eh
                    INNER JOIN (
                        SELECT `session_hash`, MIN(`id`) AS first_id
                        FROM `pwna_hits`
                        WHERE `session_hash` <> ''
                        GROUP BY `session_hash`
                    ) fh ON fh.`first_id` = eh.`id`
                ) entry ON entry.`sh` = h.`session_hash`
                WHERE h.`session_hash` <> '' AND h.`session_hash` IN (
                    SELECT `na_session_hash`$collate FROM `" . self::EVENTS_TABLE . "`
                    WHERE `path_hash`=:ph AND `created_date` BETWEEN :from AND :to
                      AND `na_session_hash` <> ''" . $this->botExclusionSql() . "
                )
                GROUP BY h.`session_hash`
                ORDER BY last_at DESC
                LIMIT " . $limit;
        $stmt = $db->prepare($sql);
        $stmt->execute([
            ':ph' => md5('/' . ltrim((string) $path, '/')),
            ':from' => (string) $from,
            ':to' => (string) $to,
        ]);
        $out = [];
        foreach($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $dev = (string) $r['device'];
            if(!in_array($dev, ['desktop', 'tablet', 'mobile'], true)) $dev = 'desktop';
            $out[] = [
                'session_hash' => (string) $r['session_hash'],
                'started_at' => (string) $r['started_at'],
                'last_at' => (string) $r['last_at'],
                'device' => $dev,
                'page_count' => (int) $r['page_count'],
                'click_count' => (int) $r['click_count'],
                'copy_count' => (int) $r['copy_count'],
                'max_scroll' => (int) $r['max_scroll'],
                'has_dead' => ((int) $r['has_dead']) ? 1 : 0,
                'has_rage' => ((int) $r['has_rage']) ? 1 : 0,
                'referrer_host' => trim((string) $r['referrer_host']),
                'utm_source' => trim((string) $r['utm_source']),
                'utm_medium' => trim((string) $r['utm_medium']),
                'utm_campaign' => trim((string) $r['utm_campaign']),
            ];
        }
        return $out;
    }

    /**
     * Count of distinct sessions that qualify for getSessionsForPath() (same
     * page-and-range filter). Backs the list's "showing N of M" note without
     * paging the full set.
     */
    public function countSessionsForPath($path, $from, $to) {
        if(!$this->hasHitsTable()) return 0;
        $db = $this->wire('database');
        $collate = $this->naHashCollation();
        $stmt = $db->prepare("SELECT COUNT(DISTINCT h.`session_hash`) FROM `pwna_hits` h
            WHERE h.`session_hash` <> '' AND h.`session_hash` IN (
                SELECT `na_session_hash`$collate FROM `" . self::EVENTS_TABLE . "`
                WHERE `path_hash`=:ph AND `created_date` BETWEEN :from AND :to
                  AND `na_session_hash` <> ''" . $this->botExclusionSql() . "
            )");
        $stmt->execute([
            ':ph' => md5('/' . ltrim((string) $path, '/')),
            ':from' => (string) $from,
            ':to' => (string) $to,
        ]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * One session's cross-page journey, oldest page first, plus session-level
     * context from the entry (earliest) hit. Distinct pages are merged in
     * first-visit order: per-visit interaction attribution is impossible from
     * aggregate nab_events (clicks are stored per path_hash, not per pageview),
     * so a revisited path appears once with its time_on_page summed and its
     * visit_count noted. Canonical pages that the session reached via a segment URL
     * segment are specialized back to the per-segment path (see below) so the rail,
     * its backdrop, and its interaction count match the page the visitor saw.
     * Returns null for a malformed hash, when NA's hits table is absent, or when
     * the session has no hits.
     *
     * @return array{device:string,browser:string,os:string,landing:string,referrer_host:string,referrer_url:string,utm_source:string,utm_medium:string,utm_campaign:string,pages:array<int,array{path:string,path_hash:string,page_title:string,time_on_page:int,visit_count:int,interaction_count:int,max_scroll:int,has_backdrop:bool}>}|null
     */
    public function getSessionJourney($sessionHash) {
        $sessionHash = (string) $sessionHash;
        if(!preg_match('/^[a-f0-9]{64}$/', $sessionHash)) return null;
        if(!$this->hasHitsTable()) return null;
        $db = $this->wire('database');

        $ctxStmt = $db->prepare("SELECT `referrer_host`,`referrer_url`,`browser`,`os`,`device_type`,
                `utm_source`,`utm_medium`,`utm_campaign`,`path` AS landing
            FROM `pwna_hits`
            WHERE `session_hash`=:sh AND `session_hash` <> ''
            ORDER BY `created_at` ASC, `id` ASC LIMIT 1");
        $ctxStmt->execute([':sh' => $sessionHash]);
        $ctx = $ctxStmt->fetch(\PDO::FETCH_ASSOC);
        if(!$ctx) return null;
        $device = (string) $ctx['device_type'];
        if(!in_array($device, ['desktop', 'tablet', 'mobile'], true)) $device = 'desktop';

        $pagesStmt = $db->prepare("SELECT `path`, `path_hash`,
                MAX(`page_title`) AS page_title,
                MIN(`created_at`) AS first_at,
                SUM(`time_on_page`) AS time_on_page,
                COUNT(*) AS visit_count
            FROM `pwna_hits`
            WHERE `session_hash`=:sh AND `session_hash` <> ''
            GROUP BY `path_hash`, `path`
            ORDER BY first_at ASC
            LIMIT 100");
        $pagesStmt->execute([':sh' => $sessionHash]);
        $pageRows = $pagesStmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        // Per-page interaction totals for this session (matched column-to-param,
        // so no COLLATE needed). Authoritative for the rail count and the
        // "+N more" marker cap when getSessionInteractions caps its rows.
        $countStmt = $db->prepare("SELECT `path_hash`, COUNT(*) AS ic
            FROM `" . self::EVENTS_TABLE . "`
            WHERE `na_session_hash`=:sh AND `type` IN ('click','copy')
            GROUP BY `path_hash`");
        $countStmt->execute([':sh' => $sessionHash]);
        $counts = [];
        foreach($countStmt->fetchAll(\PDO::FETCH_ASSOC) as $c) {
            $counts[(string) $c['path_hash']] = (int) $c['ic'];
        }

        // Per-page deepest scroll for this session. Scroll is stored as one row
        // per (session, page, device) holding the max scroll_pct reached, keyed
        // on the full per-segment path_hash, so it lines up with the specialized
        // page entries below (and the per-segment click counts).
        $scrollStmt = $db->prepare("SELECT `path_hash`, MAX(`scroll_pct`) AS max_scroll
            FROM `" . self::EVENTS_TABLE . "`
            WHERE `na_session_hash`=:sh AND `type`='scroll' AND `path_hash` <> ''
            GROUP BY `path_hash`");
        $scrollStmt->execute([':sh' => $sessionHash]);
        $scroll = [];
        foreach($scrollStmt->fetchAll(\PDO::FETCH_ASSOC) as $sc) {
            $scroll[(string) $sc['path_hash']] = (int) $sc['max_scroll'];
        }

        // Distinct full paths (any event type) this session touched. pwna_hits
        // collapses segment URL segments to the canonical page, so a /products/sale
        // visit is keyed '/sign-up/' in $pageRows but '/products/sale' here. We use
        // these to "specialize" each canonical rail page back to the segment variant
        // the session actually saw, so the rail path, its backdrop, and its
        // interaction count all key on the per-segment path_hash (matching the
        // heatmap, the snapshot, and the cfg.path the viewer opens on).
        $evStmt = $db->prepare("SELECT DISTINCT `path`, `path_hash`
            FROM `" . self::EVENTS_TABLE . "`
            WHERE `na_session_hash`=:sh AND `path_hash` <> '' AND `path` <> ''
            LIMIT 300");
        $evStmt->execute([':sh' => $sessionHash]);
        $eventPaths = $evStmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        // Map canonical pwna path_hash => list of per-segment variants. A variant is
        // an event path whose longest matching canonical prefix (among this session's
        // own pwna pages, excluding the '/' catch-all) is that page, and which keys
        // differently from the canonical (i.e. it carries a URL segment).
        $variants = [];
        foreach($eventPaths as $ep) {
            $f = (string) $ep['path'];
            $fh = (string) $ep['path_hash'];
            $bestHash = ''; $bestLen = 0;
            foreach($pageRows as $r) {
                $cp = (string) $r['path'];
                if($cp === '' || $cp === '/') continue;
                if(strpos($f, $cp) === 0 && strlen($cp) > $bestLen) {
                    $bestLen = strlen($cp);
                    $bestHash = (string) $r['path_hash'];
                }
            }
            if($bestHash === '' || $fh === $bestHash) continue;
            $variants[$bestHash][] = ['path' => $f, 'path_hash' => $fh];
        }

        $pages = [];
        foreach($pageRows as $r) {
            $ph = (string) $r['path_hash'];
            $vs = $variants[$ph] ?? [];
            if(!$vs) {
                $pages[] = [
                    'path' => (string) $r['path'],
                    'path_hash' => $ph,
                    'page_title' => (string) $r['page_title'],
                    'time_on_page' => (int) $r['time_on_page'],
                    'visit_count' => (int) $r['visit_count'],
                    'interaction_count' => $counts[$ph] ?? 0,
                    'max_scroll' => $scroll[$ph] ?? 0,
                    'has_backdrop' => $this->snapshotExistsForHash($ph, $device),
                ];
                continue;
            }
            // One rail entry per segment variant. pwna_hits only tracks the collapsed
            // page, so per-variant durations aren't recoverable: attribute the
            // canonical pageview stats to the first variant and mark the rest as
            // single zero-duration visits (a session almost always arrives via one
            // segment, so multiple variants of the same page is a rare edge).
            $first = true;
            foreach($vs as $v) {
                $vh = (string) $v['path_hash'];
                $pages[] = [
                    'path' => (string) $v['path'],
                    'path_hash' => $vh,
                    'page_title' => (string) $r['page_title'],
                    'time_on_page' => $first ? (int) $r['time_on_page'] : 0,
                    'visit_count' => $first ? (int) $r['visit_count'] : 1,
                    'interaction_count' => $counts[$vh] ?? 0,
                    'max_scroll' => $scroll[$vh] ?? 0,
                    'has_backdrop' => $this->snapshotExistsForHash($vh, $device),
                ];
                $first = false;
            }
        }

        return [
            'device' => $device,
            'browser' => (string) $ctx['browser'],
            'os' => (string) $ctx['os'],
            'landing' => (string) $ctx['landing'],
            'referrer_host' => (string) $ctx['referrer_host'],
            'referrer_url' => (string) $ctx['referrer_url'],
            'utm_source' => (string) $ctx['utm_source'],
            'utm_medium' => (string) $ctx['utm_medium'],
            'utm_campaign' => (string) $ctx['utm_campaign'],
            'pages' => $pages,
        ];
    }

    /**
     * One session's clicks/copies on a single page, oldest first, for marker
     * rendering. Matched column-to-param so no COLLATE is needed. Capped (D9):
     * up to $limit rows (default 200); callers compute "+N more" from the
     * journey's authoritative interaction_count.
     *
     * @return array<int,array{type:string,x_frac:int,y_px:int,dh:int,offx:int,offy:int,selector:string,label:string,dead:int,rage:int,t:string}>
     */
    public function getSessionInteractions($sessionHash, $pathHash, $device, $limit = 200) {
        $sessionHash = (string) $sessionHash;
        if(!preg_match('/^[a-f0-9]{64}$/', $sessionHash)) return [];
        $limit = max(1, min(500, (int) $limit));
        $device = in_array((string) $device, ['desktop', 'tablet', 'mobile'], true) ? (string) $device : 'desktop';
        $db = $this->wire('database');
        $stmt = $db->prepare("SELECT `type`,`x_frac`,`y_px`,`dh`,`offx`,`offy`,`selector`,`label`,`dead`,`rage`,`created_at`
            FROM `" . self::EVENTS_TABLE . "`
            WHERE `na_session_hash`=:sh AND `path_hash`=:ph AND `device`=:dev
              AND `type` IN ('click','copy')
            ORDER BY `created_at` ASC, `id` ASC
            LIMIT " . $limit);
        $stmt->execute([':sh' => $sessionHash, ':ph' => (string) $pathHash, ':dev' => $device]);
        $out = [];
        foreach($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $out[] = [
                'type' => (string) $r['type'],
                'x_frac' => (int) $r['x_frac'],
                'y_px' => (int) $r['y_px'],
                'dh' => (int) $r['dh'],
                'offx' => (int) $r['offx'],
                'offy' => (int) $r['offy'],
                'selector' => (string) $r['selector'],
                'label' => (string) $r['label'],
                'dead' => ((int) $r['dead']) ? 1 : 0,
                'rage' => ((int) $r['rage']) ? 1 : 0,
                't' => (string) $r['created_at'],
            ];
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

        $f = $modules->get('InputfieldCheckbox');
        $f->name = 'excludeNaBots';
        $f->label = 'Exclude NativeAnalytics bot sessions';
        $f->label2 = 'Hide clicks and scrolls from sessions NativeAnalytics has flagged as bots';
        $f->description = "Reuses NativeAnalytics' bot determination (UA detection plus its hourly behavioral classifier). Only sessions recorded after this setting shipped carry the matching hash, so older rows are always shown.";
        $f->attr('checked', !empty($data['excludeNaBots']));
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
