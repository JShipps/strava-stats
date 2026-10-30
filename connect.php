<?php
/**
 * Strava Stats - one-time account connection.
 *
 * Run from a terminal:   php connect.php
 *
 * Prints a Strava authorization link. Open it, click Authorize, then copy the
 * address your browser lands on (it will fail to load - that's expected) and
 * paste it back here. The tokens are saved to the configured token_file and
 * refreshed automatically from then on.
 *
 * Prefer a browser? Open setup.php instead - it does all of this for you.
 */

require_once __DIR__ . '/lib.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

function out(string $s = ''): void { fwrite(STDOUT, $s . PHP_EOL); }
function fail(string $s): void { fwrite(STDERR, 'Error: ' . $s . PHP_EOL); exit(1); }

try {
    $cfg = strava_load_config();
} catch (StravaException $e) {
    fail($e->getMessage());
}

if ($cfg['client_id'] === '' || $cfg['client_secret'] === '') {
    fail("client_id and client_secret are not set.\n"
       . "Copy config.example.php to config.php and fill them in, set\n"
       . "STRAVA_CLIENT_ID and STRAVA_CLIENT_SECRET, or open setup.php in a browser.");
}

// "localhost" is always accepted by Strava as a redirect, whatever the app's
// Authorization Callback Domain is set to, so no web server is needed.
$redirect = 'http://localhost/exchange_token';
$state = bin2hex(random_bytes(16));
$authUrl = STRAVA_OAUTH_BASE . '/authorize?' . http_build_query([
    'client_id'       => $cfg['client_id'],
    'response_type'   => 'code',
    'redirect_uri'    => $redirect,
    'approval_prompt' => 'force',
    'scope'           => $cfg['scope'],
    'state'           => $state,
]);

out('1. Open this link in your browser and click "Authorize":');
out();
out('   ' . $authUrl);
out();
out('2. Your browser will go to a localhost page that does not load.');
out('   Copy the full address from the address bar and paste it below.');
out();
fwrite(STDOUT, 'Address (or just the code): ');
$input = trim((string)fgets(STDIN));
if ($input === '') fail('Nothing entered.');

$code = $input;
$granted = null;
if (strpos($input, '?') !== false || strpos($input, '://') !== false) {
    parse_str((string)parse_url($input, PHP_URL_QUERY), $params);
    if (!empty($params['error'])) fail('Strava says: ' . $params['error']);
    if (!isset($params['state']) || !hash_equals($state, (string)$params['state'])) {
        fail('That address is from a different authorization attempt. Run connect.php again and use the new link.');
    }
    $code = (string)($params['code'] ?? '');
    $granted = isset($params['scope']) ? explode(',', (string)$params['scope']) : null;
}
if ($code === '' || !preg_match('/^[A-Za-z0-9]+$/', $code)) fail('Could not find an authorization code in that input.');

try {
    $data = strava_exchange_code($cfg, $code, $granted !== null ? implode(',', $granted) : null);
} catch (StravaException $e) {
    fail($e->getMessage());
}

$name = trim(($data['athlete']['firstname'] ?? '') . ' ' . ($data['athlete']['lastname'] ?? ''));
out();
out('Connected' . ($name !== '' ? ' as ' . $name : '') . '. Tokens saved to ' . $cfg['token_file']);

// Strava lets the athlete untick scopes on the consent screen.
if ($granted !== null) {
    $missing = array_diff(explode(',', $cfg['scope']), $granted);
    if ($missing) {
        out();
        out('Warning: these scopes were not granted: ' . implode(', ', $missing));
        out('Some data (e.g. private activities, FTP, weight) may be missing.');
    }
}

// Stale data from a previous account shouldn't linger.
@unlink($cfg['cache_file']);

out();
out('Test it with:  php strava.php --refresh');
