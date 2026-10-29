<?php
/**
 * Strava Stats - shared library.
 *
 * Config loading, token storage and refresh, Strava API requests and the
 * summary payload. Used by strava.php (the endpoint) and connect.php (setup).
 */

if (!defined('STRAVA_STATS_VERSION')) {
    define('STRAVA_STATS_VERSION', '1.0.0');
}

// Overridable (define before including) for testing against a mock server.
if (!defined('STRAVA_API_BASE'))   define('STRAVA_API_BASE', 'https://www.strava.com/api/v3');
if (!defined('STRAVA_OAUTH_BASE')) define('STRAVA_OAUTH_BASE', 'https://www.strava.com/oauth');

class StravaException extends RuntimeException
{
    /** @var int HTTP status to send to the client */
    public $status;

    public function __construct(string $message, int $status = 502)
    {
        parent::__construct($message);
        $this->status = $status;
    }
}

// ===================================================================== config

/**
 * Load config.php (or the file named by STRAVA_CONFIG), apply environment
 * variable overrides and fill in defaults.
 */
function strava_load_config(): array
{
    $defaults = [
        'client_id'         => '',
        'client_secret'     => '',
        'scope'             => 'read,activity:read_all,profile:read_all',
        'token_file'        => __DIR__ . '/data/tokens.json',
        'cache_file'        => __DIR__ . '/data/cache.json',
        'cache_ttl'         => 300,
        'activity_types'    => ['Ride', 'VirtualRide', 'MountainBikeRide', 'GravelRide', 'EBikeRide', 'EMountainBikeRide'],
        'fetch_count'       => 30,
        'recent_count'      => 6,
        'week_starts_on'    => 'monday',
        'timezone'          => '',
        'athlete_fields'    => ['id', 'firstname', 'lastname', 'city', 'state', 'country', 'profile', 'url'],
        'stats_fields'      => ['recent_ride_totals', 'ytd_ride_totals', 'all_ride_totals', 'biggest_ride_distance', 'biggest_climb_elevation_gain'],
        'allowed_origins'   => [],
        'browser_cache_ttl' => 120,
        'debug'             => false,
    ];

    $file = getenv('STRAVA_CONFIG') ?: __DIR__ . '/config.php';
    $cfg = [];
    if (is_file($file)) {
        $loaded = require $file;
        if (!is_array($loaded)) {
            throw new StravaException('Config file must return an array: ' . $file, 500);
        }
        $cfg = $loaded;
    }
    $cfg = array_merge($defaults, $cfg);

    $lists = ['activity_types', 'athlete_fields', 'stats_fields', 'allowed_origins'];
    $ints  = ['cache_ttl', 'fetch_count', 'recent_count', 'browser_cache_ttl'];
    foreach (array_keys($defaults) as $key) {
        $env = getenv('STRAVA_' . strtoupper($key));
        if ($env === false || $env === '') continue;
        if (in_array($key, $lists, true)) {
            $cfg[$key] = array_values(array_filter(array_map('trim', explode(',', $env)), 'strlen'));
        } elseif ($key === 'debug') {
            $cfg[$key] = filter_var($env, FILTER_VALIDATE_BOOLEAN);
        } else {
            $cfg[$key] = $env;
        }
    }

    foreach ($ints as $key) $cfg[$key] = max(0, (int)$cfg[$key]);
    $cfg['fetch_count']    = min(200, max(1, $cfg['fetch_count']));
    $cfg['week_starts_on'] = strtolower((string)$cfg['week_starts_on']) === 'sunday' ? 'sunday' : 'monday';
    foreach ($lists as $key) $cfg[$key] = array_values((array)$cfg[$key]);
    $cfg['client_id']     = trim((string)$cfg['client_id']);
    $cfg['client_secret'] = trim((string)$cfg['client_secret']);

    return $cfg;
}

// ================================================================ JSON files

function strava_read_json(string $file): ?array
{
    if (!is_readable($file)) return null;
    $fp = fopen($file, 'r');
    if (!$fp) return null;
    flock($fp, LOCK_SH);
    $raw = stream_get_contents($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    $data = json_decode((string)$raw, true);
    return is_array($data) ? $data : null;
}

/**
 * Write JSON atomically (temp file + rename) so a reader never sees a
 * half-written file.
 */
function strava_write_json(string $file, array $data): void
{
    $dir = dirname($file);
    if (!is_dir($dir) && !@mkdir($dir, 0750, true)) {
        throw new StravaException('Cannot create directory: ' . $dir, 500);
    }
    // tempnam() creates the file with 0600 permissions, which the rename
    // keeps: only the owner (the PHP user) can read the tokens.
    $tmp = tempnam($dir, '.tmp');
    if ($tmp === false
        || file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) === false
        || !rename($tmp, $file)) {
        if ($tmp !== false) @unlink($tmp);
        throw new StravaException('Cannot write file: ' . $file, 500);
    }
}

// ======================================================================= HTTP

/**
 * Make an HTTP request. Returns [status, decoded JSON body or null].
 */
function strava_http(string $method, string $url, array $headers = [], ?array $form = null): array
{
    if (!function_exists('curl_init')) {
        throw new StravaException('The PHP curl extension is required.', 500);
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => array_merge(['Accept: application/json'], $headers),
        CURLOPT_USERAGENT      => 'strava-stats/' . STRAVA_STATS_VERSION,
    ]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($form ?? []));
    }
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        throw new StravaException('Could not reach Strava: ' . $err, 502);
    }
    $data = json_decode((string)$body, true);
    return [$status, is_array($data) ? $data : null];
}

/**
 * Turn a Strava error response into a readable message.
 */
function strava_error_message(int $status, ?array $body): string
{
    $msg = 'Strava returned HTTP ' . $status;
    if (!empty($body['message'])) $msg .= ': ' . $body['message'];
    if (!empty($body['errors']) && is_array($body['errors'])) {
        $parts = [];
        foreach ($body['errors'] as $e) {
            $parts[] = trim(($e['resource'] ?? '') . ' ' . ($e['field'] ?? '') . ' ' . ($e['code'] ?? ''));
        }
        $msg .= ' (' . implode('; ', array_filter($parts)) . ')';
    }
    return $msg;
}

// ===================================================================== tokens

/**
 * Exchange an authorization code (from the OAuth redirect) for tokens and
 * save them. Returns the token response, which includes the athlete.
 */
function strava_exchange_code(array $cfg, string $code): array
{
    [$status, $data] = strava_http('POST', STRAVA_OAUTH_BASE . '/token', [], [
        'client_id'     => $cfg['client_id'],
        'client_secret' => $cfg['client_secret'],
        'code'          => $code,
        'grant_type'    => 'authorization_code',
    ]);
    if ($status < 200 || $status >= 300 || empty($data['access_token'])) {
        throw new StravaException(strava_error_message($status, $data));
    }
    strava_save_tokens($cfg, $data);
    return $data;
}

function strava_save_tokens(array $cfg, array $data, array $previous = []): void
{
    strava_write_json($cfg['token_file'], [
        'access_token'  => (string)$data['access_token'],
        // Strava may rotate the refresh token; keep the old one if it doesn't.
        'refresh_token' => (string)($data['refresh_token'] ?? ($previous['refresh_token'] ?? '')),
        'expires_at'    => isset($data['expires_at']) ? (int)$data['expires_at'] : time() + 21600,
        'athlete_id'    => $data['athlete']['id'] ?? ($previous['athlete_id'] ?? null),
        'updated_at'    => gmdate(DATE_ATOM),
    ]);
}

/**
 * Return a valid access token, refreshing it if it has expired (or if
 * $force is set, e.g. after Strava rejected the current one).
 */
function strava_access_token(array $cfg, bool $force = false): string
{
    if ($cfg['client_id'] === '' || $cfg['client_secret'] === '') {
        throw new StravaException('Strava client_id and client_secret are not configured.', 503);
    }
    $tokens = strava_read_json($cfg['token_file']);
    if (empty($tokens['refresh_token'])) {
        throw new StravaException('No Strava account connected. Run: php connect.php', 503);
    }
    if (!$force && !empty($tokens['access_token']) && time() < (int)($tokens['expires_at'] ?? 0) - 60) {
        return (string)$tokens['access_token'];
    }

    [$status, $data] = strava_http('POST', STRAVA_OAUTH_BASE . '/token', [], [
        'client_id'     => $cfg['client_id'],
        'client_secret' => $cfg['client_secret'],
        'refresh_token' => $tokens['refresh_token'],
        'grant_type'    => 'refresh_token',
    ]);
    if ($status < 200 || $status >= 300 || empty($data['access_token'])) {
        $hint = ($status === 400 || $status === 401)
            ? ' The connection may have been revoked; run php connect.php again.' : '';
        throw new StravaException('Could not refresh the Strava token. ' . strava_error_message($status, $data) . '.' . $hint);
    }
    strava_save_tokens($cfg, $data, $tokens);
    return (string)$data['access_token'];
}

/**
 * GET a Strava API endpoint. Retries once with a fresh token on 401.
 */
function strava_get(array $cfg, string $endpoint, array $query = []): array
{
    $url = STRAVA_API_BASE . $endpoint . ($query ? '?' . http_build_query($query) : '');
    $token = strava_access_token($cfg);
    [$status, $data] = strava_http('GET', $url, ['Authorization: Bearer ' . $token]);
    if ($status === 401) {
        $token = strava_access_token($cfg, true);
        [$status, $data] = strava_http('GET', $url, ['Authorization: Bearer ' . $token]);
    }
    if ($status === 429) {
        throw new StravaException('Strava rate limit reached. Try again later.', 503);
    }
    if ($status < 200 || $status >= 300 || $data === null) {
        throw new StravaException(strava_error_message($status, $data));
    }
    return $data;
}

// ==================================================================== payload

/**
 * Fetch everything from Strava and build the public summary.
 */
function strava_build_payload(array $cfg): array
{
    $athlete    = strava_get($cfg, '/athlete');
    $stats      = strava_get($cfg, '/athletes/' . (int)$athlete['id'] . '/stats');
    $activities = strava_get($cfg, '/athlete/activities', ['per_page' => $cfg['fetch_count'], 'page' => 1]);

    $matching = [];
    foreach ($activities as $a) {
        if (strava_activity_matches($cfg, $a)) $matching[] = $a;
    }

    $tz = strava_timezone($cfg, $activities);
    $now = new DateTimeImmutable('now', $tz);
    $weekStart = $now->modify(($cfg['week_starts_on'] === 'sunday' ? 'last sunday' : 'monday this week'))->setTime(0, 0, 0);
    if ($cfg['week_starts_on'] === 'sunday' && $now->format('w') === '0') {
        $weekStart = $now->setTime(0, 0, 0);
    }
    $sevenStart = $now->modify('-7 days');

    $week  = strava_totals($matching, $weekStart, $now);
    $seven = strava_totals($matching, $sevenStart, $now);
    $week['start']  = $weekStart->format(DATE_ATOM);
    $seven['start'] = $sevenStart->format(DATE_ATOM);

    $recent = [];
    foreach (array_slice($matching, 0, $cfg['recent_count']) as $a) {
        $recent[] = [
            'id'                   => $a['id'] ?? null,
            'name'                 => $a['name'] ?? '',
            'type'                 => $a['type'] ?? '',
            'sport_type'           => $a['sport_type'] ?? ($a['type'] ?? ''),
            'start_date'           => $a['start_date'] ?? null,
            'start_date_local'     => $a['start_date_local'] ?? null,
            'distance'             => (float)($a['distance'] ?? 0),
            'moving_time'          => (int)($a['moving_time'] ?? 0),
            'elapsed_time'         => (int)($a['elapsed_time'] ?? 0),
            'total_elevation_gain' => (float)($a['total_elevation_gain'] ?? 0),
            'average_speed'        => (float)($a['average_speed'] ?? 0),
            'max_speed'            => (float)($a['max_speed'] ?? 0),
            'average_watts'        => $a['average_watts'] ?? null,
            'kudos_count'          => (int)($a['kudos_count'] ?? 0),
            'url'                  => isset($a['id']) ? 'https://www.strava.com/activities/' . $a['id'] : null,
        ];
    }

    return [
        'athlete'      => strava_public_athlete($cfg, $athlete),
        'stats'        => array_intersect_key($stats, array_flip($cfg['stats_fields'])),
        'week'         => $week,
        'seven'        => $seven,
        'recent'       => $recent,
        'units'        => ['distance' => 'meters', 'elevation' => 'meters', 'time' => 'seconds', 'speed' => 'meters/second', 'weight' => 'kilograms'],
        'generated_at' => gmdate(DATE_ATOM),
    ];
}

function strava_activity_matches(array $cfg, array $a): bool
{
    if (!$cfg['activity_types'] || in_array('*', $cfg['activity_types'], true)) return true;
    $type = $a['sport_type'] ?? ($a['type'] ?? '');
    return in_array($type, $cfg['activity_types'], true);
}

/**
 * Sum count, distance, elevation and moving time for activities that
 * started within [$from, $to].
 */
function strava_totals(array $activities, DateTimeInterface $from, DateTimeInterface $to): array
{
    $t = ['count' => 0, 'distance' => 0.0, 'elevation_gain' => 0.0, 'moving_time' => 0];
    foreach ($activities as $a) {
        if (empty($a['start_date'])) continue;
        try { $start = new DateTimeImmutable($a['start_date']); } catch (Exception $e) { continue; }
        if ($start < $from || $start > $to) continue;
        $t['count']++;
        $t['distance']       += (float)($a['distance'] ?? 0);
        $t['elevation_gain'] += (float)($a['total_elevation_gain'] ?? 0);
        $t['moving_time']    += (int)($a['moving_time'] ?? 0);
    }
    return $t;
}

/**
 * The configured timezone, else the one on the latest activity (Strava
 * formats it like "(GMT-05:00) America/New_York"), else UTC.
 */
function strava_timezone(array $cfg, array $activities): DateTimeZone
{
    $candidates = [(string)$cfg['timezone']];
    if (!empty($activities[0]['timezone'])) {
        $candidates[] = preg_replace('/^\(.*?\)\s*/', '', (string)$activities[0]['timezone']);
    }
    foreach ($candidates as $name) {
        if ($name === '') continue;
        try { return new DateTimeZone($name); } catch (Exception $e) { /* try next */ }
    }
    return new DateTimeZone('UTC');
}

/**
 * Only the athlete fields the config allows - the rest of the profile
 * (bikes, shoes, clubs, etc.) never leaves the server.
 */
function strava_public_athlete(array $cfg, array $athlete): array
{
    $all = [
        'id'            => $athlete['id'] ?? null,
        'firstname'     => $athlete['firstname'] ?? '',
        'lastname'      => $athlete['lastname'] ?? '',
        'username'      => $athlete['username'] ?? null,
        'city'          => $athlete['city'] ?? '',
        'state'         => $athlete['state'] ?? '',
        'country'       => $athlete['country'] ?? '',
        'sex'           => $athlete['sex'] ?? null,
        'profile'       => $athlete['profile_medium'] ?? ($athlete['profile'] ?? ''),
        'profile_large' => $athlete['profile'] ?? '',
        'premium'       => $athlete['premium'] ?? ($athlete['summit'] ?? null),
        'created_at'    => $athlete['created_at'] ?? null,
        'ftp'           => $athlete['ftp'] ?? null,
        'weight'        => $athlete['weight'] ?? null,
        'url'           => isset($athlete['id']) ? 'https://www.strava.com/athletes/' . $athlete['id'] : null,
    ];
    $out = [];
    foreach ($cfg['athlete_fields'] as $field) {
        if (array_key_exists($field, $all)) $out[$field] = $all[$field];
    }
    return $out;
}

// ====================================================================== cache

/**
 * Return the cached payload if fresh, otherwise rebuild it. A lock file
 * makes sure only one request at a time talks to Strava; the others wait
 * and then reuse its result. If Strava fails and a stale copy exists, the
 * stale copy is served with "stale": true rather than an error.
 */
function strava_get_payload(array $cfg, bool $force = false): array
{
    $ttl = $cfg['cache_ttl'];
    if ($ttl === 0 && !$force) return strava_build_payload($cfg);

    $cached = strava_read_json($cfg['cache_file']);
    if (!$force && $cached && time() - (int)($cached['cached_at'] ?? 0) < $ttl) {
        return $cached['payload'];
    }

    $lockFile = $cfg['cache_file'] . '.lock';
    $lock = @fopen($lockFile, 'c');
    if ($lock) flock($lock, LOCK_EX);
    try {
        // Another request may have refreshed the cache while we waited.
        $cached = strava_read_json($cfg['cache_file']);
        if (!$force && $cached && time() - (int)($cached['cached_at'] ?? 0) < $ttl) {
            return $cached['payload'];
        }
        try {
            $payload = strava_build_payload($cfg);
        } catch (StravaException $e) {
            if ($cached && isset($cached['payload'])) {
                $stale = $cached['payload'];
                $stale['stale'] = true;
                return $stale;
            }
            throw $e;
        }
        strava_write_json($cfg['cache_file'], ['cached_at' => time(), 'payload' => $payload]);
        return $payload;
    } finally {
        if ($lock) { flock($lock, LOCK_UN); fclose($lock); }
    }
}
