<?php
/**
 * Plugin Name:       Managed WP Migrator
 * Description:       Copies this website into Managed WP. Paste the migration key from Managed WP under Tools → Migrate to Managed WP.
 * Version:           1.0.0
 * Requires at least: 5.0
 * Requires PHP:      7.4
 * Author:            Managed WP
 * License:           GPL-2.0-or-later
 * Text Domain:       managed-wp-migrator
 *
 * How it works: Managed WP pulls this website in small, signed requests, so it
 * runs on hosts with short time and memory limits and needs no FTP or database
 * passwords. Every request to the export endpoints must carry an HMAC-SHA256
 * signature made with the secret from the migration key; nothing is served
 * without it. wp-config.php and WordPress core files are never sent. The
 * secret is deleted when the copy finishes, when the plugin is deactivated,
 * or with Disconnect.
 */
defined('ABSPATH') || exit;

final class Managed_WP_Migrator
{
    const VERSION = '1.0.0';

    /** The connection: panel URL, source token, secret, and the migration page URL. */
    const OPTION = 'managed_wp_migrator';

    const KEY_PREFIX = 'mwp1_';

    const REST_NAMESPACE = 'managed-wp-migrator/v1';

    const ENDPOINTS = ['info', 'tables', 'table-chunk', 'files', 'file-chunk', 'finish'];

    /** How far a request's timestamp may be from now, in seconds. */
    const SIGNATURE_TOLERANCE = 300;

    /** Rows are fetched in batches of this many while a chunk is built. */
    const ROW_BATCH = 200;

    const DEFAULT_CHUNK_BYTES = 2097152;

    const MAX_CHUNK_BYTES = 8388608;

    const MAX_STATEMENT_BYTES = 1048576;

    const MANIFEST_PAGE = 2000;

    const MAX_BATCH_FILES = 500;

    /** Seconds spent listing files per request, so slow disks never hit the host's time limit. */
    const LISTING_SECONDS = 15;

    const ESTIMATE_SECONDS = 8;

    /**
     * Paths never sent, relative to the website root. wp-config.php holds
     * this host's secrets; core comes from WordPress.org on the new website;
     * caches, backups and drop-ins would break or bloat the copy; the Managed
     * WP helpers and the object cache plugin are the new website's own.
     */
    const EXCLUDED = [
        'wp-config.php',
        'wp-admin',
        'wp-includes',
        'index.php',
        'license.txt',
        'readme.html',
        'xmlrpc.php',
        'wp-activate.php',
        'wp-blog-header.php',
        'wp-comments-post.php',
        'wp-config-sample.php',
        'wp-cron.php',
        'wp-links-opml.php',
        'wp-load.php',
        'wp-login.php',
        'wp-mail.php',
        'wp-settings.php',
        'wp-signup.php',
        'wp-trackback.php',
        '.htaccess',
        '.user.ini',
        'php.ini',
        '.git',
        '.svn',
        '.well-known',
        'cgi-bin',
        '.maintenance',
        'wp-content/cache',
        'wp-content/upgrade',
        'wp-content/upgrade-temp-backup',
        'wp-content/debug.log',
        'wp-content/object-cache.php',
        'wp-content/advanced-cache.php',
        'wp-content/db.php',
        'wp-content/updraft',
        'wp-content/ai1wm-backups',
        'wp-content/backups-dup-lite',
        'wp-content/backups-dup-pro',
        'wp-content/wpvividbackups',
        'wp-content/backup-db',
        'wp-content/wflogs',
        'wp-content/et-cache',
        'wp-content/litespeed',
        'wp-content/plugins/managed-wp-migrator',
        'wp-content/plugins/redis-cache',
    ];

    /**
     * Includes the must-use plugins hosts install for themselves (WP Engine,
     * Kinsta, GoDaddy, Bluehost), which fail anywhere else.
     */
    const EXCLUDED_PATTERNS = [
        'wp-content/mu-plugins/managed-wp-*',
        'wp-content/mu-plugins/wpengine-*',
        'wp-content/mu-plugins/mu-plugin.php',
        'wp-content/mu-plugins/kinsta-mu-plugins*',
        'wp-content/mu-plugins/gd-system-plugin*',
        'wp-content/mu-plugins/endurance-*',
        'wp-content/uploads/backwpup-*',
        'wp-content/uploads/wp-migrate-db',
    ];

    const EXCLUDED_NAMES = ['error_log', '.DS_Store', 'Thumbs.db'];

    public static function boot()
    {
        add_action('admin_menu', [__CLASS__, 'add_page']);
        add_action('admin_post_managed_wp_migrator_connect', [__CLASS__, 'connect']);
        add_action('admin_post_managed_wp_migrator_disconnect', [__CLASS__, 'disconnect']);
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
        // Fallback for websites whose security plugin blocks the REST API.
        add_action('wp_ajax_nopriv_managed_wp_migrator', [__CLASS__, 'dispatch_ajax']);
        add_action('wp_ajax_managed_wp_migrator', [__CLASS__, 'dispatch_ajax']);
    }

    public static function forget()
    {
        delete_option(self::OPTION);
    }

    // ------------------------------------------------------------------
    // Admin page
    // ------------------------------------------------------------------

    public static function add_page()
    {
        add_management_page(
            __('Migrate to Managed WP', 'managed-wp-migrator'),
            __('Migrate to Managed WP', 'managed-wp-migrator'),
            'manage_options',
            'managed-wp-migrator',
            [__CLASS__, 'render_page']
        );
    }

    public static function render_page()
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        $config = self::config();
        $notice = get_transient('managed_wp_migrator_notice_'.get_current_user_id());
        delete_transient('managed_wp_migrator_notice_'.get_current_user_id());
        ?>
		<div class="wrap">
			<h1><?php esc_html_e('Migrate to Managed WP', 'managed-wp-migrator'); ?></h1>

			<?php if (is_array($notice)) { ?>
				<div class="notice notice-<?php echo $notice['type'] === 'error' ? 'error' : 'success'; ?>">
					<p><?php echo esc_html($notice['message']); ?></p>
				</div>
			<?php } ?>

			<?php if (is_multisite()) { ?>
				<div class="notice notice-error"><p><?php esc_html_e('WordPress multisite networks cannot be migrated yet.', 'managed-wp-migrator'); ?></p></div>
			<?php } elseif (! empty($config['connected_at'])) { ?>
				<p><?php esc_html_e('This website is connected to Managed WP. The copy runs from Managed WP: you can close this page, and this website keeps working normally while it is copied.', 'managed-wp-migrator'); ?></p>
				<?php if (! empty($config['migration_url'])) { ?>
					<p><a class="button button-primary" href="<?php echo esc_url($config['migration_url']); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Follow the progress in Managed WP', 'managed-wp-migrator'); ?></a></p>
				<?php } ?>
				<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
					<input type="hidden" name="action" value="managed_wp_migrator_disconnect" />
					<?php wp_nonce_field('managed_wp_migrator_disconnect'); ?>
					<p><?php esc_html_e('Disconnect to stop Managed WP from reading this website. A running copy then fails.', 'managed-wp-migrator'); ?></p>
					<?php submit_button(__('Disconnect', 'managed-wp-migrator'), 'secondary', 'submit', false); ?>
				</form>
			<?php } else { ?>
				<p><?php esc_html_e('Paste the migration key shown on the migration page in Managed WP. Managed WP then copies the database, themes, plugins and uploads of this website into your new website. Nothing on this website is changed.', 'managed-wp-migrator'); ?></p>
				<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
					<input type="hidden" name="action" value="managed_wp_migrator_connect" />
					<?php wp_nonce_field('managed_wp_migrator_connect'); ?>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="managed-wp-migration-key"><?php esc_html_e('Migration key', 'managed-wp-migrator'); ?></label></th>
							<td><textarea id="managed-wp-migration-key" name="migration_key" rows="4" class="large-text code" required autocomplete="off" spellcheck="false"></textarea></td>
						</tr>
					</table>
					<?php submit_button(__('Connect', 'managed-wp-migrator')); ?>
				</form>
			<?php } ?>
		</div>
		<?php
    }

    /**
     * Store the key, then report this website to Managed WP.
     */
    public static function connect()
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to do this.', 'managed-wp-migrator'), 403);
        }

        check_admin_referer('managed_wp_migrator_connect');

        $key = isset($_POST['migration_key']) ? trim(sanitize_textarea_field(wp_unslash($_POST['migration_key']))) : '';
        $parsed = self::parse_key($key);

        if ($parsed === null) {
            self::redirect_with('error', __('This is not a Managed WP migration key. Copy it again from the migration page.', 'managed-wp-migrator'));
        }

        if (is_multisite()) {
            self::redirect_with('error', __('WordPress multisite networks cannot be migrated yet.', 'managed-wp-migrator'));
        }

        update_option(self::OPTION, $parsed, false);

        $body = wp_json_encode(self::site_info(true));
        $url = $parsed['panel'].'/api/migrations/source/connect';
        $path = (string) wp_parse_url($url, PHP_URL_PATH);
        $response = wp_remote_post(
            $url,
            [
                'timeout' => 30,
                'headers' => array_merge(
                    self::signature_headers('POST', $path, $body, $parsed['secret']),
                    [
                        'X-MWP-Key' => $parsed['token'],
                        'Content-Type' => 'application/json',
                        'Accept' => 'application/json',
                    ]
                ),
                'body' => $body,
            ]
        );

        if (is_wp_error($response)) {
            self::forget();
            /* translators: %s: the connection error. */
            self::redirect_with('error', sprintf(__('Could not reach Managed WP: %s', 'managed-wp-migrator'), $response->get_error_message()));
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $reply = json_decode((string) wp_remote_retrieve_body($response), true);

        if ($status !== 200 || ! is_array($reply)) {
            self::forget();
            $message = is_array($reply) && ! empty($reply['message']) && $status !== 403
                ? (string) $reply['message']
                : __('Managed WP did not accept the key. Copy it again from the migration page.', 'managed-wp-migrator');
            self::redirect_with('error', $message);
        }

        $parsed['connected_at'] = time();
        $parsed['migration_url'] = isset($reply['migration_url']) ? esc_url_raw((string) $reply['migration_url']) : '';
        update_option(self::OPTION, $parsed, false);

        self::redirect_with('success', __('Connected. Managed WP starts copying this website as soon as the new website is ready.', 'managed-wp-migrator'));
    }

    public static function disconnect()
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to do this.', 'managed-wp-migrator'), 403);
        }

        check_admin_referer('managed_wp_migrator_disconnect');
        self::forget();

        self::redirect_with('success', __('Disconnected. Managed WP can no longer read this website.', 'managed-wp-migrator'));
    }

    private static function redirect_with($type, $message)
    {
        set_transient('managed_wp_migrator_notice_'.get_current_user_id(), ['type' => $type, 'message' => $message], 60);
        wp_safe_redirect(admin_url('tools.php?page=managed-wp-migrator'));
        exit;
    }

    /**
     * mwp1_ + base64url(JSON {u: panel URL, k: source token, s: secret}).
     *
     * @return array{panel: string, token: string, secret: string}|null
     */
    private static function parse_key($key)
    {
        if (strpos($key, self::KEY_PREFIX) !== 0) {
            return null;
        }

        $json = base64_decode(strtr(substr($key, strlen(self::KEY_PREFIX)), '-_', '+/'), true);
        $data = is_string($json) ? json_decode($json, true) : null;

        if (! is_array($data) || ! isset($data['u'], $data['k'], $data['s'])) {
            return null;
        }

        $panel = untrailingslashit((string) $data['u']);
        $token = (string) $data['k'];
        $secret = (string) $data['s'];

        if (! preg_match('#^https?://[^\s/?\#]+(/[^\s?\#]*)?$#', $panel)
            || ! preg_match('/^[a-f0-9]{64}$/D', $token)
            || ! preg_match('/^[a-f0-9]{64}$/D', $secret)) {
            return null;
        }

        return [
            'panel' => $panel,
            'token' => $token,
            'secret' => $secret,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function config()
    {
        $config = get_option(self::OPTION);

        return is_array($config) ? $config : [];
    }

    // ------------------------------------------------------------------
    // Signed export endpoints
    // ------------------------------------------------------------------

    public static function register_routes()
    {
        register_rest_route(
            self::REST_NAMESPACE,
            '/(?P<endpoint>[a-z-]+)',
            [
                'methods' => 'POST',
                'callback' => [__CLASS__, 'dispatch_rest'],
                // Every request is authenticated by its HMAC signature in dispatch().
                'permission_callback' => '__return_true',
            ]
        );
    }

    public static function dispatch_rest($request)
    {
        self::dispatch((string) $request['endpoint']);
    }

    public static function dispatch_ajax()
    {
        self::dispatch(isset($_GET['endpoint']) ? sanitize_key(wp_unslash($_GET['endpoint'])) : ''); // phpcs:ignore WordPress.Security.NonceVerification -- signed request.
    }

    /**
     * Verify the signature, then answer one endpoint. Always exits.
     */
    private static function dispatch($endpoint)
    {
        if (! in_array($endpoint, self::ENDPOINTS, true)) {
            self::fail(404, 'unknown_endpoint', 'Unknown endpoint.');
        }

        $config = self::config();

        if (empty($config['secret']) || empty($config['connected_at'])) {
            self::fail(403, 'not_connected', 'This website is not connected to Managed WP.');
        }

        $body = (string) file_get_contents('php://input');

        if (! self::verify('/'.self::REST_NAMESPACE.'/'.$endpoint, $body, (string) $config['secret'])) {
            self::fail(401, 'bad_signature', 'The request signature is invalid or expired.');
        }

        $input = json_decode($body, true);
        $input = is_array($input) ? $input : [];

        if (function_exists('set_time_limit')) {
            @set_time_limit(300); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- disabled on some hosts.
        }

        switch ($endpoint) {
            case 'info':
                self::json(self::site_info(false));
                break;
            case 'tables':
                self::json(['tables' => self::tables()]);
                break;
            case 'table-chunk':
                self::json(self::table_chunk($input));
                break;
            case 'files':
                self::json(self::file_page($input));
                break;
            case 'file-chunk':
                self::file_chunk($input);
                break;
            case 'finish':
                self::forget();
                self::json(['status' => 'finished']);
                break;
        }
    }

    /**
     * @return array<string, string>
     */
    private static function signature_headers($method, $path, $body, $secret)
    {
        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(16));

        return [
            'X-MWP-Timestamp' => $timestamp,
            'X-MWP-Nonce' => $nonce,
            'X-MWP-Signature' => self::sign($secret, $method, $path, $timestamp, $nonce, $body),
        ];
    }

    private static function sign($secret, $method, $path, $timestamp, $nonce, $body)
    {
        return hash_hmac('sha256', implode("\n", [strtoupper($method), $path, $timestamp, $nonce, hash('sha256', $body)]), $secret);
    }

    private static function verify($path, $body, $secret)
    {
        $timestamp = isset($_SERVER['HTTP_X_MWP_TIMESTAMP']) ? (string) $_SERVER['HTTP_X_MWP_TIMESTAMP'] : '';
        $nonce = isset($_SERVER['HTTP_X_MWP_NONCE']) ? (string) $_SERVER['HTTP_X_MWP_NONCE'] : '';
        $signature = isset($_SERVER['HTTP_X_MWP_SIGNATURE']) ? (string) $_SERVER['HTTP_X_MWP_SIGNATURE'] : '';

        if (! ctype_digit($timestamp) || abs(time() - (int) $timestamp) > self::SIGNATURE_TOLERANCE) {
            return false;
        }

        if (! preg_match('/^[a-f0-9]{16,64}$/D', $nonce)) {
            return false;
        }

        $expected = self::sign($secret, 'POST', $path, $timestamp, $nonce, $body);

        if (! hash_equals($expected, $signature) || get_transient('managed_wp_migrator_nonce_'.$nonce) !== false) {
            return false;
        }

        set_transient('managed_wp_migrator_nonce_'.$nonce, 1, self::SIGNATURE_TOLERANCE * 2);

        return true;
    }

    private static function json($data, $status = 200)
    {
        self::clean_output();
        status_header($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo wp_json_encode($data); // phpcs:ignore WordPress.Security.EscapeOutput -- JSON.
        exit;
    }

    private static function fail($status, $code, $message)
    {
        self::json(
            [
                'code' => 'managed_wp_migrator_'.$code,
                'message' => $message,
            ],
            $status
        );
    }

    private static function clean_output()
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
    }

    // ------------------------------------------------------------------
    // Website facts
    // ------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private static function site_info($with_estimates)
    {
        global $wpdb;

        $info = [
            'home' => home_url(),
            'siteurl' => site_url(),
            'blogname' => get_bloginfo('name'),
            'wp_version' => get_bloginfo('version'),
            'php_version' => PHP_VERSION,
            'plugin_version' => self::VERSION,
            'table_prefix' => $wpdb->prefix,
            'multisite' => is_multisite(),
        ];

        if (! $with_estimates) {
            return array_merge(
                $info,
                [
                    'charset' => $wpdb->charset,
                    'active_plugins' => array_values((array) get_option('active_plugins', [])),
                ]
            );
        }

        $info['db_bytes'] = (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT SUM(DATA_LENGTH + INDEX_LENGTH) FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME LIKE %s',
                DB_NAME,
                $wpdb->esc_like($wpdb->prefix).'%'
            )
        );

        $count = 0;
        $bytes = 0;
        $deadline = microtime(true) + self::ESTIMATE_SECONDS;
        $complete = true;

        foreach (self::walk() as $absolute) {
            $count++;
            $bytes += (int) @filesize($absolute); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- a file may vanish mid-walk.

            if ($count % 200 === 0 && microtime(true) > $deadline) {
                $complete = false;
                break;
            }
        }

        $info['file_count'] = $complete ? $count : null;
        $info['file_bytes'] = $complete ? $bytes : null;

        return $info;
    }

    // ------------------------------------------------------------------
    // Database
    // ------------------------------------------------------------------

    /**
     * This website's tables (those with its prefix), with the statement that
     * recreates each under the wp_ prefix Managed WP uses.
     *
     * @return list<array<string, mixed>>
     */
    private static function tables()
    {
        global $wpdb;

        $tables = [];

        foreach (self::table_names() as $name) {
            $create = $wpdb->get_row('SHOW CREATE TABLE `'.$name.'`', ARRAY_N); // phpcs:ignore WordPress.DB -- name from SHOW TABLES.
            $status = $wpdb->get_row($wpdb->prepare('SHOW TABLE STATUS LIKE %s', $name), ARRAY_A);
            $target = self::target_table($name);
            $sql = is_array($create) ? (string) $create[1] : '';
            $sql = preg_replace('/^CREATE TABLE `'.preg_quote($name, '/').'`/', 'CREATE TABLE `'.$target.'`', $sql);
            $tables[] = [
                'name' => $name,
                'target' => $target,
                'rows' => is_array($status) ? (int) $status['Rows'] : 0,
                'bytes' => is_array($status) ? (int) $status['Data_length'] : 0,
                'create' => base64_encode((string) $sql),
            ];
        }

        return $tables;
    }

    /**
     * @return list<string>
     */
    private static function table_names()
    {
        global $wpdb;

        $names = [];
        $rows = $wpdb->get_results($wpdb->prepare('SHOW FULL TABLES LIKE %s', $wpdb->esc_like($wpdb->prefix).'%'), ARRAY_N);

        foreach ((array) $rows as $row) {
            if (isset($row[1]) && $row[1] === 'BASE TABLE' && strpos($row[0], '`') === false) {
                $names[] = (string) $row[0];
            }
        }

        sort($names, SORT_STRING);

        return $names;
    }

    private static function target_table($name)
    {
        global $wpdb;

        return 'wp_'.substr($name, strlen($wpdb->prefix));
    }

    /**
     * Up to max_bytes of INSERT statements for one table, starting after the
     * cursor. Tables with a single integer primary key page by key; others by
     * offset. Binary columns are sent as hex so no charset conversion touches
     * them.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private static function table_chunk($input)
    {
        global $wpdb;

        $table = isset($input['table']) ? (string) $input['table'] : '';

        if (! in_array($table, self::table_names(), true)) {
            self::fail(422, 'unknown_table', 'Unknown table.');
        }

        $max_bytes = isset($input['max_bytes']) ? (int) $input['max_bytes'] : self::DEFAULT_CHUNK_BYTES;
        $max_bytes = max(65536, min(self::MAX_CHUNK_BYTES, $max_bytes));
        $columns = $wpdb->get_results('SHOW COLUMNS FROM `'.$table.'`', ARRAY_A); // phpcs:ignore WordPress.DB -- validated name.
        $names = [];
        $binary = [];
        $primary = [];

        foreach ((array) $columns as $index => $column) {
            $names[] = '`'.str_replace('`', '``', (string) $column['Field']).'`';

            if (preg_match('/blob|binary|bit\(/i', (string) $column['Type'])) {
                $binary[$index] = true;
            }

            if ($column['Key'] === 'PRI') {
                $primary[] = [
                    'name' => (string) $column['Field'],
                    'integer' => (bool) preg_match('/^(tiny|small|medium|big)?int\b/i', (string) $column['Type']),
                ];
            }
        }

        $key = count($primary) === 1 && $primary[0]['integer'] ? '`'.$primary[0]['name'].'`' : null;
        $after = isset($input['after']) && $input['after'] !== null ? (string) $input['after'] : null;
        $offset = isset($input['offset']) ? max(0, (int) $input['offset']) : 0;
        $order = $primary ? ' ORDER BY '.implode(', ', array_map(function ($column) {
            return '`'.str_replace('`', '``', $column['name']).'`';
        }, $primary)) : '';

        if ($after !== null && ! preg_match('/^-?\d{1,20}$/D', $after)) {
            self::fail(422, 'bad_cursor', 'Invalid cursor.');
        }

        $target = self::target_table($table);
        $prefix = 'INSERT INTO `'.$target.'` ('.implode(',', $names).') VALUES ';
        $sql = '';
        $statement = '';
        $rows = 0;
        $last_key = $after;
        $key_index = null;
        $done = false;

        if ($key !== null) {
            foreach ((array) $columns as $index => $column) {
                if ('`'.$column['Field'].'`' === $key) {
                    $key_index = $index;
                }
            }
        }

        $full = false;

        while (! $full) {
            if ($key !== null) {
                $query = $last_key === null
                    ? "SELECT * FROM `{$table}` ORDER BY {$key} LIMIT ".self::ROW_BATCH
                    : $wpdb->prepare("SELECT * FROM `{$table}` WHERE {$key} > %s ORDER BY {$key} LIMIT ".self::ROW_BATCH, $last_key); // phpcs:ignore WordPress.DB
            } else {
                $query = "SELECT * FROM `{$table}`{$order} LIMIT ".($offset + $rows).', '.self::ROW_BATCH;
            }

            $batch = $wpdb->get_results($query, ARRAY_N); // phpcs:ignore WordPress.DB -- built from validated names.

            if ($wpdb->last_error !== '') {
                self::fail(500, 'query_failed', 'Reading '.$table.' failed: '.$wpdb->last_error);
            }

            foreach ((array) $batch as $row) {
                $values = [];

                foreach ($row as $index => $value) {
                    if ($value === null) {
                        $values[] = 'NULL';
                    } elseif (isset($binary[$index])) {
                        $values[] = $value === '' ? "''" : '0x'.bin2hex($value);
                    } else {
                        $values[] = "'".$wpdb->remove_placeholder_escape($wpdb->_real_escape($value))."'";
                    }
                }

                $tuple = '('.implode(',', $values).')';

                if ($statement !== '' && strlen($statement) + strlen($tuple) > self::MAX_STATEMENT_BYTES) {
                    $sql .= $statement.";\n";
                    $statement = '';
                }

                $statement .= ($statement === '' ? $prefix : ',').$tuple;
                $rows++;

                if ($key_index !== null) {
                    $last_key = (string) $row[$key_index];
                }

                // A chunk always holds at least one row, however large.
                if (strlen($sql) + strlen($statement) >= $max_bytes) {
                    $full = true;
                    break;
                }
            }

            if (! $full && count((array) $batch) < self::ROW_BATCH) {
                $done = true;
                break;
            }
        }

        if ($statement !== '') {
            $sql .= $statement.";\n";
        }

        $next = null;

        if (! $done) {
            $next = $key !== null ? ['after' => $last_key] : ['offset' => $offset + $rows];
        }

        return [
            'sql' => base64_encode($sql),
            'rows' => $rows,
            'next' => $next,
        ];
    }

    // ------------------------------------------------------------------
    // Files
    // ------------------------------------------------------------------

    /**
     * The next page of files after the cursor, in a stable order, with sizes.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private static function file_page($input)
    {
        $after = isset($input['after']) ? (string) $input['after'] : '';
        $files = [];
        $last = null;
        $deadline = microtime(true) + self::LISTING_SECONDS;
        $done = true;

        foreach (self::walk($after) as $logical => $absolute) {
            $files[] = [
                'p' => $logical,
                's' => (int) @filesize($absolute), // phpcs:ignore WordPress.PHP.NoSilencedErrors
            ];
            $last = $logical;

            if (count($files) >= self::MANIFEST_PAGE || microtime(true) > $deadline) {
                $done = false;
                break;
            }
        }

        return [
            'files' => $files,
            'next' => $done ? null : $last,
        ];
    }

    /**
     * Stream files: a JSON header line per file ({p, s, h} with the MD5, or
     * {p, e} when it cannot be read) followed by exactly s bytes. With
     * offset/length, only that range of one file, as raw bytes.
     *
     * @param  array<string, mixed>  $input
     */
    private static function file_chunk($input)
    {
        $paths = isset($input['files']) && is_array($input['files']) ? array_slice($input['files'], 0, self::MAX_BATCH_FILES) : [];

        self::clean_output();
        status_header(200);
        header('Content-Type: application/octet-stream');
        header('Cache-Control: no-store');

        if (isset($input['length']) && count($paths) === 1) {
            $absolute = self::resolve((string) $paths[0]);
            $handle = $absolute !== null ? @fopen($absolute, 'rb') : false; // phpcs:ignore WordPress

            if ($handle === false) {
                self::fail(404, 'missing_file', 'The file is gone.');
            }

            $length = max(0, min(self::MAX_CHUNK_BYTES, (int) $input['length']));
            fseek($handle, max(0, (int) (isset($input['offset']) ? $input['offset'] : 0)));

            while ($length > 0 && ! feof($handle)) {
                $data = fread($handle, min(65536, $length)); // phpcs:ignore WordPress

                if ($data === false || $data === '') {
                    break;
                }

                echo $data; // phpcs:ignore WordPress.Security.EscapeOutput -- file bytes.
                $length -= strlen($data);
            }

            fclose($handle); // phpcs:ignore WordPress
            exit;
        }

        foreach ($paths as $logical) {
            $logical = (string) $logical;
            $absolute = self::resolve($logical);
            $size = $absolute !== null ? @filesize($absolute) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors
            $hash = $absolute !== null ? @md5_file($absolute) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors

            if ($size === false || $hash === false) {
                echo wp_json_encode(['p' => $logical, 'e' => 'missing'])."\n"; // phpcs:ignore WordPress.Security.EscapeOutput

                continue;
            }

            echo wp_json_encode(['p' => $logical, 's' => $size, 'h' => $hash])."\n"; // phpcs:ignore WordPress.Security.EscapeOutput
            readfile($absolute); // phpcs:ignore WordPress
        }

        exit;
    }

    /**
     * The file behind a logical path, or null when the path is not one this
     * plugin would list (outside the website, excluded, or a symlink).
     */
    private static function resolve($logical)
    {
        if ($logical === '' || strpos($logical, "\0") !== false || strpos($logical, '\\') !== false) {
            return null;
        }

        $segments = explode('/', $logical);

        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return null;
            }
        }

        for ($depth = 1; $depth <= count($segments); $depth++) {
            if (self::is_excluded(implode('/', array_slice($segments, 0, $depth)))) {
                return null;
            }
        }

        $roots = self::root_entries();

        if (! isset($roots[$segments[0]])) {
            return null;
        }

        $absolute = $roots[$segments[0]].(count($segments) > 1 ? '/'.implode('/', array_slice($segments, 1)) : '');
        $real = realpath($absolute);
        $base = realpath($roots[$segments[0]]);

        if ($real === false || $base === false || is_link($absolute) || ! is_file($real)) {
            return null;
        }

        if ($real !== $base && strpos($real, rtrim($base, '/').'/') !== 0) {
            return null;
        }

        return $real;
    }

    /**
     * Every file to copy after $after, as logical path => absolute path, in
     * depth-first order with each directory sorted byte-wise, which is the
     * segment-wise order the cursor is compared in. The content directory is
     * always listed as wp-content, wherever WP_CONTENT_DIR points.
     *
     * @return Generator<string, string>
     */
    private static function walk($after = '')
    {
        return self::walk_entries('', self::root_entries(), $after === '' ? [] : explode('/', $after));
    }

    /**
     * @return array<string, string>
     */
    private static function root_entries()
    {
        $root = untrailingslashit(ABSPATH);
        $content = untrailingslashit(WP_CONTENT_DIR);
        $entries = self::list_directory($root);

        unset($entries['wp-content']);
        $entries['wp-content'] = $content;
        ksort($entries, SORT_STRING);

        return $entries;
    }

    /**
     * @return array<string, string>
     */
    private static function list_directory($directory)
    {
        $names = @scandir($directory); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- unreadable directories are skipped.
        $entries = [];

        foreach (is_array($names) ? $names : [] as $name) {
            if ($name !== '.' && $name !== '..') {
                $entries[(string) $name] = $directory.'/'.$name;
            }
        }

        ksort($entries, SORT_STRING);

        return $entries;
    }

    /**
     * @param  array<string, string>  $entries
     * @param  list<string>  $after
     * @return Generator<string, string>
     */
    private static function walk_entries($prefix, $entries, $after)
    {
        $content = realpath(WP_CONTENT_DIR);

        foreach ($entries as $name => $absolute) {
            $logical = $prefix === '' ? (string) $name : $prefix.'/'.$name;

            if (self::is_excluded($logical) || is_link($absolute)) {
                continue;
            }

            $segments = explode('/', $logical);

            if (is_dir($absolute)) {
                // The content directory is listed once, as wp-content.
                if ($logical !== 'wp-content' && $content !== false && realpath($absolute) === $content) {
                    continue;
                }

                if ($after && self::compare($segments, $after) < 0 && ! self::is_prefix($segments, $after)) {
                    continue;
                }

                foreach (self::walk_entries($logical, self::list_directory($absolute), $after) as $path => $file) {
                    yield $path => $file;
                }
            } elseif (is_file($absolute)) {
                if ($after && self::compare($segments, $after) <= 0) {
                    continue;
                }

                yield $logical => $absolute;
            }
        }
    }

    /**
     * @param  list<string>  $a
     * @param  list<string>  $b
     */
    private static function compare($a, $b)
    {
        $length = min(count($a), count($b));

        for ($i = 0; $i < $length; $i++) {
            $result = strcmp($a[$i], $b[$i]);

            if ($result !== 0) {
                return $result;
            }
        }

        return count($a) - count($b);
    }

    /**
     * @param  list<string>  $prefix
     * @param  list<string>  $path
     */
    private static function is_prefix($prefix, $path)
    {
        return count($prefix) < count($path) && array_slice($path, 0, count($prefix)) === $prefix;
    }

    private static function is_excluded($logical)
    {
        if (in_array($logical, self::EXCLUDED, true) || in_array(basename($logical), self::EXCLUDED_NAMES, true)) {
            return true;
        }

        foreach (self::EXCLUDED_PATTERNS as $pattern) {
            if (fnmatch($pattern, $logical, FNM_PATHNAME)) {
                return true;
            }
        }

        return false;
    }
}

Managed_WP_Migrator::boot();

// Deactivating the plugin disconnects the website.
register_deactivation_hook(__FILE__, ['Managed_WP_Migrator', 'forget']);
