<?php
/**
 * Strava Stats - web installer.
 *
 * Upload the project, then open this file in your browser:
 *
 *     https://example.com/strava-stats/setup.php
 *
 * It checks your server, writes config.php, connects your Strava account
 * (no terminal needed) and tests the feed. You can come back to change
 * settings at any time.
 *
 * Security:
 *  - On the first visit you choose an installer password. Until you do,
 *    anyone who finds this page could claim it, so run it straight after
 *    uploading.
 *  - If config.php already holds a Client Secret (an existing install), the
 *    first visit asks for that secret to prove the install is yours.
 *  - When you're done, click "Lock installer" (or delete this file).
 */

require_once __DIR__ . '/lib.php';

if (PHP_SAPI === 'cli') {
    fwrite(STDOUT, "setup.php is a web installer: open it in your browser.\n"
        . "To set up from a terminal instead, edit config.php and run: php connect.php\n");
    exit(0);
}

// ==================================================================== catalog

const SETUP_SPORT_GROUPS = [
    'Cycling'          => ['Ride', 'GravelRide', 'MountainBikeRide', 'VirtualRide', 'EBikeRide', 'EMountainBikeRide', 'Velomobile', 'Handcycle'],
    'Running'          => ['Run', 'TrailRun', 'VirtualRun'],
    'Walking & hiking' => ['Walk', 'Hike', 'Snowshoe', 'Wheelchair'],
    'Water'            => ['Swim', 'Rowing', 'VirtualRow', 'Kayaking', 'Canoeing', 'StandUpPaddling', 'Surfing', 'Kitesurf', 'Windsurf', 'Sail'],
    'Winter'           => ['AlpineSki', 'BackcountrySki', 'NordicSki', 'Snowboard', 'IceSkate', 'RollerSki'],
    'Fitness'          => ['Workout', 'WeightTraining', 'Crossfit', 'HighIntensityIntervalTraining', 'Elliptical', 'StairStepper', 'Yoga', 'Pilates', 'RockClimbing'],
    'Other'            => ['Golf', 'Soccer', 'Tennis', 'Pickleball', 'Badminton', 'Squash', 'Racquetball', 'TableTennis', 'InlineSkate', 'Skateboard'],
];

const SETUP_STATS_FIELDS = [
    'recent_ride_totals'           => 'Rides, last 4 weeks',
    'ytd_ride_totals'              => 'Rides, this year',
    'all_ride_totals'              => 'Rides, all time',
    'recent_run_totals'            => 'Runs, last 4 weeks',
    'ytd_run_totals'               => 'Runs, this year',
    'all_run_totals'               => 'Runs, all time',
    'recent_swim_totals'           => 'Swims, last 4 weeks',
    'ytd_swim_totals'              => 'Swims, this year',
    'all_swim_totals'              => 'Swims, all time',
    'biggest_ride_distance'        => 'Longest ride ever',
    'biggest_climb_elevation_gain' => 'Biggest climb ever',
];

const SETUP_ATHLETE_FIELDS = [
    'firstname'     => 'First name',
    'lastname'      => 'Last name',
    'profile'       => 'Profile photo',
    'profile_large' => 'Large profile photo',
    'city'          => 'City',
    'state'         => 'State / region',
    'country'       => 'Country',
    'url'           => 'Link to Strava profile',
    'id'            => 'Athlete ID',
    'username'      => 'Username',
    'sex'           => 'Sex',
    'premium'       => 'Subscriber status',
    'created_at'    => 'Member since',
    'ftp'           => 'FTP',
    'weight'        => 'Weight',
];

const SETUP_PRESETS = [
    'cycling' => [
        'label' => 'Cycling',
        'types' => ['Ride', 'VirtualRide', 'MountainBikeRide', 'GravelRide', 'EBikeRide', 'EMountainBikeRide'],
        'stats' => ['recent_ride_totals', 'ytd_ride_totals', 'all_ride_totals', 'biggest_ride_distance', 'biggest_climb_elevation_gain'],
    ],
    'running' => [
        'label' => 'Running',
        'types' => ['Run', 'TrailRun', 'VirtualRun'],
        'stats' => ['recent_run_totals', 'ytd_run_totals', 'all_run_totals'],
    ],
    'everything' => [
        'label' => 'Everything',
        'types' => ['*'],
        'stats' => ['recent_ride_totals', 'ytd_ride_totals', 'all_ride_totals', 'recent_run_totals', 'ytd_run_totals',
                    'all_run_totals', 'recent_swim_totals', 'ytd_swim_totals', 'all_swim_totals',
                    'biggest_ride_distance', 'biggest_climb_elevation_gain'],
    ],
];

const SETUP_STEPS = [
    'requirements' => 'Server check',
    'password'     => 'Password',
    'app'          => 'Strava app',
    'options'      => 'Options',
    'connect'      => 'Connect',
    'finish'       => 'Test & finish',
];

// ==================================================================== helpers

function h($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function setup_type_label(string $t): string
{
    $special = [
        'EBikeRide' => 'E-Bike Ride', 'EMountainBikeRide' => 'E-Mountain Bike Ride',
        'HighIntensityIntervalTraining' => 'HIIT', 'Crossfit' => 'CrossFit', 'Sail' => 'Sailing',
        'StandUpPaddling' => 'Stand Up Paddling', 'Ride' => 'Road Ride',
    ];
    return $special[$t] ?? trim((string)preg_replace('/(?<!^)([A-Z])/', ' $1', $t));
}

function setup_all_types(): array
{
    return array_merge(...array_values(SETUP_SPORT_GROUPS));
}

function setup_is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') return true;
    if ((string)($_SERVER['SERVER_PORT'] ?? '') === '443') return true;
    if (strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') return true;
    return false;
}

function setup_host(): string
{
    $host = (string)($_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost'));
    return preg_match('/^([A-Za-z0-9.\-]+|\[[0-9A-Fa-f:.]+\])(:\d+)?$/', $host) ? $host : 'localhost';
}

function setup_origin(): string
{
    return (setup_is_https() ? 'https' : 'http') . '://' . setup_host();
}

function setup_self_url(): string
{
    return setup_origin() . (string)($_SERVER['SCRIPT_NAME'] ?? '/setup.php');
}

/** URL of the folder setup.php lives in, with a trailing slash. */
function setup_dir_url(): string
{
    $dir = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/setup.php'))), '/');
    return setup_origin() . $dir . '/';
}

/** What to type into Strava's "Authorization Callback Domain" box. */
function setup_callback_domain(): string
{
    return (string)preg_replace('/:\d+$/', '', trim(setup_host(), '[]'));
}

function setup_redirect(string $query = ''): void
{
    header('Location: ' . setup_self_url() . ($query !== '' ? '?' . $query : ''), true, 303);
    exit;
}

function setup_flash(string $type, string $message): void
{
    $_SESSION['flash'][] = [$type, $message];
}

function setup_is_absolute(string $p): bool
{
    return $p !== '' && ($p[0] === '/' || $p[0] === '\\' || preg_match('/^[A-Za-z]:[\\\\\/]/', $p) === 1);
}

/** Is $dir writable, or could it be created (its nearest existing parent is writable)? */
function setup_dir_writable(string $dir): bool
{
    while (!is_dir($dir)) {
        $parent = dirname($dir);
        if ($parent === $dir) return false;
        $dir = $parent;
    }
    return is_writable($dir);
}

function setup_auth_tag(string $hash): string
{
    return hash('sha256', 'strava-setup|' . $hash);
}

function setup_human_duration(int $s): string
{
    if ($s <= 0) return 'Off';
    if ($s % 3600 === 0) return ($s / 3600) . ' hour' . ($s === 3600 ? '' : 's');
    if ($s % 60 === 0) return ($s / 60) . ' minute' . ($s === 60 ? '' : 's');
    return $s . ' seconds';
}

/** Activity scopes that were asked for but not granted (empty if unknown). */
function setup_missing_scopes(string $wanted, array $tokens): array
{
    if (empty($tokens['scope'])) return [];
    $have = array_map('trim', explode(',', (string)$tokens['scope']));
    if (in_array('activity:read_all', $have, true)) $have[] = 'activity:read';
    return array_values(array_diff(array_map('trim', explode(',', $wanted)), $have));
}

function setup_scope_text(string $scope): array
{
    $map = [
        'read'              => 'Your public profile',
        'activity:read'     => 'Activities visible to Everyone or Followers',
        'activity:read_all' => 'All your activities, including "Only You" ones',
        'profile:read_all'  => 'Your full profile, including FTP and weight',
    ];
    $out = [];
    foreach (array_map('trim', explode(',', $scope)) as $s) {
        if ($s !== '') $out[] = $map[$s] ?? $s;
    }
    return $out;
}

function setup_env_note(string $key): string
{
    $name = 'STRAVA_' . strtoupper($key);
    $v = getenv($name);
    if ($v === false || $v === '') return '';
    return '<p class="envnote">Set by the environment variable <code>' . h($name)
         . '</code> on this server, which wins over whatever you save here.</p>';
}

// ============================================================== config files

function setup_read_raw(string $file): array
{
    if (!is_file($file)) return [];
    if (function_exists('opcache_invalidate')) @opcache_invalidate($file, true);
    $loaded = (static function (string $f) { return require $f; })($file);
    if (!is_array($loaded)) {
        throw new RuntimeException('config.php must return an array (it currently returns ' . gettype($loaded) . ').');
    }
    return $loaded;
}

/** PHP source for a config value; paths inside the config folder stay relative. */
function setup_export($v, string $baseDir): string
{
    if (is_string($v)) {
        $prefix = rtrim(str_replace('\\', '/', $baseDir), '/') . '/';
        $norm = str_replace('\\', '/', $v);
        if (strpos($norm, $prefix) === 0) {
            return "__DIR__ . " . var_export('/' . substr($norm, strlen($prefix)), true);
        }
        return var_export($v, true);
    }
    if (is_bool($v)) return $v ? 'true' : 'false';
    if ($v === null) return 'null';
    if (is_array($v)) {
        $isList = array_keys($v) === range(0, count($v) - 1) || $v === [];
        $parts = [];
        foreach ($v as $k => $item) {
            $parts[] = ($isList ? '' : var_export($k, true) . ' => ') . setup_export($item, $baseDir);
        }
        return '[' . implode(', ', $parts) . ']';
    }
    return var_export($v, true);
}

function setup_write_config(string $file, array $raw): void
{
    $defaults = strava_default_config();
    $ordered = [];
    foreach (array_keys($defaults) as $k) {
        // display_units is only written once chosen, so the Options step can
        // tell a first visit (and suggest units from the browser's locale).
        if ($k === 'display_units' && !array_key_exists($k, $raw)) continue;
        $ordered[$k] = array_key_exists($k, $raw) ? $raw[$k] : $defaults[$k];
    }
    foreach ($raw as $k => $v) if (!array_key_exists($k, $ordered) && strpos((string)$k, 'setup_') !== 0) $ordered[$k] = $v;
    foreach (['setup_password_hash', 'setup_locked'] as $k) if (array_key_exists($k, $raw)) $ordered[$k] = $raw[$k];

    $sections = [
        'client_id'           => 'Strava API app (https://www.strava.com/settings/api)',
        'scope'               => 'Permissions requested when connecting your account',
        'token_file'          => 'Where tokens and the cache are stored. Must be writable and private.',
        'cache_ttl'           => 'Seconds to reuse a response before asking Strava again',
        'activity_types'      => "Activity types that count (['*'] means all)",
        'fetch_count'         => 'Activities fetched from Strava / returned in "recent"',
        'display_units'       => "How distances are shown on your site: 'metric' (km) or 'imperial' (miles)",
        'week_starts_on'      => 'Weekly totals',
        'athlete_fields'      => 'What gets published',
        'allowed_origins'     => 'Other sites allowed to call strava.php from a browser (CORS)',
        'browser_cache_ttl'   => 'Browser/CDN caching and error detail',
        'setup_password_hash' => 'Web installer (setup.php). setup_locked = true switches it off.',
    ];

    $dir = dirname($file);
    $php = "<?php\n/**\n * Strava Stats configuration.\n *\n"
         . " * Written by setup.php on " . gmdate('Y-m-d H:i') . " UTC. You can edit it by hand\n"
         . " * or open setup.php again. config.example.php describes every option.\n */\n\nreturn [\n";
    foreach ($ordered as $k => $v) {
        if (isset($sections[$k])) $php .= "\n    // " . $sections[$k] . "\n";
        $php .= '    ' . var_export((string)$k, true) . ' => ' . setup_export($v, $dir) . ",\n";
    }
    $php .= "];\n";

    $ok = false;
    if (is_dir($dir) && is_writable($dir)) {
        $tmp = $dir . '/.config.' . bin2hex(random_bytes(6)) . '.tmp';
        if (@file_put_contents($tmp, $php) !== false) {
            @chmod($tmp, 0640);
            $ok = @rename($tmp, $file);
            if (!$ok) @unlink($tmp);
        }
    }
    if (!$ok && is_file($file) && is_writable($file)) {
        $ok = @file_put_contents($file, $php, LOCK_EX) !== false;
    }
    if (!$ok) {
        throw new RuntimeException('Could not write ' . $file . '. Make its folder writable by the web server and try again.');
    }
    if (function_exists('opcache_invalidate')) @opcache_invalidate($file, true);
}

// =============================================================== diagnostics

/**
 * Server checks. Each: [label, level ok|warn|fail, detail, fix].
 */
function setup_requirements(string $configFile, array $cfg, bool $network): array
{
    $r = [];
    $phpOk = version_compare(PHP_VERSION, '7.4.0', '>=');
    $r[] = ['PHP 7.4 or newer', $phpOk ? 'ok' : 'fail', 'This server runs PHP ' . PHP_VERSION . '.',
            'Switch this site to PHP 8 in your hosting control panel.'];
    $r[] = ['PHP curl extension', function_exists('curl_init') ? 'ok' : 'fail', 'Used to talk to Strava.',
            'Enable "curl" in your hosting control panel, or install it (for example: sudo apt install php-curl).'];
    $r[] = ['PHP json extension', function_exists('json_encode') ? 'ok' : 'fail', 'Used for the feed and the token files.',
            'Enable the "json" extension in your hosting control panel.'];

    $cfgWritable = is_file($configFile) ? (is_writable($configFile) || is_writable(dirname($configFile)))
                                        : setup_dir_writable(dirname($configFile));
    $r[] = ['Can save config.php', $cfgWritable ? 'ok' : 'fail', $configFile,
            'Make the folder writable by the web server, e.g. chmod 775 ' . dirname($configFile)
            . ' (or set it to 775 in your FTP program), then reload this page.'];

    $dirs = array_unique([dirname($cfg['token_file']), dirname($cfg['cache_file'])]);
    foreach ($dirs as $d) {
        $w = setup_dir_writable($d);
        $r[] = ['Can save tokens and cache', $w ? 'ok' : 'fail', $d,
                'Make this folder writable by the web server, e.g. chmod 775 ' . $d
                . '. Strava tokens are refreshed every few hours, so it has to stay writable.'];
    }

    $r[] = ['Secure connection (HTTPS)', setup_is_https() ? 'ok' : 'warn',
            setup_is_https() ? 'This page was loaded over HTTPS.' : 'This page was loaded over plain HTTP.',
            'Your installer password and Strava secret are sent unencrypted. Open this page with https:// if your host supports it.'];

    $soft = strtolower((string)($_SERVER['SERVER_SOFTWARE'] ?? ''));
    if (strpos($soft, 'nginx') !== false || strpos($soft, 'caddy') !== false) {
        $r[] = ['Private files blocked', 'warn', 'This server (' . ($_SERVER['SERVER_SOFTWARE'] ?? '') . ') ignores .htaccess files.',
                'Add the nginx or Caddy rule from the README so nobody can download data/tokens.json. The last step of this installer checks it for you.'];
    }

    if ($network && function_exists('curl_init')) {
        try {
            [$status] = strava_http('GET', STRAVA_API_BASE . '/athlete');
            $reach = $status > 0;
        } catch (Throwable $e) {
            $reach = false;
        }
        $r[] = ['Can reach Strava', $reach ? 'ok' : 'warn',
                $reach ? 'This server can connect to www.strava.com.' : 'This server could not connect to www.strava.com.',
                'Some hosts block outgoing connections. Ask your host to allow HTTPS requests to www.strava.com.'];
    }
    return $r;
}

/**
 * Try to download the token file over HTTP to make sure it's blocked.
 * Returns [status ok|bad|outside|unknown, url, message].
 */
function setup_probe_private(string $file): array
{
    $real = realpath($file);
    if ($real === false) return ['unknown', '', 'The token file does not exist yet.'];
    $real = str_replace('\\', '/', $real);

    $root = null;
    $sf = str_replace('\\', '/', (string)realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')));
    $sn = (string)($_SERVER['SCRIPT_NAME'] ?? '');
    if ($sf !== '' && $sn !== '' && substr($sf, -strlen($sn)) === $sn) {
        $root = substr($sf, 0, -strlen($sn));
    } elseif (!empty($_SERVER['DOCUMENT_ROOT'])) {
        $root = str_replace('\\', '/', (string)realpath((string)$_SERVER['DOCUMENT_ROOT']));
    }
    if ($root === null || $root === '' || strpos($real, rtrim($root, '/') . '/') !== 0) {
        return ['outside', '', 'Your tokens are stored outside the web root, so they can never be downloaded.'];
    }
    $path = substr($real, strlen(rtrim($root, '/')));
    $url = setup_origin() . implode('/', array_map('rawurlencode', explode('/', $path)));

    if (!function_exists('curl_init')) return ['unknown', $url, 'Could not check automatically.'];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_TIMEOUT => 6, CURLOPT_RANGE => '0-4095',
        CURLOPT_USERAGENT => 'strava-stats-setup/' . STRAVA_STATS_VERSION,
    ]);
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false || $status === 0) {
        return ['unknown', $url, 'This server could not load its own address to check.'];
    }
    if ($status >= 200 && $status < 300 && strpos((string)$body, 'refresh_token') !== false) {
        return ['bad', $url, 'Anyone can download your Strava tokens from this address.'];
    }
    return ['ok', $url, 'The token file is blocked (the server answered HTTP ' . $status . ').'];
}

// =============================================================== form parsing

function setup_int(array $p, string $key, int $min, int $max, string $label, array &$errors): int
{
    $raw = trim((string)($p[$key] ?? ''));
    if ($raw === '' || !preg_match('/^\d+$/', $raw)) {
        $errors[] = $label . ' must be a whole number.';
        return $min;
    }
    $n = (int)$raw;
    if ($n < $min || $n > $max) {
        $errors[] = $label . ' must be between ' . $min . ' and ' . $max . '.';
    }
    return max($min, min($max, $n));
}

function setup_parse_options(array $p, string $configDir): array
{
    $e = [];
    $v = [];

    if (!empty($p['all_types'])) {
        $types = ['*'];
    } else {
        $types = array_values(array_intersect(setup_all_types(), (array)($p['types'] ?? [])));
        foreach (preg_split('/[\s,]+/', (string)($p['other_types'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) as $t) {
            if (!preg_match('/^[A-Za-z]+$/', $t)) {
                $e[] = '"' . $t . '" isn\'t a valid activity type. Use Strava\'s names, like GravelRide.';
                continue;
            }
            if (!in_array($t, $types, true)) $types[] = $t;
        }
        if (!$types) $e[] = 'Choose at least one activity type, or tick "All activity types".';
    }
    $v['activity_types'] = $types;
    $v['stats_fields']   = array_values(array_intersect(array_keys(SETUP_STATS_FIELDS), (array)($p['stats'] ?? [])));
    $v['athlete_fields'] = array_values(array_intersect(array_keys(SETUP_ATHLETE_FIELDS), (array)($p['athlete'] ?? [])));

    $private = !empty($p['scope_private']);
    $profile = !empty($p['scope_profile']);
    $v['scope'] = 'read,' . ($private ? 'activity:read_all' : 'activity:read') . ($profile ? ',profile:read_all' : '');
    if (!$profile && array_intersect(['ftp', 'weight'], $v['athlete_fields'])) {
        $e[] = 'Publishing FTP or weight needs "Allow access to FTP and weight" under Privacy.';
    }

    $v['display_units']  = ($p['display_units'] ?? '') === 'imperial' ? 'imperial' : 'metric';
    $v['week_starts_on'] = ($p['week_starts_on'] ?? '') === 'sunday' ? 'sunday' : 'monday';
    $tz = trim((string)($p['timezone'] ?? ''));
    if ($tz !== '' && !in_array($tz, DateTimeZone::listIdentifiers(), true)) {
        $e[] = '"' . $tz . '" isn\'t a timezone this server knows.';
    }
    $v['timezone'] = $tz;

    $v['recent_count']      = setup_int($p, 'recent_count', 0, 200, 'Recent activities to show', $e);
    $v['fetch_count']       = setup_int($p, 'fetch_count', 1, 200, 'Activities to fetch', $e);
    $v['cache_ttl']         = setup_int($p, 'cache_ttl', 0, 86400, 'Cache time', $e);
    $v['browser_cache_ttl'] = setup_int($p, 'browser_cache_ttl', 0, 86400, 'Browser cache time', $e);
    if ($v['recent_count'] > $v['fetch_count']) {
        $e[] = 'Recent activities to show can\'t be more than activities to fetch.';
    }

    $origins = [];
    foreach (preg_split('/[\s,]+/', (string)($p['allowed_origins'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) as $o) {
        $o = rtrim($o, '/');
        if ($o !== '*' && !preg_match('#^https?://[A-Za-z0-9.\-]+(:\d+)?$#', $o)) {
            $e[] = '"' . $o . '" isn\'t a valid site address. Use the form https://example.com (no path).';
        }
        if (!in_array($o, $origins, true)) $origins[] = $o;
    }
    $v['allowed_origins'] = $origins;
    $v['debug'] = !empty($p['debug']);

    foreach (['token_file' => 'Token file', 'cache_file' => 'Cache file'] as $key => $label) {
        $path = trim((string)($p[$key] ?? ''));
        if ($path === '') {
            $e[] = $label . ' can\'t be empty.';
            $path = strava_default_config()[$key];
        } elseif (!setup_is_absolute($path)) {
            $path = rtrim($configDir, '/\\') . '/' . ltrim($path, './\\');
        }
        if (!setup_dir_writable(dirname($path))) {
            $e[] = $label . ': the web server can\'t write to ' . dirname($path) . '.';
        }
        $v[$key] = $path;
    }
    if ($v['token_file'] === $v['cache_file']) $e[] = 'The token file and the cache file must be different files.';

    return [$v, $e];
}

// ===================================================================== setup

$configFile = getenv('STRAVA_CONFIG') ?: __DIR__ . '/config.php';
$configDir  = dirname($configFile);
$nonce      = base64_encode(random_bytes(12));

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; script-src 'nonce-$nonce'; "
     . "img-src https: data:; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");

try {
    $raw = setup_read_raw($configFile);
    $cfg = strava_load_config();
} catch (Throwable $e) {
    http_response_code(500);
    setup_page('Configuration error', '<h1>config.php has a problem</h1><div class="alert fail"><p>'
        . h($e->getMessage()) . '</p></div><p>Fix or delete <code>' . h($configFile)
        . '</code>, then reload this page.</p>', [], '', $nonce);
    exit;
}

if (!empty($raw['setup_locked'])) {
    http_response_code(404);
    setup_page('Installer locked', '<h1>The installer is locked</h1><p>To use it again, open <code>config.php</code> '
        . 'and change <code>\'setup_locked\' => true</code> to <code>false</code>.</p>', [], '', $nonce);
    exit;
}

$dirUrl = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/'))), '/') . '/';
session_name('strava_setup');
session_set_cookie_params([
    'lifetime' => 0, 'path' => $dirUrl, 'secure' => setup_is_https(), 'httponly' => true, 'samesite' => 'Lax',
]);
session_start();
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$csrf = (string)$_SESSION['csrf'];

$hash       = (string)($raw['setup_password_hash'] ?? '');
$authed     = $hash !== '' && isset($_SESSION['auth']) && hash_equals(setup_auth_tag($hash), (string)$_SESSION['auth']);
$needsClaim = $hash === '' && $cfg['client_secret'] !== '';
$reqs       = setup_requirements($configFile, $cfg, false);
$reqOk      = !array_filter($reqs, function ($r) { return $r[1] === 'fail'; });
$hasApp     = $cfg['client_id'] !== '' && $cfg['client_secret'] !== '';
$tokens     = strava_read_json($cfg['token_file']) ?? [];
$connected  = !empty($tokens['refresh_token']);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = (string)($method === 'POST' ? ($_POST['action'] ?? '') : ($_GET['action'] ?? ''));

// ==================================================================== actions

if ($method === 'POST') {
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
        setup_flash('fail', 'Your session expired. Please try again.');
        setup_redirect();
    }
    $back = 'step=' . rawurlencode((string)($_POST['step'] ?? ''));

    if ($action === 'login') {
        if ($hash !== '' && password_verify((string)($_POST['password'] ?? ''), $hash)) {
            session_regenerate_id(true);
            $_SESSION['auth'] = setup_auth_tag($hash);
            setup_redirect();
        }
        sleep(2);
        setup_flash('fail', 'That password is not correct.');
        setup_redirect();
    }

    if ($action === 'logout') {
        $_SESSION = [];
        session_destroy();
        setup_redirect();
    }

    if ($action === 'set_password') {
        if (!($authed || ($hash === '' && $reqOk))) setup_redirect();
        if ($hash === '' && $needsClaim && !hash_equals($cfg['client_secret'], trim((string)($_POST['claim_secret'] ?? '')))) {
            sleep(2);
            setup_flash('fail', 'That Client Secret doesn\'t match the one this install is using.');
            setup_redirect('step=password');
        }
        $pw = (string)($_POST['password'] ?? '');
        if (strlen($pw) < 8) {
            setup_flash('fail', 'Use a password of at least 8 characters.');
            setup_redirect('step=password');
        }
        if (!hash_equals($pw, (string)($_POST['password2'] ?? ''))) {
            setup_flash('fail', 'The two passwords don\'t match.');
            setup_redirect('step=password');
        }
        $newHash = password_hash($pw, PASSWORD_DEFAULT);
        $raw['setup_password_hash'] = $newHash;
        $raw['setup_locked'] = false;
        try {
            setup_write_config($configFile, $raw);
        } catch (Throwable $e) {
            setup_flash('fail', $e->getMessage());
            setup_redirect('step=password');
        }
        session_regenerate_id(true);
        $_SESSION['auth'] = setup_auth_tag($newHash);
        if ($hash === '') {
            setup_redirect($hasApp ? 'step=options' : 'step=app');
        }
        setup_flash('ok', 'Installer password changed.');
        setup_redirect('step=finish');
    }

    if (!$authed) {
        setup_flash('fail', 'Please log in first.');
        setup_redirect();
    }

    if ($action === 'save_app') {
        $id = trim((string)($_POST['client_id'] ?? ''));
        $secret = trim((string)($_POST['client_secret'] ?? ''));
        $oldSecret = (string)($raw['client_secret'] ?? '');
        if ($secret === '') $secret = $oldSecret;
        $errors = [];
        if (!preg_match('/^\d+$/', $id)) $errors[] = 'The Client ID is a number, like 123456.';
        if ($secret === '') $errors[] = 'Enter the Client Secret.';
        elseif (!preg_match('/^[A-Za-z0-9]{20,}$/', $secret)) $errors[] = 'That doesn\'t look like a Client Secret. It\'s a long string of letters and numbers; click "Show" on Strava\'s page to see it.';
        if ($errors) {
            foreach ($errors as $m) setup_flash('fail', $m);
            $_SESSION['old_app'] = ['client_id' => $id];
            setup_redirect('step=app');
        }
        $changedApp = $connected && (string)($raw['client_id'] ?? $cfg['client_id']) !== $id;
        $raw['client_id'] = $id;
        $raw['client_secret'] = $secret;
        try {
            setup_write_config($configFile, $raw);
        } catch (Throwable $e) {
            setup_flash('fail', $e->getMessage());
            setup_redirect('step=app');
        }
        if ($changedApp) {
            @unlink($cfg['token_file']);
            @unlink($cfg['cache_file']);
            setup_flash('warn', 'You switched to a different Strava app, so the old connection was removed. Connect your account again.');
        } else {
            setup_flash('ok', 'Strava app saved.');
        }
        setup_redirect('step=options');
    }

    if ($action === 'save_options') {
        [$v, $errors] = setup_parse_options($_POST, $configDir);
        if ($errors) {
            foreach ($errors as $m) setup_flash('fail', $m);
            $_SESSION['old_options'] = $v + ['other_types_raw' => (string)($_POST['other_types'] ?? '')];
            setup_redirect('step=options');
        }
        // Moving storage: bring the existing files along.
        foreach (['token_file', 'cache_file'] as $key) {
            if ($v[$key] !== $cfg[$key] && is_file($cfg[$key]) && !is_file($v[$key])) {
                $dir = dirname($v[$key]);
                if (!is_dir($dir)) @mkdir($dir, 0750, true);
                if (!@rename($cfg[$key], $v[$key]) && $key === 'token_file') {
                    setup_flash('warn', 'Could not move your tokens to the new location, so you\'ll need to connect again.');
                }
            }
        }
        $raw = array_merge($raw, $v);
        try {
            setup_write_config($configFile, $raw);
        } catch (Throwable $e) {
            setup_flash('fail', $e->getMessage());
            setup_redirect('step=options');
        }
        @unlink($cfg['cache_file']);
        @unlink($v['cache_file']);
        $newTokens = strava_read_json($v['token_file']) ?? [];
        if (!empty($newTokens['refresh_token']) && setup_missing_scopes($v['scope'], $newTokens)) {
            setup_flash('warn', 'You changed which data Strava Stats may read. Reconnect your account so Strava can ask you to approve it.');
            setup_redirect('step=connect');
        }
        setup_flash('ok', 'Options saved.');
        setup_redirect(!empty($newTokens['refresh_token']) ? 'step=finish' : 'step=connect');
    }

    if ($action === 'disconnect') {
        if ($connected) {
            try {
                $token = strava_access_token($cfg);
                strava_http('POST', STRAVA_OAUTH_BASE . '/deauthorize', [], ['access_token' => $token]);
            } catch (Throwable $e) {
                // Already revoked or unreachable; removing the local files is what matters.
            }
        }
        @unlink($cfg['token_file']);
        @unlink($cfg['cache_file']);
        setup_flash('ok', 'Disconnected. Strava Stats no longer has access to your account.');
        setup_redirect('step=connect');
    }

    if ($action === 'refresh') {
        try {
            strava_get_payload($cfg, true);
            setup_flash('ok', 'Fetched fresh data from Strava.');
        } catch (Throwable $e) {
            $_SESSION['test_error'] = $e->getMessage();
        }
        setup_redirect('step=finish');
    }

    if ($action === 'lock') {
        $raw['setup_locked'] = true;
        try {
            setup_write_config($configFile, $raw);
        } catch (Throwable $e) {
            setup_flash('fail', $e->getMessage());
            setup_redirect('step=finish');
        }
        $_SESSION = [];
        session_destroy();
        setup_page('Installer locked', '<h1>Installer locked</h1><p>Your settings are saved and this page is now switched off. '
            . 'To use it again, change <code>\'setup_locked\' => true</code> to <code>false</code> in <code>config.php</code>.</p>'
            . '<p><a class="btn" href="' . h(setup_dir_url() . 'strava.php') . '">View your feed</a></p>', [], '', $nonce);
        exit;
    }

    setup_redirect();
}

if ($action === 'authorize' || $action === 'callback') {
    if (!$authed) {
        setup_flash('fail', 'Your installer session ended. Log in and click Connect again.');
        setup_redirect();
    }
    if (!$hasApp) setup_redirect('step=app');

    if ($action === 'authorize') {
        if (!hash_equals($csrf, (string)($_GET['csrf'] ?? ''))) setup_redirect('step=connect');
        $state = bin2hex(random_bytes(16));
        $_SESSION['oauth_state'] = $state;
        header('Location: ' . STRAVA_OAUTH_BASE . '/authorize?' . http_build_query([
            'client_id'       => $cfg['client_id'],
            'response_type'   => 'code',
            'redirect_uri'    => setup_self_url() . '?action=callback',
            'approval_prompt' => 'force',
            'scope'           => $cfg['scope'],
            'state'           => $state,
        ]), true, 303);
        exit;
    }

    // callback
    $expected = (string)($_SESSION['oauth_state'] ?? '');
    unset($_SESSION['oauth_state']);
    if (!empty($_GET['error'])) {
        setup_flash('fail', $_GET['error'] === 'access_denied'
            ? 'You clicked Cancel on Strava, so nothing was connected. Click Connect to try again.'
            : 'Strava said: ' . (string)$_GET['error']);
        setup_redirect('step=connect');
    }
    if ($expected === '' || !hash_equals($expected, (string)($_GET['state'] ?? ''))) {
        setup_flash('fail', 'That sign-in link was out of date. Click Connect to start again.');
        setup_redirect('step=connect');
    }
    $code = (string)($_GET['code'] ?? '');
    if (!preg_match('/^[A-Za-z0-9]+$/', $code)) {
        setup_flash('fail', 'Strava didn\'t send back an authorization code. Click Connect to try again.');
        setup_redirect('step=connect');
    }
    $granted = isset($_GET['scope']) ? (string)$_GET['scope'] : null;
    try {
        $data = strava_exchange_code($cfg, $code, $granted);
    } catch (Throwable $e) {
        setup_flash('fail', 'Connecting failed: ' . $e->getMessage());
        setup_redirect('step=connect');
    }
    @unlink($cfg['cache_file']);
    $name = trim(($data['athlete']['firstname'] ?? '') . ' ' . ($data['athlete']['lastname'] ?? ''));
    setup_flash('ok', 'Connected' . ($name !== '' ? ' as ' . $name : '') . '.');
    $missing = setup_missing_scopes($cfg['scope'], ['scope' => $granted]);
    if ($granted !== null && $missing) {
        setup_flash('warn', 'Some permissions were unticked on Strava\'s page, so this data won\'t be available: '
            . implode('; ', setup_scope_text(implode(',', $missing))) . '. Reconnect and leave them ticked if you want it.');
    }
    setup_redirect('step=finish');
}

// ===================================================================== render

$flash = $_SESSION['flash'] ?? [];
unset($_SESSION['flash']);

if ($hash !== '' && !$authed) {
    setup_page('Log in', render_login($csrf), [], '', $nonce, $flash);
    exit;
}

$steps = [
    'requirements' => [true, $reqOk],
    'password'     => [$reqOk || $hash !== '', $hash !== ''],
    'app'          => [$authed, $hasApp],
    'options'      => [$authed && $hasApp, $hasApp],
    'connect'      => [$authed && $hasApp, $connected],
    'finish'       => [$authed && $connected, false],
];
$step = (string)($_GET['step'] ?? '');
if (!isset($steps[$step]) || !$steps[$step][0]) {
    if ($hash === '' || !$reqOk) $step = 'requirements';
    elseif (!$hasApp) $step = 'app';
    elseif (!$connected) $step = 'connect';
    else $step = 'finish';
}

$ctx = compact('cfg', 'raw', 'csrf', 'hash', 'needsClaim', 'reqOk', 'hasApp', 'tokens', 'connected', 'configFile');
switch ($step) {
    case 'requirements': $body = render_requirements(setup_requirements($configFile, $cfg, true), $ctx); break;
    case 'password':     $body = render_password($ctx); break;
    case 'app':          $body = render_app($ctx); break;
    case 'options':      $body = render_options($ctx); break;
    case 'connect':      $body = render_connect($ctx); break;
    default:             $body = render_finish($ctx);
}
setup_page(SETUP_STEPS[$step], $body, $steps, $step, $nonce, $flash, $authed);

// ================================================================= templates

function csrf_field(string $csrf, string $action, string $step = ''): string
{
    return '<input type="hidden" name="csrf" value="' . h($csrf) . '"><input type="hidden" name="action" value="' . h($action) . '">'
         . ($step !== '' ? '<input type="hidden" name="step" value="' . h($step) . '">' : '');
}

function copy_field(string $value, string $label = 'Copy'): string
{
    return '<span class="copy"><code>' . h($value) . '</code><button type="button" class="btn small ghost" data-copy="'
         . h($value) . '">' . h($label) . '</button></span>';
}

function render_login(string $csrf): string
{
    return '<h1>Strava Stats setup</h1><p class="lede">Enter your installer password to change settings.</p>'
        . '<form method="post" class="stack narrow">' . csrf_field($csrf, 'login')
        . '<label class="field"><span>Installer password</span><input type="password" name="password" autocomplete="current-password" required autofocus></label>'
        . '<div><button class="btn">Log in</button></div>'
        . '<p class="hint">Forgot it? Remove the <code>setup_password_hash</code> line from config.php. '
        . 'You\'ll then be asked for your Strava Client Secret to set a new one.</p></form>';
}

function render_requirements(array $reqs, array $c): string
{
    $icons = ['ok' => '✓', 'warn' => '!', 'fail' => '✕'];
    $failed = false;
    $li = '';
    foreach ($reqs as [$label, $level, $detail, $fix]) {
        if ($level === 'fail') $failed = true;
        $li .= '<li class="check ' . $level . '"><span class="icon" aria-hidden="true">' . $icons[$level] . '</span><div><strong>'
             . h($label) . '</strong><span class="detail">' . h($detail) . '</span>'
             . ($level !== 'ok' ? '<span class="fix">' . h($fix) . '</span>' : '') . '</div></li>';
    }
    $next = $c['hash'] === '' ? 'password' : ($c['hasApp'] ? 'options' : 'app');
    $out = '<h1>Check your server</h1><p class="lede">Strava Stats needs a few things from your web host. '
         . 'Anything marked ✕ has to be fixed before you continue.</p><ul class="checks">' . $li . '</ul>';
    if ($failed) {
        $out .= '<div class="actions"><a class="btn" href="?step=requirements">Check again</a></div>';
    } else {
        $out .= '<div class="actions"><a class="btn" href="?step=' . $next . '">Continue</a></div>';
    }
    return $out;
}

function render_password(array $c): string
{
    $first = $c['hash'] === '';
    $out = '<h1>' . ($first ? 'Protect this installer' : 'Change installer password') . '</h1>';
    if ($first) {
        $out .= '<p class="lede">Choose a password for this page. You\'ll need it whenever you come back to change settings.</p>'
              . '<div class="alert warn"><p><strong>Do this now.</strong> Until a password is set, anyone who finds this page could take over the setup.</p></div>';
    }
    $out .= '<form method="post" class="stack narrow">' . csrf_field($c['csrf'], 'set_password');
    if ($first && $c['needsClaim']) {
        $out .= '<label class="field"><span>Current Client Secret</span><input type="password" name="claim_secret" autocomplete="off" required>'
              . '<small>This install already has a Strava app set up. To prove it\'s yours, enter its Client Secret, found in config.php '
              . 'or at <a href="https://www.strava.com/settings/api" target="_blank" rel="noopener">strava.com/settings/api</a>.</small></label>';
    }
    $out .= '<label class="field"><span>' . ($first ? 'Password' : 'New password') . '</span><input type="password" name="password" minlength="8" autocomplete="new-password" required><small>At least 8 characters.</small></label>'
          . '<label class="field"><span>Repeat password</span><input type="password" name="password2" minlength="8" autocomplete="new-password" required></label>'
          . '<div class="actions"><button class="btn">' . ($first ? 'Save and continue' : 'Change password') . '</button></div></form>';
    return $out;
}

function render_app(array $c): string
{
    $old = $_SESSION['old_app'] ?? [];
    unset($_SESSION['old_app']);
    $id = $old['client_id'] ?? (string)($c['raw']['client_id'] ?? '');
    $hasSecret = (string)($c['raw']['client_secret'] ?? '') !== '';
    $domain = setup_callback_domain();

    return '<h1>Create your Strava API app</h1>'
        . '<p class="lede">Strava Stats reads your data through an "API application" that you create on Strava. It takes about a minute.</p>'
        . '<ol class="howto">'
        . '<li>Open <a href="https://www.strava.com/settings/api" target="_blank" rel="noopener">strava.com/settings/api</a> (log in if asked).</li>'
        . '<li>Fill in the form:<dl class="kv">'
        . '<dt>Application Name</dt><dd>Anything, e.g. "My website"</dd>'
        . '<dt>Category</dt><dd>Anything sensible, e.g. "Visualizer"</dd>'
        . '<dt>Website</dt><dd>' . copy_field(setup_origin()) . '</dd>'
        . '<dt>Authorization Callback Domain</dt><dd>' . copy_field($domain) . '<small>This one matters: it must be exactly this.</small></dd>'
        . '</dl></li>'
        . '<li>Click <strong>Create</strong>. Strava may ask you to upload an icon first; any image works.</li>'
        . '<li>Copy the <strong>Client ID</strong> and <strong>Client Secret</strong> (click "Show") into the boxes below.</li>'
        . '</ol>'
        . '<form method="post" class="stack narrow">' . csrf_field($c['csrf'], 'save_app')
        . '<label class="field"><span>Client ID</span><input name="client_id" inputmode="numeric" autocomplete="off" required value="' . h($id) . '" placeholder="123456"></label>'
        . setup_env_note('client_id')
        . '<label class="field"><span>Client Secret</span><input type="password" name="client_secret" autocomplete="off" '
        . ($hasSecret ? 'placeholder="Saved. Leave blank to keep it."' : 'required placeholder="40 letters and numbers"') . '>'
        . '<small>Stays on your server. It\'s never sent to visitors.</small></label>'
        . setup_env_note('client_secret')
        . '<div class="actions"><button class="btn">Save and continue</button></div></form>';
}

function render_options(array $c): string
{
    $o = $_SESSION['old_options'] ?? null;
    unset($_SESSION['old_options']);
    $cfg = $c['cfg'];
    $v = $o ?? $cfg;
    $types = (array)$v['activity_types'];
    $all = !$types || in_array('*', $types, true);
    $known = setup_all_types();
    $other = $o['other_types_raw'] ?? implode(', ', array_diff($types, $known, ['*']));
    $scope = array_map('trim', explode(',', (string)$v['scope']));

    $presetBtns = '';
    foreach (SETUP_PRESETS as $key => $p) {
        $presetBtns .= '<button type="button" class="chip" data-preset="' . h($key) . '">' . h($p['label']) . '</button>';
    }

    $groups = '';
    foreach (SETUP_SPORT_GROUPS as $group => $list) {
        $boxes = '';
        foreach ($list as $t) {
            $boxes .= '<label class="tick"><input type="checkbox" name="types[]" value="' . h($t) . '"'
                    . (!$all && in_array($t, $types, true) ? ' checked' : '') . '> ' . h(setup_type_label($t)) . '</label>';
        }
        $groups .= '<div class="group"><div class="group-head"><span>' . h($group) . '</span>'
                 . '<button type="button" class="linkish" data-toggle-group>All / none</button></div><div class="ticks">' . $boxes . '</div></div>';
    }

    $stats = '';
    foreach (SETUP_STATS_FIELDS as $k => $label) {
        $stats .= '<label class="tick"><input type="checkbox" name="stats[]" value="' . h($k) . '"'
                . (in_array($k, (array)$v['stats_fields'], true) ? ' checked' : '') . '> ' . h($label) . '</label>';
    }
    $athlete = '';
    foreach (SETUP_ATHLETE_FIELDS as $k => $label) {
        $athlete .= '<label class="tick"><input type="checkbox" name="athlete[]" value="' . h($k) . '"'
                  . (in_array($k, (array)$v['athlete_fields'], true) ? ' checked' : '') . '> ' . h($label) . '</label>';
    }

    $tzOpts = '<option value="">Automatic (from your latest activity)</option>';
    foreach (DateTimeZone::listIdentifiers() as $tz) {
        $tzOpts .= '<option' . ($tz === $v['timezone'] ? ' selected' : '') . '>' . h($tz) . '</option>';
    }

    $ttls = [60, 300, 600, 900, 1800, 3600, 0];
    if (!in_array((int)$v['cache_ttl'], $ttls, true)) array_unshift($ttls, (int)$v['cache_ttl']);
    $ttlOpts = '';
    foreach ($ttls as $t) {
        $note = $t === 0 ? 'every visit calls Strava (not for public sites)'
              : (86400 / $t * 3 > 1000 ? 'can go over Strava\'s daily limit' : 'at most ' . number_format((int)ceil(86400 / $t * 3)) . ' requests a day');
        $ttlOpts .= '<option value="' . $t . '"' . ($t === (int)$v['cache_ttl'] ? ' selected' : '') . '>'
                  . h(setup_human_duration($t) . ($t === 300 ? ' (recommended)' : '') . ' · ' . $note) . '</option>';
    }

    $presets = [];
    foreach (SETUP_PRESETS as $k => $p) $presets[$k] = ['types' => $p['types'], 'stats' => $p['stats']];

    $imperial = $v['display_units'] === 'imperial';
    $unitsChosen = isset($c['raw']['display_units']) || $o !== null;
    $unitCard = function ($value, $title, $example, $checked) {
        return '<label class="unit"><input type="radio" name="display_units" value="' . $value . '"' . ($checked ? ' checked' : '') . '>'
             . '<span><strong>' . $title . '</strong><small>' . $example . '</small></span></label>';
    };

    return '<h1>Choose what to show</h1><p class="lede">The defaults suit a cyclist. Change anything you like; you can come back later.</p>'
        . '<form method="post" class="stack" id="options">' . csrf_field($c['csrf'], 'save_options')
        . '<script type="application/json" id="presets">' . json_encode($presets, JSON_HEX_TAG | JSON_HEX_AMP) . '</script>'

        . '<fieldset><legend>Units</legend>'
        . '<p class="hint">How distances and climbing are shown on your website.</p>'
        . '<div class="units"' . ($unitsChosen ? '' : ' data-guess-units') . '>'
        . $unitCard('metric', 'Kilometres and metres', 'e.g. 42.5 km · 610 m climbing', !$imperial)
        . $unitCard('imperial', 'Miles and feet', 'e.g. 26.4 mi · 2,001 ft climbing', $imperial)
        . '</div><p class="hint">You can switch later. Pages using the code from this installer or <code>example.html</code> follow this setting automatically.</p>'
        . setup_env_note('display_units') . '</fieldset>'

        . '<fieldset><legend>Activities that count</legend>'
        . '<p class="hint">These feed your weekly totals and your recent activity list.</p>'
        . '<div class="chips"><span>Quick pick:</span>' . $presetBtns . '</div>'
        . '<label class="tick strong"><input type="checkbox" name="all_types" value="1" id="all_types"' . ($all ? ' checked' : '') . '> All activity types</label>'
        . '<div class="groups" id="type_groups">' . $groups . '</div>'
        . '<label class="field"><span>Other types</span><input name="other_types" id="other_types" value="' . h($other) . '" placeholder="e.g. Dance, Padel">'
        . '<small>Any other <a href="https://developers.strava.com/docs/reference/#api-models-SportType" target="_blank" rel="noopener">Strava sport types</a>, separated by commas.</small></label>'
        . setup_env_note('activity_types') . '</fieldset>'

        . '<fieldset><legend>Totals to publish</legend><div class="ticks">' . $stats . '</div>'
        . '<p class="hint">Strava only counts activities visible to Everyone in these totals.</p>' . setup_env_note('stats_fields') . '</fieldset>'

        . '<fieldset><legend>Profile details to publish</legend><div class="ticks">' . $athlete . '</div>'
        . '<p class="hint">Anything unticked never leaves your server.</p>' . setup_env_note('athlete_fields') . '</fieldset>'

        . '<fieldset><legend>Privacy</legend>'
        . '<label class="tick block"><input type="checkbox" name="scope_private" value="1"' . (in_array('activity:read_all', $scope, true) ? ' checked' : '') . '> '
        . '<span><strong>Include "Only You" activities</strong><small>Private activities count towards weekly totals and can appear in the recent list.</small></span></label>'
        . '<label class="tick block"><input type="checkbox" name="scope_profile" value="1"' . (in_array('profile:read_all', $scope, true) ? ' checked' : '') . '> '
        . '<span><strong>Allow access to FTP and weight</strong><small>Needed only if you publish FTP or weight.</small></span></label>'
        . '<p class="hint">Changing these means reconnecting your account so Strava can ask you to approve them.</p>' . setup_env_note('scope') . '</fieldset>'

        . '<fieldset><legend>Weeks</legend><div class="row">'
        . '<label class="field"><span>Week starts on</span><select name="week_starts_on"><option value="monday">Monday</option><option value="sunday"' . ($v['week_starts_on'] === 'sunday' ? ' selected' : '') . '>Sunday</option></select></label>'
        . '<label class="field grow"><span>Timezone</span><select name="timezone" id="timezone">' . $tzOpts . '</select>'
        . '<small id="tz_hint" hidden><button type="button" class="linkish" id="tz_use"></button></small></label></div></fieldset>'

        . '<fieldset><legend>Recent activities</legend><div class="row">'
        . '<label class="field"><span>How many to show</span><input type="number" name="recent_count" min="0" max="200" value="' . h($v['recent_count']) . '"></label>'
        . '<label class="field"><span>How many to fetch</span><input type="number" name="fetch_count" min="1" max="200" value="' . h($v['fetch_count']) . '">'
        . '<small>Raise this if you log more than this many activities (of any type) in a week.</small></label></div></fieldset>'

        . '<fieldset><legend>Caching</legend>'
        . '<label class="field"><span>Ask Strava for new data every</span><select name="cache_ttl">' . $ttlOpts . '</select>'
        . '<small>Strava allows 1,000 requests a day, and each refresh uses 3. Visitors in between get the saved copy.</small></label>'
        . '<label class="field short"><span>Browser cache (seconds)</span><input type="number" name="browser_cache_ttl" min="0" max="86400" value="' . h($v['browser_cache_ttl']) . '"></label></fieldset>'

        . '<fieldset><legend>Other websites</legend>'
        . '<label class="field"><span>Sites allowed to load your stats</span><textarea name="allowed_origins" rows="3" placeholder="https://example.com">' . h(implode("\n", (array)$v['allowed_origins'])) . '</textarea>'
        . '<small>Only needed if the page showing your stats is on a <em>different</em> domain from ' . h(setup_host()) . '. One per line, like https://example.com. Use * to allow any site.</small></label></fieldset>'

        . '<details><summary>Advanced</summary><div class="stack">'
        . '<label class="field"><span>Token file</span><input name="token_file" value="' . h($v['token_file']) . '" spellcheck="false">'
        . '<small>Safest outside your public web folder. Existing tokens are moved for you.</small></label>'
        . '<label class="field"><span>Cache file</span><input name="cache_file" value="' . h($v['cache_file']) . '" spellcheck="false"></label>'
        . '<label class="tick"><input type="checkbox" name="debug" value="1"' . (!empty($v['debug']) ? ' checked' : '') . '> Show detailed errors in the feed (turn off when you\'re done)</label>'
        . '</div></details>'

        . '<div class="actions sticky"><button class="btn">Save options</button></div></form>';
}

function render_connect(array $c): string
{
    $cfg = $c['cfg'];
    $t = $c['tokens'];
    $domain = setup_callback_domain();
    $link = '?action=authorize&csrf=' . rawurlencode($c['csrf']);
    $perms = '<ul class="perms">';
    foreach (setup_scope_text($cfg['scope']) as $p) $perms .= '<li>' . h($p) . '</li>';
    $perms .= '</ul>';

    if ($c['connected']) {
        $name = (string)($t['athlete_name'] ?? '');
        $missing = setup_missing_scopes($cfg['scope'], $t);
        $out = '<h1>Your Strava account</h1>'
             . '<div class="status ok"><span class="dot"></span><div><strong>Connected' . ($name !== '' ? ' as ' . h($name) : '') . '</strong>'
             . (!empty($t['athlete_id']) ? '<span class="detail">Athlete ' . h($t['athlete_id']) . ' · <a href="https://www.strava.com/athletes/' . h($t['athlete_id']) . '" target="_blank" rel="noopener">view profile</a></span>' : '')
             . '</div></div>';
        if ($missing) {
            $out .= '<div class="alert warn"><p><strong>Reconnect needed.</strong> Your options ask for data this connection doesn\'t cover yet: '
                  . h(implode('; ', setup_scope_text(implode(',', $missing)))) . '.</p></div>';
        }
        $out .= '<div class="actions"><a class="btn' . ($missing ? '' : ' ghost') . '" href="' . h($link) . '">Reconnect</a>'
              . (!$missing ? '<a class="btn" href="?step=finish">Continue</a>' : '') . '</div>'
              . '<form method="post" class="danger-zone">' . csrf_field($c['csrf'], 'disconnect')
              . '<p><strong>Disconnect</strong><br><span class="hint">Revokes access on Strava and deletes the saved tokens. Your feed stops working until you connect again.</span></p>'
              . '<button class="btn ghost danger">Disconnect</button></form>';
        return $out;
    }

    return '<h1>Connect your Strava account</h1>'
        . '<p class="lede">Strava will ask you to approve access, then send you straight back here.</p>'
        . '<div class="alert info"><p><strong>Check this first.</strong> In your app at <a href="https://www.strava.com/settings/api" target="_blank" rel="noopener">strava.com/settings/api</a>, '
        . 'the <em>Authorization Callback Domain</em> must be:</p><p>' . copy_field($domain) . '</p>'
        . '<p class="hint">If it isn\'t, Strava shows a "redirect_uri invalid" error. Click Edit on that page to change it.</p></div>'
        . '<p>Strava Stats will be able to read:</p>' . $perms
        . '<div class="actions"><a class="btn big" href="' . h($link) . '">Connect with Strava</a></div>'
        . '<p class="hint">It can only read. It can\'t post, edit or delete anything on your account.</p>';
}

function render_finish(array $c): string
{
    $cfg = $c['cfg'];
    $endpoint = setup_dir_url() . 'strava.php';
    $error = $_SESSION['test_error'] ?? null;
    unset($_SESSION['test_error']);
    $payload = null;
    if ($error === null) {
        try {
            $payload = strava_get_payload($cfg);
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }

    $out = '<h1>' . ($payload ? 'You\'re all set' : 'Test your feed') . '</h1>';

    if ($payload) {
        $a = $payload['athlete'] ?? [];
        $w = $payload['week'] ?? [];
        $mi = $cfg['display_units'] === 'imperial';
        $dist = function ($m) use ($mi) { return $mi ? number_format($m / 1609.344, 1) . ' mi' : number_format($m / 1000, 1) . ' km'; };
        $elev = function ($m) use ($mi) { return $mi ? number_format($m * 3.28084) . ' ft' : number_format($m) . ' m'; };
        $name = trim(($a['firstname'] ?? '') . ' ' . ($a['lastname'] ?? ''));
        $recent = '';
        foreach (array_slice($payload['recent'] ?? [], 0, 3) as $r) {
            $date = !empty($r['start_date']) ? date('M j', strtotime((string)$r['start_date'])) : '';
            $recent .= '<li><span>' . h($r['name']) . '</span><span class="detail">' . h($date) . ' · ' . $dist((float)$r['distance']) . '</span></li>';
        }
        $out .= '<div class="preview">'
              . '<div class="who">' . (!empty($a['profile']) && strpos((string)$a['profile'], 'https://') === 0 ? '<img src="' . h($a['profile']) . '" alt="">' : '')
              . '<div><strong>' . h($name !== '' ? $name : 'Your feed is working') . '</strong><span class="detail">Feed generated '
              . h(date('M j, g:i a', strtotime((string)$payload['generated_at']))) . ' (server time)'
              . (!empty($payload['stale']) ? ' · <b>saved copy</b>, Strava was unreachable' : '') . '</span></div></div>'
              . '<div class="tiles"><div><b>' . (int)($w['count'] ?? 0) . '</b>activities this week</div>'
              . '<div><b>' . $dist((float)($w['distance'] ?? 0)) . '</b>distance</div>'
              . '<div><b>' . $elev((float)($w['elevation_gain'] ?? 0)) . '</b>climbing</div></div>'
              . ($recent !== '' ? '<ul class="recent">' . $recent . '</ul>' : '<p class="hint">No matching activities in your latest ' . (int)$cfg['fetch_count'] . '. Check "Activities that count" in Options.</p>')
              . '<form method="post">' . csrf_field($c['csrf'], 'refresh') . '<button class="btn small ghost">Fetch fresh data</button></form>'
              . '</div>';
    } else {
        $out .= '<div class="alert fail"><p><strong>The feed isn\'t working yet.</strong></p><p>' . h($error) . '</p></div>'
              . '<form method="post" class="actions">' . csrf_field($c['csrf'], 'refresh') . '<button class="btn">Try again</button>'
              . '<a class="btn ghost" href="?step=connect">Check connection</a></form>';
    }

    $snippet = "<div id=\"strava\"></div>\n<script>\nfetch('" . $endpoint . "')\n  .then(r => r.json())\n  .then(data => {\n"
             . "    const miles = data.display_units === 'imperial';\n"
             . "    const d = miles ? (data.week.distance / 1609.344).toFixed(1) + ' mi'\n"
             . "                    : (data.week.distance / 1000).toFixed(1) + ' km';\n"
             . "    document.getElementById('strava').textContent =\n"
             . "      `\${data.week.count} activities, \${d} this week`;\n  });\n</script>";

    $out .= '<h2>Your feed</h2><p>Your stats are published as JSON here:</p><p>' . copy_field($endpoint) . ' <a class="btn small ghost" href="' . h($endpoint) . '" target="_blank" rel="noopener">Open</a></p>'
          . '<p>Paste this into any page on your site to show this week\'s totals. <code>example.html</code> has a fuller widget.</p>'
          . '<div class="code"><pre>' . h($snippet) . '</pre><button type="button" class="btn small ghost" data-copy="' . h($snippet) . '">Copy</button></div>'
          . '<p class="hint">Strava\'s rules ask you to show a "Powered by Strava" logo and link back to Strava wherever you display this data.</p>';

    if ($c['connected']) {
        [$st, $url, $msg] = setup_probe_private($cfg['token_file']);
        $cls = ['ok' => 'ok', 'outside' => 'ok', 'bad' => 'fail', 'unknown' => 'warn'][$st];
        $out .= '<h2>Security check</h2><div class="status ' . $cls . '"><span class="dot"></span><div><strong>'
              . h(['ok' => 'Tokens are private', 'outside' => 'Tokens are private', 'bad' => 'Your tokens are public!', 'unknown' => 'Couldn\'t check your tokens'][$st])
              . '</strong><span class="detail">' . h($msg) . '</span></div></div>';
        if ($st === 'bad' || $st === 'unknown') {
            $dir = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
            $out .= ($st === 'unknown' && $url !== '' ? '<p>Check it yourself: opening this should give an error, not a file. ' . copy_field($url) . '</p>' : '')
                  . '<p>On Apache and LiteSpeed the included <code>.htaccess</code> files handle this. On nginx, add this to your server block:</p>'
                  . '<div class="code"><pre>' . h("location ~ ^" . $dir . "/(data/|config\\.php|config\\.example\\.php|lib\\.php|connect\\.php) {\n    deny all;\n    return 404;\n}") . '</pre></div>'
                  . '<p>Or move the token file outside your web folder under Options → Advanced.</p>';
        }
    }

    $out .= '<h2>Installer</h2><div class="danger-zone"><p><strong>Lock the installer</strong><br>'
          . '<span class="hint">Switches this page off once you\'re happy. To use it again, set <code>setup_locked</code> to <code>false</code> in config.php. Deleting setup.php works too.</span></p>'
          . '<form method="post">' . csrf_field($c['csrf'], 'lock') . '<button class="btn">Lock installer</button></form></div>'
          . '<p class="links"><a href="?step=password">Change installer password</a> · <a href="?step=options">Edit options</a></p>';
    return $out;
}

function setup_page(string $title, string $body, array $steps, string $current, string $nonce, array $flash = [], bool $authed = false): void
{
    $nav = '';
    if ($steps) {
        $i = 0;
        foreach (SETUP_STEPS as $key => $label) {
            $i++;
            [$available, $done] = $steps[$key];
            $cls = ($key === $current ? 'current ' : '') . ($done ? 'done ' : '') . (!$available ? 'locked' : '');
            $inner = '<span class="num">' . ($done && $key !== $current ? '✓' : $i) . '</span><span class="label">' . h($label) . '</span>';
            $nav .= '<li class="' . trim($cls) . '">' . ($available && $key !== $current ? '<a href="?step=' . $key . '">' . $inner . '</a>' : '<span>' . $inner . '</span>') . '</li>';
        }
        $nav = '<nav aria-label="Setup steps"><ol class="steps">' . $nav . '</ol></nav>';
    }
    $msgs = '';
    foreach ($flash as [$type, $m]) $msgs .= '<div class="alert ' . h($type) . '" role="status"><p>' . h($m) . '</p></div>';
    $logout = $authed ? '<form method="post" class="logout"><input type="hidden" name="csrf" value="' . h($_SESSION['csrf'] ?? '') . '"><input type="hidden" name="action" value="logout"><button class="linkish">Log out</button></form>' : '';

    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
       . '<meta name="robots" content="noindex, nofollow"><title>' . h($title) . ' · Strava Stats setup</title><style>' . setup_css() . '</style></head><body>'
       . '<header class="top"><div class="brand"><span class="mark" aria-hidden="true"></span>Strava Stats <span>setup</span></div>' . $logout . '</header>'
       . '<div class="shell' . ($nav ? '' : ' solo') . '">' . $nav . '<main>' . $msgs . $body . '</main></div>'
       . '<footer>Strava Stats ' . h(STRAVA_STATS_VERSION) . ' · not affiliated with Strava, Inc.</footer>'
       . '<script nonce="' . h($nonce) . '">' . setup_js() . '</script></body></html>';
}

function setup_css(): string
{
    return <<<'CSS'
:root{--bg:#f5f4f0;--surface:#fff;--ink:#1c1b19;--muted:#6a6862;--line:#e3e0d8;--soft:#f0eee8;--accent:#e4570f;--accent-ink:#fff;--ok:#2d7a4c;--warn:#a86a12;--fail:#c13a2b;--info:#2f5f9e;--radius:10px;color-scheme:light}
@media (prefers-color-scheme:dark){:root{--bg:#121211;--surface:#1c1c1a;--ink:#ecebe6;--muted:#a19f98;--line:#33322e;--soft:#252522;--accent:#ff7433;--accent-ink:#1a0d05;--ok:#5fc289;--warn:#e0a54a;--fail:#f07a6c;--info:#7fa9e6;color-scheme:dark}}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--ink);font:16px/1.55 ui-sans-serif,system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;-webkit-font-smoothing:antialiased}
a{color:var(--accent)}
code{font:0.88em ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;background:var(--soft);padding:.1em .35em;border-radius:5px;overflow-wrap:anywhere}
.top{display:flex;justify-content:space-between;align-items:center;max-width:1040px;margin:0 auto;padding:22px 20px 0}
.brand{font-weight:700;letter-spacing:-.01em;display:flex;align-items:center;gap:10px}
.brand span{font-weight:400;color:var(--muted)}
.mark{width:22px;height:22px;border-radius:6px;background:linear-gradient(135deg,var(--accent),#f2a33a);display:inline-block}
.shell{max-width:1040px;margin:0 auto;padding:24px 20px 40px;display:grid;grid-template-columns:210px 1fr;gap:28px;align-items:start}
.shell.solo{grid-template-columns:1fr;max-width:560px}
main{background:var(--surface);border:1px solid var(--line);border-radius:14px;padding:32px 36px;min-width:0}
h1{font-size:1.65rem;line-height:1.2;letter-spacing:-.02em;margin:0 0 .4rem}
h2{font-size:1.1rem;margin:2.2rem 0 .6rem;padding-top:1.4rem;border-top:1px solid var(--line)}
.lede{color:var(--muted);margin:0 0 1.5rem;max-width:60ch}
.hint,small{color:var(--muted);font-size:.875rem}
.steps{list-style:none;margin:0;padding:0;position:sticky;top:20px}
.steps li>a,.steps li>span{display:flex;align-items:center;gap:12px;padding:9px 10px;border-radius:9px;color:var(--ink);text-decoration:none}
.steps li>a:hover{background:var(--surface)}
.steps .num{width:26px;height:26px;flex:none;border-radius:50%;display:grid;place-items:center;font-size:.8rem;font-weight:600;border:1.5px solid var(--line);background:var(--surface);color:var(--muted)}
.steps .done .num{border-color:var(--ok);color:var(--ok)}
.steps .current>span{background:var(--surface);box-shadow:inset 0 0 0 1px var(--line);font-weight:600}
.steps .current .num{background:var(--accent);border-color:var(--accent);color:var(--accent-ink)}
.steps .locked{opacity:.45}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;font:inherit;font-weight:600;padding:10px 18px;border-radius:9px;border:1px solid var(--accent);background:var(--accent);color:var(--accent-ink);text-decoration:none;cursor:pointer;line-height:1.2}
.btn:hover{filter:brightness(1.06)}
.btn.ghost{background:transparent;color:var(--ink);border-color:var(--line)}
.btn.ghost:hover{background:var(--soft);filter:none}
.btn.small{padding:5px 11px;font-size:.85rem;font-weight:500}
.btn.big{padding:14px 26px;font-size:1.05rem}
.btn.danger{color:var(--fail)}
.btn:focus-visible,input:focus-visible,select:focus-visible,textarea:focus-visible,.chip:focus-visible,.linkish:focus-visible{outline:2px solid var(--accent);outline-offset:2px}
.linkish{background:none;border:0;padding:0;font:inherit;color:var(--accent);cursor:pointer;text-decoration:underline;text-underline-offset:2px}
.actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:1.5rem}
.actions.sticky{position:sticky;bottom:0;background:linear-gradient(transparent,var(--surface) 30%);padding:18px 0 4px;margin-top:.5rem}
.stack{display:flex;flex-direction:column;gap:16px}
.narrow{max-width:420px}
.field{display:flex;flex-direction:column;gap:6px}
.field>span{font-weight:600;font-size:.92rem}
.field.short{max-width:220px}
.row{display:flex;gap:16px;flex-wrap:wrap}.row>.field{flex:1 1 180px}.row>.grow{flex:2 1 260px}
input[type=text],input:not([type]),input[type=password],input[type=number],select,textarea{font:inherit;width:100%;padding:10px 12px;border:1px solid var(--line);border-radius:8px;background:var(--surface);color:var(--ink)}
textarea{resize:vertical}
fieldset{border:0;border-top:1px solid var(--line);margin:0;padding:22px 0 4px;display:flex;flex-direction:column;gap:12px;min-width:0}
legend{font-weight:700;font-size:1.02rem;padding:0;float:left;width:100%;margin-bottom:4px}
fieldset>legend+*{clear:both}
.ticks{display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:4px 16px}
.tick{display:flex;gap:9px;align-items:flex-start;cursor:pointer;padding:3px 0}
.tick input{margin:.3em 0 0;accent-color:var(--accent);width:16px;height:16px;flex:none}
.tick.strong{font-weight:600}
.tick.block span{display:flex;flex-direction:column}
.groups{display:flex;flex-direction:column;gap:14px;transition:opacity .15s}
.groups.is-disabled{opacity:.4}
.group{background:var(--soft);border-radius:var(--radius);padding:12px 14px}
.group-head{display:flex;justify-content:space-between;align-items:baseline;font-weight:600;font-size:.9rem;margin-bottom:6px}
.group-head .linkish{font-size:.8rem;font-weight:400}
.units{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.unit{display:flex;gap:10px;align-items:flex-start;padding:14px 16px;border:1.5px solid var(--line);border-radius:var(--radius);cursor:pointer}
.unit:has(input:checked){border-color:var(--accent);background:color-mix(in srgb,var(--accent) 6%,var(--surface))}
.unit input{margin:.3em 0 0;accent-color:var(--accent);width:16px;height:16px;flex:none}
.unit span{display:flex;flex-direction:column}
@media (max-width:520px){.units{grid-template-columns:1fr}}
.chips{display:flex;gap:8px;align-items:center;flex-wrap:wrap;font-size:.9rem;color:var(--muted)}
.chip{font:inherit;font-size:.88rem;padding:5px 12px;border-radius:99px;border:1px solid var(--line);background:var(--surface);color:var(--ink);cursor:pointer}
.chip:hover{border-color:var(--accent)}
details{border-top:1px solid var(--line);padding-top:18px}
summary{cursor:pointer;font-weight:700}
details>.stack{margin-top:14px}
.alert{border-radius:var(--radius);padding:12px 16px;margin:0 0 16px;border:1px solid;background:var(--soft)}
.alert p{margin:.25rem 0}
.alert.ok{border-color:color-mix(in srgb,var(--ok) 40%,transparent);background:color-mix(in srgb,var(--ok) 9%,var(--surface))}
.alert.warn{border-color:color-mix(in srgb,var(--warn) 45%,transparent);background:color-mix(in srgb,var(--warn) 10%,var(--surface))}
.alert.fail{border-color:color-mix(in srgb,var(--fail) 45%,transparent);background:color-mix(in srgb,var(--fail) 9%,var(--surface))}
.alert.info{border-color:color-mix(in srgb,var(--info) 40%,transparent);background:color-mix(in srgb,var(--info) 8%,var(--surface))}
.envnote{font-size:.85rem;color:var(--warn);margin:-6px 0 0}
.checks{list-style:none;padding:0;margin:0;border:1px solid var(--line);border-radius:var(--radius);overflow:hidden}
.check{display:flex;gap:14px;padding:14px 16px;border-top:1px solid var(--line)}
.check:first-child{border-top:0}
.check .icon{width:24px;height:24px;flex:none;border-radius:50%;display:grid;place-items:center;font-size:.8rem;font-weight:700;color:#fff}
.check.ok .icon{background:var(--ok)}.check.warn .icon{background:var(--warn)}.check.fail .icon{background:var(--fail)}
.check strong{display:block}
.detail{display:block;color:var(--muted);font-size:.88rem;word-break:break-word}
.fix{display:block;font-size:.9rem;margin-top:4px}
.howto{padding-left:1.3rem;margin:0 0 1.8rem;display:flex;flex-direction:column;gap:10px}
.howto li::marker{font-weight:700;color:var(--accent)}
.kv{display:grid;grid-template-columns:max-content 1fr;gap:8px 16px;margin:10px 0 0;padding:14px 16px;background:var(--soft);border-radius:var(--radius);align-items:baseline}
.kv dt{font-weight:600;font-size:.9rem}.kv dd{margin:0;min-width:0}.kv small{display:block}
.copy{display:inline-flex;align-items:center;gap:8px;flex-wrap:wrap;max-width:100%}
.copy code{font-size:.92em}
.perms{margin:.3rem 0 0;padding-left:1.2rem}
.status{display:flex;gap:12px;align-items:flex-start;padding:14px 16px;border:1px solid var(--line);border-radius:var(--radius);margin-bottom:12px}
.status .dot{width:10px;height:10px;border-radius:50%;margin-top:.45em;flex:none;background:var(--muted)}
.status.ok .dot{background:var(--ok);box-shadow:0 0 0 4px color-mix(in srgb,var(--ok) 20%,transparent)}
.status.warn .dot{background:var(--warn)}.status.fail .dot{background:var(--fail)}
.status.fail{border-color:var(--fail)}
.danger-zone{display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap;border:1px solid var(--line);border-radius:var(--radius);padding:14px 16px;margin-top:1.6rem}
.danger-zone p{margin:0;flex:1 1 280px}
.preview{border:1px solid var(--line);border-radius:12px;padding:18px;display:flex;flex-direction:column;gap:16px}
.who{display:flex;gap:12px;align-items:center}
.who img{width:48px;height:48px;border-radius:50%;object-fit:cover}
.tiles{display:grid;grid-template-columns:repeat(3,1fr);gap:10px}
.tiles>div{background:var(--soft);border-radius:var(--radius);padding:12px;font-size:.85rem;color:var(--muted)}
.tiles b{display:block;font-size:1.3rem;color:var(--ink);font-variant-numeric:tabular-nums;letter-spacing:-.01em}
.tiles b span{font-size:.75rem;font-weight:500;color:var(--muted)}
.recent{list-style:none;margin:0;padding:0}
.recent li{display:flex;justify-content:space-between;gap:12px;padding:8px 0;border-top:1px solid var(--line)}
.recent li:first-child{border-top:0}
.code{position:relative;margin:.6rem 0}
.code pre{margin:0;background:var(--soft);border:1px solid var(--line);border-radius:var(--radius);padding:14px 16px;overflow-x:auto;font:.84rem/1.5 ui-monospace,SFMono-Regular,Menlo,Consolas,monospace}
.code .btn{position:absolute;top:8px;right:8px;background:var(--surface)}
.links{margin-top:1.2rem;font-size:.92rem}
.logout{margin:0}
footer{text-align:center;color:var(--muted);font-size:.8rem;padding:0 20px 30px}
@media (max-width:760px){
 .shell{grid-template-columns:1fr;gap:14px;padding:16px}
 .steps{position:static;display:flex;overflow-x:auto;gap:4px;padding-bottom:4px}
 .steps .label{display:none}.steps .current .label{display:inline}
 .steps li>a,.steps li>span{padding:6px 8px}
 main{padding:22px 18px}
 .kv{grid-template-columns:1fr}.kv dt{margin-top:6px}
 .tiles{grid-template-columns:1fr 1fr 1fr;gap:6px}.tiles b{font-size:1.05rem}
 .top{padding:16px 16px 0}
}
CSS;
}

function setup_js(): string
{
    return <<<'JS'
document.querySelectorAll('[data-copy]').forEach(function (b) {
  b.addEventListener('click', function () {
    var text = b.getAttribute('data-copy'), label = b.textContent;
    var done = function () { b.textContent = 'Copied'; setTimeout(function () { b.textContent = label; }, 1400); };
    if (navigator.clipboard && window.isSecureContext) { navigator.clipboard.writeText(text).then(done); return; }
    var t = document.createElement('textarea'); t.value = text; document.body.appendChild(t); t.select();
    try { document.execCommand('copy'); done(); } catch (e) {} document.body.removeChild(t);
  });
});

var form = document.getElementById('options');
if (form) {
  var all = document.getElementById('all_types'), groups = document.getElementById('type_groups'),
      other = document.getElementById('other_types'),
      types = form.querySelectorAll('input[name="types[]"]'), stats = form.querySelectorAll('input[name="stats[]"]'),
      presets = JSON.parse(document.getElementById('presets').textContent);
  var sync = function () {
    groups.classList.toggle('is-disabled', all.checked);
    types.forEach(function (c) { c.disabled = all.checked; });
    other.disabled = all.checked;
    form.querySelectorAll('[data-toggle-group]').forEach(function (b) { b.disabled = all.checked; });
  };
  all.addEventListener('change', sync); sync();
  form.querySelectorAll('[data-preset]').forEach(function (b) {
    b.addEventListener('click', function () {
      var p = presets[b.getAttribute('data-preset')];
      all.checked = p.types.indexOf('*') !== -1;
      types.forEach(function (c) { c.checked = p.types.indexOf(c.value) !== -1; });
      stats.forEach(function (c) { c.checked = p.stats.indexOf(c.value) !== -1; });
      if (!all.checked) other.value = '';
      sync();
    });
  });
  form.querySelectorAll('[data-toggle-group]').forEach(function (b) {
    b.addEventListener('click', function () {
      var boxes = b.closest('.group').querySelectorAll('input[type=checkbox]');
      var any = Array.prototype.some.call(boxes, function (c) { return !c.checked; });
      boxes.forEach(function (c) { c.checked = any; });
    });
  });
  var guess = form.querySelector('[data-guess-units]');
  if (guess) {
    try {
      var loc = (navigator.languages && navigator.languages[0]) || navigator.language || '';
      if (/-(US|LR|MM)$/i.test(loc)) {
        var imp = guess.querySelector('input[value="imperial"]'); if (imp) imp.checked = true;
      }
    } catch (e) {}
  }
  var tz = document.getElementById('timezone'), hint = document.getElementById('tz_hint'), use = document.getElementById('tz_use');
  try {
    var mine = Intl.DateTimeFormat().resolvedOptions().timeZone;
    if (mine && tz.value !== mine && tz.querySelector('option[value="' + mine + '"], option:not([value])')) {
      var has = Array.prototype.some.call(tz.options, function (o) { return o.value === mine; });
      if (has) { use.textContent = 'Use my timezone (' + mine + ')'; hint.hidden = false;
        use.addEventListener('click', function () { tz.value = mine; hint.hidden = true; }); }
    }
  } catch (e) {}
}
JS;
}
