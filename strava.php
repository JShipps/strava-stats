<?php
/**
 * Strava Stats - JSON endpoint.
 *
 * Over HTTP:   GET /path/to/strava.php        -> JSON summary
 * From a shell: php strava.php [--refresh]    -> same JSON, printed
 *
 * --refresh skips the cache and fetches fresh data from Strava.
 */

require_once __DIR__ . '/lib.php';

$cli = PHP_SAPI === 'cli';

try {
    $cfg = strava_load_config();
} catch (StravaException $e) {
    strava_respond(['error' => $e->getMessage()], $e->status, ['debug' => $cli]);
}

if (!$cli) {
    strava_send_cors($cfg);
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
    if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) {
        strava_respond(['error' => 'Method not allowed.'], 405, $cfg);
    }
}

try {
    $force = $cli && in_array('--refresh', $argv, true);
    $payload = strava_get_payload($cfg, $force);
    strava_respond($payload, 200, $cfg);
} catch (StravaException $e) {
    if (!$cli) error_log('strava-stats: ' . $e->getMessage());
    $message = ($cli || $cfg['debug']) ? $e->getMessage() : 'Strava data is unavailable right now.';
    strava_respond(['error' => $message], $e->status, $cfg);
} catch (Throwable $e) {
    if (!$cli) error_log('strava-stats: ' . $e);
    $message = ($cli || $cfg['debug']) ? $e->getMessage() : 'Internal error.';
    strava_respond(['error' => $message], 500, $cfg);
}

// ------------------------------------------------------------------ helpers

function strava_respond(array $body, int $status, array $cfg): void
{
    $json = json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (PHP_SAPI === 'cli') {
        fwrite($status >= 400 ? STDERR : STDOUT, $json . PHP_EOL);
        exit($status >= 400 ? 1 : 0);
    }
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    $ttl = (int)($cfg['browser_cache_ttl'] ?? 0);
    header($status === 200 && $ttl > 0 ? 'Cache-Control: public, max-age=' . $ttl : 'Cache-Control: no-store');
    echo $json;
    exit;
}

function strava_send_cors(array $cfg): void
{
    $allowed = $cfg['allowed_origins'];
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if (in_array('*', $allowed, true)) {
        header('Access-Control-Allow-Origin: *');
    } elseif ($origin !== '' && in_array($origin, $allowed, true)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
    } else {
        return;
    }
    header('Access-Control-Allow-Methods: GET, OPTIONS');
    header('Access-Control-Max-Age: 86400');
}
