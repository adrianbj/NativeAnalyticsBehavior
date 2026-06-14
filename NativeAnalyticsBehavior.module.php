<?php namespace ProcessWire;

class NativeAnalyticsBehavior extends WireData implements Module, ConfigurableModule {

    const EVENTS_TABLE = 'nab_events';
    const COLLECT_ROUTE = '/nab-collect';
    const SNAPSHOT_TABLE = 'nab_snapshots';
    const SNAPSHOT_ROUTE = '/nab-snapshot';
    const SNAPSHOT_MAX_BYTES = 4194304; // 4 MB raw upload cap (DOM + inlined CSS)

    protected $defaults = [
        'enabled' => 1,
        'enableHeatmaps' => 1,
        'sampleRate' => 100,      // percent of pageloads that collect
        'retentionDays' => 60,
        'excludedPaths' => '',    // newline-separated path prefixes
        'excludedTemplates' => '', // newline-separated template names
        'excludedRoles' => '',    // newline-separated role names (superuser always excluded)
        'blockedIps' => '',       // newline-separated IPs
        'excludeNaBots' => 1,     // hide sessions NativeAnalytics flagged as bots
    ];

    public static function getModuleInfo() {
        return [
            'title' => 'NativeAnalyticsBehavior',
            'summary' => 'Behavioral analytics companion for NativeAnalytics: heatmaps, insights and session recordings.',
            'version' => '0.1.0',
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
        // Versioned snapshots (D2): one row per DISTINCT DOM version of a
        // (path, device), not one row per bucket. A version covers the time
        // interval [captured_at, next version's captured_at); a session at time T
        // selects the version with the greatest captured_at <= T. `dom_hash` is the
        // sha256 of the stored DOM JSON, used at ingest to dedup an unchanged
        // recapture against the latest version. No UNIQUE on (path_hash, device):
        // multiple versions coexist.
        $db->exec("CREATE TABLE IF NOT EXISTS `" . self::SNAPSHOT_TABLE . "` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `path` VARCHAR(767) NOT NULL DEFAULT '',
            `path_hash` CHAR(32) NOT NULL DEFAULT '',
            `device` VARCHAR(16) NOT NULL DEFAULT '',
            `capture_width` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            `dom_hash` CHAR(64) NOT NULL DEFAULT '',
            `captured_at` DATETIME NOT NULL,
            `dom_gz` MEDIUMBLOB NOT NULL,
            PRIMARY KEY (`id`),
            KEY `ver` (`path_hash`, `device`, `captured_at`),
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
        $this->migrateSnapshotVersioning($db);
        $done = true;
    }

    /**
     * Migrate an existing nab_snapshots table to the versioned model (D2). The
     * CREATE TABLE above is a no-op on installs that already have the single-row-
     * per-bucket schema, so convert it idempotently here: add `dom_hash`, drop the
     * `bucket` UNIQUE (so versions can coexist), drop the now-unused
     * `captured_modified`, add the `ver` index, and backfill `dom_hash` for the
     * pre-existing rows so the first recapture of an unchanged page dedups instead
     * of inserting a spurious duplicate version.
     */
    protected function migrateSnapshotVersioning($db) {
        $tbl = self::SNAPSHOT_TABLE;
        $col = $db->query("SHOW COLUMNS FROM `$tbl` LIKE 'dom_hash'");
        if($col && $col->rowCount() === 0) {
            $db->exec("ALTER TABLE `$tbl` ADD `dom_hash` CHAR(64) NOT NULL DEFAULT '' AFTER `capture_width`");
        }
        $idx = $db->query("SHOW INDEX FROM `$tbl` WHERE Key_name='bucket'");
        if($idx && $idx->rowCount() > 0) {
            $db->exec("ALTER TABLE `$tbl` DROP INDEX `bucket`");
        }
        $idx = $db->query("SHOW INDEX FROM `$tbl` WHERE Key_name='ver'");
        if($idx && $idx->rowCount() === 0) {
            $db->exec("ALTER TABLE `$tbl` ADD KEY `ver` (`path_hash`, `device`, `captured_at`)");
        }
        // Backfill dom_hash over the same string ingest hashes (the gunzipped
        // dom_gz IS that JSON), so an unchanged recapture matches the latest row.
        $rows = $db->query("SELECT `id`,`dom_gz` FROM `$tbl` WHERE `dom_hash`=''");
        if($rows) {
            $upd = $db->prepare("UPDATE `$tbl` SET `dom_hash`=:h WHERE `id`=:id");
            foreach($rows->fetchAll(\PDO::FETCH_ASSOC) as $r) {
                $json = @gzdecode($r['dom_gz']);
                if($json === false) continue;
                $upd->execute([':h' => hash('sha256', $json), ':id' => (int) $r['id']]);
            }
        }
        $col = $db->query("SHOW COLUMNS FROM `$tbl` LIKE 'captured_modified'");
        if($col && $col->rowCount() > 0) {
            $db->exec("ALTER TABLE `$tbl` DROP COLUMN `captured_modified`");
        }
    }
    protected function shouldInjectCurrentRequest() {
        if(!$this->enabled || !$this->enableHeatmaps) return false;
        if($this->wire('config')->ajax) return false;
        // Never track superusers: their admin and testing browsing would pollute
        // the data and cause confusion. Additional roles can be excluded via the
        // "Excluded roles" setting (e.g. staff/editors); everyone else — including
        // guests and ordinary logged-in members — is tracked normally.
        $u = $this->wire('user');
        if($u->isSuperuser() || $this->userHasExcludedRole($u)) return false;
        $page = $this->wire('page');
        if(!$page || !$page->id) return false;
        if($this->isExcludedTemplate($page)) return false;
        if($this->isExcludedPath()) return false;
        return true;
    }

    protected function configLines($key) {
        return $this->normalizeList($this->get($key));
    }

    /**
     * Normalize a config value to a list of non-empty trimmed strings. Accepts an
     * array (InputfieldAsmSelect) or a newline-separated string (InputfieldTextarea),
     * so list settings read the same way regardless of input control.
     */
    protected function normalizeList($val) {
        if(is_array($val)) {
            $out = [];
            foreach($val as $v) {
                $v = trim((string) $v);
                if($v !== '') $out[] = $v;
            }
            return $out;
        }
        $raw = (string) $val;
        if($raw === '') return [];
        $out = [];
        foreach(preg_split('/\r\n|\r|\n/', $raw) as $line) {
            $line = trim($line);
            if($line !== '') $out[] = $line;
        }
        return $out;
    }

    protected function userHasExcludedRole(User $u) {
        $roles = $this->configLines('excludedRoles');
        if(!$roles) return false;
        foreach($roles as $role) {
            if($u->hasRole($role)) return true;
        }
        return false;
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
        // The client captures once per session per path and always uploads; the
        // ingest endpoint hash-dedups against the latest stored version, so there
        // is no per-request freshness state to inject (D2 versioned snapshots).
        $payload['snapshotEndpoint'] = $this->getSnapshotEndpointUrl();
        $payload['snapshotLib'] = $this->getVersionedAssetUrl('assets/vendor/rrweb-snapshot.js');

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

    /**
     * Mirror the companion NativeAnalytics consent gate: when it requires consent
     * and the consent cookie is absent, behavior data must not be stored either.
     * Closes the direct-POST path; the client collector already gates on PWNA_CONFIG.
     */
    protected function consentBlocked() {
        $data = $this->wire('modules')->getModuleConfigData('NativeAnalytics');
        if(empty($data['requireConsent'])) return false;
        $name = !empty($data['consentCookieName']) ? (string) $data['consentCookieName'] : 'pwna_consent';
        return empty($_COOKIE[$name]);
    }

    protected function handleCollectRequest() {
        if(!$this->enabled || !$this->enableHeatmaps) $this->sendJson(204, ['ok' => true]);
        if(($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') $this->sendJson(405, ['ok' => false]);
        if(in_array($this->clientIp(), $this->configLines('blockedIps'), true)) $this->sendJson(204, ['ok' => true]);
        if($this->consentBlocked()) $this->sendJson(204, ['ok' => true]);

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
        if($this->consentBlocked()) $this->sendJson(204, ['ok' => true]);

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

        $now = date('Y-m-d H:i:s');
        $db = $this->wire('database');
        $pathHash = md5($path);

        // JSON_HEX_TAG escapes < and > so the stored DOM can be safely embedded in a
        // <script type="application/json"> block on the admin dashboard; without it,
        // page text containing the literal "</script>" would break out of the tag.
        $domJson = json_encode($data['dom'], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
        if($domJson === false) $this->sendJson(400, ['ok' => false]);
        $domHash = hash('sha256', $domJson);

        // Version-on-change (D2): the client uploads once per session per path, but
        // the markup only rarely differs between sessions, so store a new version
        // only when this DOM differs from the LATEST stored version for the bucket.
        // Comparing against the latest (not any historical version) is deliberate:
        // an A -> B -> A oscillation correctly yields three intervals, each pointing
        // at the markup live during its window. The endpoint is public, but a forged
        // upload can now only append a new version dated "now" — it can't clobber the
        // history older sessions resolve against.
        $latest = $db->prepare("SELECT `dom_hash` FROM `" . self::SNAPSHOT_TABLE . "`
            WHERE `path_hash`=:ph AND `device`=:device ORDER BY `captured_at` DESC, `id` DESC LIMIT 1");
        $latest->execute([':ph' => $pathHash, ':device' => $device]);
        if($row = $latest->fetch(\PDO::FETCH_ASSOC)) {
            if(((string) $row['dom_hash']) === $domHash) $this->sendJson(200, ['ok' => true, 'unchanged' => true]);
        }

        $gz = gzencode($domJson, 6);
        if($gz === false) $this->sendJson(500, ['ok' => false]);

        $sql = "INSERT INTO `" . self::SNAPSHOT_TABLE . "`
            (`path`,`path_hash`,`device`,`capture_width`,`dom_hash`,`captured_at`,`dom_gz`)
            VALUES (:path,:ph,:device,:w,:dh,:now,:dom)";
        $stmt = $db->prepare($sql);
        $stmt->bindValue(':path', $path);
        $stmt->bindValue(':ph', $pathHash);
        $stmt->bindValue(':device', $device);
        $stmt->bindValue(':w', $width, \PDO::PARAM_INT);
        $stmt->bindValue(':dh', $domHash);
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
    public function getTopPagesBySessions($limit = 25, $from = null, $to = null) {
        $limit = max(1, min(100, (int) $limit));
        $db = $this->wire('database');
        $params = [];
        $dateSql = '';
        if($from !== null && $to !== null) {
            $dateSql = " AND `created_date` BETWEEN :from AND :to";
            $params[':from'] = (string) $from;
            $params[':to'] = (string) $to;
        }
        $stmt = $db->prepare("SELECT `path`, COUNT(DISTINCT `session_hash`) AS c FROM `" . self::EVENTS_TABLE . "`
            WHERE `session_hash` <> ''" . $dateSql . $this->botExclusionSql() . "
            GROUP BY `path` ORDER BY c DESC LIMIT " . $limit);
        $stmt->execute($params);
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
     *
     * Version selection (D2): with $atTime null (aggregate heatmap) the LATEST
     * version is returned. With $atTime set ('Y-m-d H:i:s', a session's time on the
     * page) the version live during that moment is returned — the greatest
     * captured_at <= $atTime. A session predating the earliest stored version (e.g.
     * its original markup aged out of retention) falls back to the earliest one, so
     * the trail still shows the closest available backdrop rather than nothing.
     */
    public function getSnapshot($path, $device, $atTime = null) {
        $db = $this->wire('database');
        $pathHash = md5('/' . ltrim((string) $path, '/'));
        $dev = (string) $device;
        $tbl = self::SNAPSHOT_TABLE;
        $cols = "`device`,`capture_width`,`captured_at`,`dom_gz`";
        $row = null;
        if($atTime !== null && $atTime !== '') {
            $stmt = $db->prepare("SELECT $cols FROM `$tbl`
                WHERE `path_hash`=:ph AND `device`=:dev AND `captured_at`<=:at
                ORDER BY `captured_at` DESC, `id` DESC LIMIT 1");
            $stmt->execute([':ph' => $pathHash, ':dev' => $dev, ':at' => (string) $atTime]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if(!$row) {
                $stmt = $db->prepare("SELECT $cols FROM `$tbl`
                    WHERE `path_hash`=:ph AND `device`=:dev
                    ORDER BY `captured_at` ASC, `id` ASC LIMIT 1");
                $stmt->execute([':ph' => $pathHash, ':dev' => $dev]);
                $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            }
        } else {
            $stmt = $db->prepare("SELECT $cols FROM `$tbl`
                WHERE `path_hash`=:ph AND `device`=:dev
                ORDER BY `captured_at` DESC, `id` DESC LIMIT 1");
            $stmt->execute([':ph' => $pathHash, ':dev' => $dev]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        }
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
     * Distinct sessions per device for a path/range, over the same session
     * universe as the sessions panel (events on this path+range that also
     * exist in pwna_hits). Returns a device=>count map; devices with no
     * sessions are absent. A session spanning two devices counts under each.
     */
    public function getDeviceSessionCounts($path, $fromDate, $toDate) {
        $db = $this->wire('database');
        $collate = $this->naHashCollation();
        $hitsFilter = $this->hasHitsTable()
            ? " AND `na_session_hash`$collate IN (SELECT `session_hash` FROM `pwna_hits` WHERE `session_hash` <> '')"
            : '';
        $stmt = $db->prepare("SELECT `device`, COUNT(DISTINCT `na_session_hash`) AS c FROM `" . self::EVENTS_TABLE . "`
            WHERE `path_hash`=:ph AND `created_date` BETWEEN :from AND :to
              AND `na_session_hash` <> ''" . $this->botExclusionSql() . $hitsFilter . "
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
     * Search counts grouped by term for a path/device/date range, read from
     * NativeAnalytics' pwna_hits (NA extracts the term from results-page URLs
     * via its searchQueryVars setting — this module records nothing itself).
     * Bot hits are excluded via pwna's own is_bot flag when the excludeNaBots
     * setting is on (the nab_events-oriented botExclusionSql doesn't apply to
     * pwna_hits). Returns rows ['label'=>term, 'c'=>count] descending by
     * count; [] when the hits table is absent.
     */
    public function getSearchTermsForPath($path, $device, $fromDate, $toDate) {
        if(!$this->hasHitsTable()) return [];
        $db = $this->wire('database');
        $botSql = empty($this->excludeNaBots) ? '' : " AND `is_bot`=0";
        $sql = "SELECT `search_term` AS label, COUNT(*) AS c FROM `pwna_hits`
            WHERE `path_hash`=:ph AND `search_term` <> '' AND `device_type`=:dev
              AND `created_at` BETWEEN :from AND :to" . $botSql . "
            GROUP BY `search_term` ORDER BY c DESC";
        $stmt = $db->prepare($sql);
        $stmt->execute([
            ':ph' => md5('/' . ltrim((string) $path, '/')),
            ':dev' => (string) $device,
            ':from' => (string) $fromDate . ' 00:00:00',
            ':to' => (string) $toDate . ' 23:59:59',
        ]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Search counts grouped by term for searches INITIATED on a page — the
     * visitor's immediately preceding pageview in the same pwna session —
     * within a device/date range. A search with no prior hit (direct landing
     * on the results page) or no session hash falls back to the results page
     * itself. Complements getSearchTermsForPath(), which counts searches by
     * the results page they LANDED on; the two only overlap on the results
     * page (refinements initiate there, landings arrive there).
     */
    public function getSearchOriginsForPath($path, $device, $fromDate, $toDate) {
        if(!$this->hasHitsTable()) return [];
        $db = $this->wire('database');
        $botSql = empty($this->excludeNaBots) ? '' : " AND h.`is_bot`=0";
        $sql = "SELECT h.`search_term` AS label, COUNT(*) AS c FROM `pwna_hits` h
            WHERE h.`search_term` <> '' AND h.`device_type`=:dev
              AND h.`created_at` BETWEEN :from AND :to" . $botSql . "
              AND (CASE WHEN h.`session_hash` = '' THEN h.`path_hash`
                   ELSE COALESCE((SELECT p2.`path_hash` FROM `pwna_hits` p2
                        WHERE p2.`session_hash` = h.`session_hash` AND p2.`id` < h.`id`
                        ORDER BY p2.`id` DESC LIMIT 1), h.`path_hash`) END) = :ph
            GROUP BY h.`search_term` ORDER BY c DESC";
        $stmt = $db->prepare($sql);
        $stmt->execute([
            ':ph' => md5('/' . ltrim((string) $path, '/')),
            ':dev' => (string) $device,
            ':from' => (string) $fromDate . ' 00:00:00',
            ':to' => (string) $toDate . ' 23:59:59',
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
     * segments, so landing pages like /products/sale are distinguishable,
     * whereas pwna_hits collapses them to the canonical page (/products/) and so
     * can't isolate a single segment variant. The outer aggregate still reports each
     * qualifying session's FULL stats (start time, distinct page count, device)
     * from pwna_hits across all its hits, joined on the shared session_hash, so
     * the list row reflects the whole visit even if it began before $from.
     * Interaction counts and frustration flags are left-joined from nab_events via
     * the collation-coerced na_session_hash; sessions with no captured clicks/copies
     * still appear with a zero count. Only sessions recorded since na_session_hash
     * shipped qualify (older interactions carry an empty hash).
     *
     * $filters (['min_seconds' => int, 'interacted' => bool,
     * 'min_scroll' => int, 'multi_page' => bool]; 0/false disables a criterion)
     * AND together as HAVING clauses over the per-session aggregates.
     *
     * @return array<int,array{session_hash:string,started_at:string,last_at:string,duration:int,device:string,page_count:int,click_count:int,copy_count:int,max_scroll:int,has_dead:int,has_rage:int,referrer_host:string,utm_source:string,utm_medium:string,utm_campaign:string}>
     */
    public function getSessionsForPath($path, $from, $to, $limit = 50, $filters = [], $device = '') {
        if(!$this->hasHitsTable()) return [];
        $db = $this->wire('database');
        $limit = max(1, min(200, (int) $limit));
        $collate = $this->naHashCollation();
        $params = [
            ':ph' => md5('/' . ltrim((string) $path, '/')),
            ':from' => (string) $from,
            ':to' => (string) $to,
        ];
        $deviceSql = '';
        if(in_array($device, ['desktop', 'tablet', 'mobile'], true)) {
            $deviceSql = " AND `device`=:dev";
            $params[':dev'] = $device;
        }
        $having = [];
        if(!empty($filters['min_seconds'])) {
            $having[] = "duration >= :minsec";
            $params[':minsec'] = (int) $filters['min_seconds'];
        }
        if(!empty($filters['interacted'])) {
            $having[] = "(click_count + copy_count) > 0";
        }
        if(!empty($filters['min_scroll'])) {
            $having[] = "max_scroll >= :minscroll";
            $params[':minscroll'] = (int) $filters['min_scroll'];
        }
        if(!empty($filters['multi_page'])) {
            $having[] = "page_count > 1";
        }
        $havingSql = $having ? "
                HAVING " . implode(' AND ', $having) : '';
        $sql = "SELECT h.`session_hash` AS session_hash,
                       MIN(h.`created_at`) AS started_at,
                       MAX(h.`created_at`) AS last_at,
                       SUM(h.`time_on_page`) AS duration,
                       MAX(h.`device_type`) AS device,
                       COUNT(DISTINCT h.`path_hash`) AS page_count,
                       COALESCE(e.`clicks`, 0) AS click_count,
                       COALESCE(e.`copies`, 0) AS copy_count,
                       COALESCE(e.`ms`, 0) AS max_scroll,
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
                           MAX(`scroll_pct`) AS ms
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
                      AND `na_session_hash` <> ''" . $deviceSql . $this->botExclusionSql() . "
                )
                GROUP BY h.`session_hash`" . $havingSql . "
                ORDER BY last_at DESC
                LIMIT " . $limit;
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $out = [];
        foreach($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $dev = (string) $r['device'];
            if(!in_array($dev, ['desktop', 'tablet', 'mobile'], true)) $dev = 'desktop';
            $out[] = [
                'session_hash' => (string) $r['session_hash'],
                'started_at' => (string) $r['started_at'],
                'last_at' => (string) $r['last_at'],
                'duration' => (int) $r['duration'],
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
     * Aggregate stats for the sessions panel, over the sessions that qualify
     * for getSessionsForPath() (same page-and-range selection, bot exclusion,
     * and engagement $filters — see getSessionsForPath for the filter shape).
     * Returns ['total','median_duration','median_pages','scroll_median','median_clicks'], all int:
     * - total: distinct qualifying sessions;
     * - median_duration: median per-session engaged time in whole seconds (the
     *   per-session sum of NativeAnalytics' per-hit time_on_page, so the last
     *   page counts). Unlike NativeAnalytics' own avg_time_on_page (which
     *   skips sessions whose time beacon never landed), zero-time sessions
     *   count toward this median unless a filter removes them;
     * - median_pages: median distinct-page count per qualifying session;
     * - scroll_median: median scroll depth percent on THIS page in range, one
     *   value per filtered session — its deepest pageview on the page (sessions
     *   with no scroll row are skipped). Device-independent, matching the panel
     *   rather than the device-filtered heatmap;
     * - median_clicks: median interaction count (clicks + copies, matching the
     *   'interacted' filter) on THIS page in range, one value per filtered
     *   session (sessions with no interaction count as 0, since zero is
     *   meaningful). Device-independent, like scroll_median.
     * All zeros when the hits table is absent or nothing matches. The page
     * params appear twice under different names (:ph/:ph2 etc.) because PDO
     * allows each named param only once per statement.
     */
    public function getSessionStatsForPath($path, $from, $to, $filters = [], $device = '') {
        $empty = ['total' => 0, 'median_duration' => 0, 'median_pages' => 0, 'scroll_median' => 0, 'median_clicks' => 0];
        if(!$this->hasHitsTable()) return $empty;
        $db = $this->wire('database');
        $collate = $this->naHashCollation();
        $params = [
            ':ph' => md5('/' . ltrim((string) $path, '/')),
            ':from' => (string) $from,
            ':to' => (string) $to,
        ];
        $params[':ph2'] = $params[':ph'];
        $params[':from2'] = $params[':from'];
        $params[':to2'] = $params[':to'];
        $params[':ph3'] = $params[':ph'];
        $params[':from3'] = $params[':from'];
        $params[':to3'] = $params[':to'];
        $deviceSql = '';
        if(in_array($device, ['desktop', 'tablet', 'mobile'], true)) {
            $deviceSql = " AND `device`=:dev";
            $params[':dev'] = $device;
        }
        $having = [];
        if(!empty($filters['min_seconds'])) {
            $having[] = "duration >= :minsec";
            $params[':minsec'] = (int) $filters['min_seconds'];
        }
        if(!empty($filters['interacted'])) {
            $having[] = "interactions > 0";
        }
        if(!empty($filters['min_scroll'])) {
            $having[] = "session_scroll >= :minscroll";
            $params[':minscroll'] = (int) $filters['min_scroll'];
        }
        if(!empty($filters['multi_page'])) {
            $having[] = "page_count > 1";
        }
        $havingSql = $having ? "
                    HAVING " . implode(' AND ', $having) : '';
        // The session-wide events rollup is the heaviest scan here (a full
        // GROUP BY over nab_events) and only feeds the interacted/min_scroll
        // criteria, so it is joined only when one of them is active. Its inner
        // aliases (ic/ms) deliberately differ from the outer COALESCE aliases:
        // MySQL resolves HAVING names against FROM columns before select
        // aliases, and matching names would bind HAVING to the nullable
        // pre-COALESCE columns.
        $needsEvents = !empty($filters['interacted']) || !empty($filters['min_scroll']);
        $eventCols = $needsEvents ? "
                       COALESCE(e.`ic`, 0) AS interactions,
                       COALESCE(e.`ms`, 0) AS session_scroll," : "";
        $eventJoin = $needsEvents ? "
                LEFT JOIN (
                    SELECT `na_session_hash`,
                           SUM(`type` IN ('click','copy')) AS ic,
                           MAX(`scroll_pct`) AS ms
                    FROM `" . self::EVENTS_TABLE . "`
                    WHERE `na_session_hash` <> ''
                    GROUP BY `na_session_hash`
                ) e ON e.`na_session_hash`$collate = h.`session_hash`" : "";
        // Per-session rows rather than SQL aggregates: MySQL has no portable
        // MEDIAN(), so the duration median is computed in PHP below.
        $sql = "SELECT t.`duration`, t.`page_scroll`, t.`page_count`, t.`page_clicks` FROM (
                SELECT h.`session_hash` AS sh,
                       SUM(h.`time_on_page`) AS duration,$eventCols
                       COUNT(DISTINCT h.`path_hash`) AS page_count,
                       MAX(ps.`page_scroll`) AS page_scroll,
                       COALESCE(MAX(pc.`page_clicks`), 0) AS page_clicks
                FROM `pwna_hits` h" . $eventJoin . "
                LEFT JOIN (
                    SELECT `na_session_hash`, MAX(`scroll_pct`) AS page_scroll
                    FROM `" . self::EVENTS_TABLE . "`
                    WHERE `type`='scroll' AND `path_hash`=:ph2
                      AND `created_date` BETWEEN :from2 AND :to2
                      AND `na_session_hash` <> ''" . $this->botExclusionSql() . "
                    GROUP BY `na_session_hash`
                ) ps ON ps.`na_session_hash`$collate = h.`session_hash`
                LEFT JOIN (
                    SELECT `na_session_hash`, COUNT(*) AS page_clicks
                    FROM `" . self::EVENTS_TABLE . "`
                    WHERE `type` IN ('click','copy') AND `path_hash`=:ph3
                      AND `created_date` BETWEEN :from3 AND :to3
                      AND `na_session_hash` <> ''" . $this->botExclusionSql() . "
                    GROUP BY `na_session_hash`
                ) pc ON pc.`na_session_hash`$collate = h.`session_hash`
                WHERE h.`session_hash` <> '' AND h.`session_hash` IN (
                    SELECT `na_session_hash`$collate FROM `" . self::EVENTS_TABLE . "`
                    WHERE `path_hash`=:ph AND `created_date` BETWEEN :from AND :to
                      AND `na_session_hash` <> ''" . $deviceSql . $this->botExclusionSql() . "
                )
                GROUP BY h.`session_hash`" . $havingSql . "
            ) t";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(\PDO::FETCH_NUM);
        if(!$rows) return $empty;
        $durations = [];
        $scrolls = [];
        $pages = [];
        $clicks = [];
        foreach($rows as $r) {
            $durations[] = (int) $r[0];
            if($r[1] !== null) $scrolls[] = (int) $r[1];
            $pages[] = (int) $r[2];
            $clicks[] = (int) $r[3];
        }
        return [
            'total' => count($durations),
            'median_duration' => $this->medianInt($durations),
            'median_pages' => $this->medianInt($pages),
            'scroll_median' => $this->medianInt($scrolls),
            'median_clicks' => $this->medianInt($clicks),
        ];
    }

    /**
     * Median of a list of ints, rounded to the nearest int (even-count lists
     * average the two middle values). 0 for an empty list.
     */
    protected function medianInt(array $values) {
        if(!$values) return 0;
        sort($values);
        $n = count($values);
        $mid = intdiv($n, 2);
        $median = ($n % 2) ? $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2;
        return (int) round($median);
    }

    /**
     * One session's cross-page journey, oldest page first, plus session-level
     * context from the entry (earliest) hit. Distinct pages are merged in
     * first-visit order: per-visit interaction attribution is impossible from
     * aggregate nab_events (clicks are stored per path_hash, not per pageview),
     * so a revisited path appears once with its time_on_page summed and its
     * visit_count noted. Canonical pages that the session reached via a URL
     * segment are specialized back to the per-segment path (see below) so the rail,
     * its backdrop, and its interaction count match the page the visitor saw.
     * Returns null for a malformed hash, when NA's hits table is absent, or when
     * the session has no hits.
     *
     * 'device' is the layout class the session's clicks/copies were recorded
     * under (viewport-based, majority across the session's events) — use it for
     * interaction and snapshot lookups. 'ua_device' is NA's UA-based device_type,
     * for display only; the two differ for e.g. a narrow desktop window.
     *
     * @return array{device:string,ua_device:string,browser:string,os:string,landing:string,referrer_host:string,referrer_url:string,utm_source:string,utm_medium:string,utm_campaign:string,pages:array<int,array{path:string,path_hash:string,page_title:string,time_on_page:int,visit_count:int,interaction_count:int,max_scroll:int,has_backdrop:bool}>}|null
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
        $uaDevice = (string) $ctx['device_type'];
        if(!in_array($uaDevice, ['desktop', 'tablet', 'mobile'], true)) $uaDevice = 'desktop';

        // Layout device for fetching interactions and snapshots. nab_events rows
        // carry the viewport-based class from collector.js deviceClass(), which can
        // disagree with NA's UA-based device_type (a 810px-wide Mac Safari window
        // records clicks as 'tablet'). Filtering interactions by the UA device would
        // silently drop those rows, so use the class the session's clicks were
        // actually recorded under (majority wins on mixed-width sessions).
        $devStmt = $db->prepare("SELECT `device`
            FROM `" . self::EVENTS_TABLE . "`
            WHERE `na_session_hash`=:sh AND `type` IN ('click','copy')
              AND `device` IN ('desktop','tablet','mobile')
            GROUP BY `device`
            ORDER BY COUNT(*) DESC, `device` ASC
            LIMIT 1");
        $devStmt->execute([':sh' => $sessionHash]);
        $device = (string) $devStmt->fetchColumn();
        if(!in_array($device, ['desktop', 'tablet', 'mobile'], true)) $device = $uaDevice;

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
        // collapses URL segments to the canonical page, so a /products/sale
        // visit is keyed '/products/' in $pageRows but '/products/sale' here. We use
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
                    'first_at' => (string) $r['first_at'],
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
                    'first_at' => (string) $r['first_at'],
                    'interaction_count' => $counts[$vh] ?? 0,
                    'max_scroll' => $scroll[$vh] ?? 0,
                    'has_backdrop' => $this->snapshotExistsForHash($vh, $device),
                ];
                $first = false;
            }
        }

        return [
            'device' => $device,
            'ua_device' => $uaDevice,
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

    /**
     * One session's site searches from pwna_hits (search_term is extracted by
     * NativeAnalytics from results-page URLs), oldest first, with both the results
     * page's path_hash and the resolved origin_hash (the session's preceding pageview,
     * falling back to the results page) so the journey endpoint can attach each search
     * to the page it was initiated on. Returns [['path_hash','origin_hash','label','t'], ...]; []
     * for a malformed hash or when the hits table is absent.
     */
    public function getSessionSearches($sessionHash) {
        $sessionHash = (string) $sessionHash;
        if(!preg_match('/^[a-f0-9]{64}$/', $sessionHash)) return [];
        if(!$this->hasHitsTable()) return [];
        $db = $this->wire('database');
        $stmt = $db->prepare("SELECT h.`path_hash`, h.`search_term`, h.`created_at`,
                COALESCE((SELECT p2.`path_hash` FROM `pwna_hits` p2
                    WHERE p2.`session_hash` = h.`session_hash` AND p2.`id` < h.`id`
                    ORDER BY p2.`id` DESC LIMIT 1), h.`path_hash`) AS origin_hash
            FROM `pwna_hits` h
            WHERE h.`session_hash`=:sh AND h.`search_term` <> ''
            ORDER BY h.`created_at` ASC, h.`id` ASC
            LIMIT 100");
        $stmt->execute([':sh' => $sessionHash]);
        $out = [];
        foreach($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $out[] = [
                'path_hash' => (string) $r['path_hash'],
                'origin_hash' => (string) $r['origin_hash'],
                'label' => (string) $r['search_term'],
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

        $f = $modules->get('InputfieldAsmSelect');
        $f->name = 'excludedTemplates';
        $f->label = 'Excluded templates';
        $f->description = 'Pages using any selected template are never tracked.';
        foreach($this->wire('templates') as $t) {
            if($t->flags & Template::flagSystem) continue;
            $f->addOption($t->name, $t->name);
        }
        $f->value = $this->normalizeList($data['excludedTemplates']);
        $wrap->add($f);

        $f = $modules->get('InputfieldAsmSelect');
        $f->name = 'excludedRoles';
        $f->label = 'Excluded roles';
        $f->description = 'Sessions from users with any selected role are never tracked. Superusers are always excluded regardless of this list.';
        foreach($this->wire('roles') as $role) {
            if($role->name === 'guest' || $role->name === 'superuser') continue;
            $f->addOption($role->name, $role->name);
        }
        $f->value = $this->normalizeList($data['excludedRoles']);
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
