<?php
/**
 * Strava Stats configuration.
 *
 * Easiest: open setup.php in your browser and it writes config.php for you.
 *
 * Or copy this file to config.php and fill in your values:
 *
 *     cp config.example.php config.php
 *
 * Any setting can also be supplied as an environment variable (shown next to
 * each option). Environment variables win over values in this file, which is
 * handy for Docker or for keeping secrets out of files entirely.
 */

return [

    // ------------------------------------------------------------------ API app
    // Create an API application at https://www.strava.com/settings/api
    // and copy the Client ID and Client Secret here.

    'client_id'     => '',   // env: STRAVA_CLIENT_ID
    'client_secret' => '',   // env: STRAVA_CLIENT_SECRET

    // OAuth scopes requested by connect.php.
    //   read               - public profile
    //   activity:read_all  - all activities, including "Only You" ones
    //                        (use activity:read for public/followers only)
    //   profile:read_all   - needed for FTP and weight
    'scope' => 'read,activity:read_all,profile:read_all',   // env: STRAVA_SCOPE

    // -------------------------------------------------------------- Storage
    // Where the OAuth tokens and the response cache are written. The web
    // server user must be able to write to these locations.
    //
    // IMPORTANT: the tokens file grants access to your Strava account.
    // Keep it out of the web root if you can. If it has to live inside the
    // web root, data/.htaccess blocks it on Apache; on nginx and others you
    // must block it yourself (see README).

    'token_file' => __DIR__ . '/data/tokens.json',   // env: STRAVA_TOKEN_FILE
    'cache_file' => __DIR__ . '/data/cache.json',    // env: STRAVA_CACHE_FILE

    // How long (seconds) a response is reused before Strava is asked again.
    // Every refresh costs 3 API requests. Strava's default read limit is
    // 100 requests per 15 minutes and 1,000 per day, so 300 (5 minutes) uses
    // well under half the daily budget. Set to 0 to disable caching (not
    // recommended on a public site).
    'cache_ttl' => 300,   // env: STRAVA_CACHE_TTL

    // ------------------------------------------------------------ Activities
    // Which activities count. Matched against each activity's sport_type
    // (e.g. GravelRide), falling back to the older "type" field. Use ['*']
    // (or leave the array empty) to include every activity type.
    // Full list: https://developers.strava.com/docs/reference/#api-models-SportType
    'activity_types' => [   // env: STRAVA_ACTIVITY_TYPES (comma separated)
        'Ride',
        'VirtualRide',
        'MountainBikeRide',
        'GravelRide',
        'EBikeRide',
        'EMountainBikeRide',
    ],

    // How many recent activities to fetch (1-200). These feed both the
    // "recent" list and the weekly totals, so raise this if you log more
    // than this many activities (of any type) in a week.
    'fetch_count' => 30,   // env: STRAVA_FETCH_COUNT

    // How many matching activities to return in "recent".
    'recent_count' => 6,   // env: STRAVA_RECENT_COUNT

    // ----------------------------------------------------------------- Units
    // How distances and climbing are shown on your site: 'metric' (km and m)
    // or 'imperial' (miles and feet). The numbers in the feed are always
    // metric; this tells example.html and the setup.php snippet which units
    // to display, so you can switch later without changing your page.
    'display_units' => 'metric',   // env: STRAVA_DISPLAY_UNITS

    // ----------------------------------------------------------------- Weeks
    // First day of the week for the "week" totals: 'monday' or 'sunday'.
    'week_starts_on' => 'monday',   // env: STRAVA_WEEK_STARTS_ON

    // Timezone used to decide when the week starts, e.g. 'America/New_York'.
    // Leave empty to use the timezone of your most recent activity, then UTC.
    'timezone' => '',   // env: STRAVA_TIMEZONE

    // --------------------------------------------------------------- Output
    // Athlete fields included in the response. Anything not listed stays
    // private. Available: id, firstname, lastname, username, city, state,
    // country, sex, profile, profile_large, premium, created_at, ftp,
    // weight, url. "ftp" and "weight" need the profile:read_all scope.
    'athlete_fields' => [   // env: STRAVA_ATHLETE_FIELDS (comma separated)
        'id', 'firstname', 'lastname', 'city', 'state', 'country', 'profile', 'url',
    ],

    // Totals from Strava's /athletes/{id}/stats endpoint to include.
    // Available: recent_ride_totals, ytd_ride_totals, all_ride_totals,
    // recent_run_totals, ytd_run_totals, all_run_totals, recent_swim_totals,
    // ytd_swim_totals, all_swim_totals, biggest_ride_distance,
    // biggest_climb_elevation_gain.
    // Note: "recent_*" totals cover the last 4 weeks, not the current week.
    'stats_fields' => [   // env: STRAVA_STATS_FIELDS (comma separated)
        'recent_ride_totals', 'ytd_ride_totals', 'all_ride_totals',
        'biggest_ride_distance', 'biggest_climb_elevation_gain',
    ],

    // ----------------------------------------------------------------- CORS
    // Origins allowed to call strava.php from a browser on another domain,
    // e.g. ['https://example.com']. Use ['*'] to allow any site. Leave empty
    // if the page calling it is on the same domain.
    'allowed_origins' => [],   // env: STRAVA_ALLOWED_ORIGINS (comma separated)

    // Seconds browsers and CDNs may cache the response.
    'browser_cache_ttl' => 120,   // env: STRAVA_BROWSER_CACHE_TTL

    // Show detailed error messages in responses. Leave off in production.
    'debug' => false,   // env: STRAVA_DEBUG
];
