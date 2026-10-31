# Strava Stats: advanced guide

The [README](../README.md) covers everyday setup with the browser installer. This guide is for developers and anyone who wants to set Strava Stats up by hand, change settings in `config.php`, read the JSON format, or lock down their server.

## Contents

- [Requirements](#requirements)
- [Setting up from a terminal](#setting-up-from-a-terminal)
- [The web installer](#the-web-installer)
- [Configuration](#configuration)
- [The JSON response](#the-json-response)
- [Using it on your site](#using-it-on-your-site)
- [Securing your tokens](#securing-your-tokens)
- [Rate limits and caching](#rate-limits-and-caching)
- [Troubleshooting](#troubleshooting)
- [FAQ](#faq)
- [Files](#files)

---

## Requirements

- PHP **7.4 or newer** with the `curl` and `json` extensions (both standard on almost every host)
- A web server that runs PHP (Apache, nginx with PHP-FPM, LiteSpeed, Caddy, …)
- A folder the web server can write to (for `config.php` and `data/`)
- A Strava account

## Setting up from a terminal

Prefer the command line, or can't make the folder writable? The original route still works.

1. Create a Strava API app at <https://www.strava.com/settings/api> and note the **Client ID** and **Client Secret**. The Authorization Callback Domain doesn't matter for this route.
2. Configure:

   ```bash
   git clone https://github.com/JShipps/strava-stats.git
   cd strava-stats
   cp config.example.php config.php   # then paste in client_id and client_secret
   ```

3. Connect (one time):

   ```bash
   php connect.php
   ```

   Open the printed link and click **Authorize**. Your browser goes to `http://localhost/exchange_token?...` and shows an error page, **which is expected**. Copy the whole address and paste it into the terminal.

4. Test: `php strava.php --refresh`
5. If you set it up on another machine, upload the folder **including `data/tokens.json`**, and make sure `data/` is writable on the server.

## The web installer

- **Coming back later.** Open `setup.php` again and log in with your installer password to change options, reconnect, or switch accounts. Everything is saved to `config.php`, which you can still edit by hand.
- **Existing installs.** If `config.php` already has a Client Secret when you first open `setup.php`, you'll be asked for that secret before you can set a password. That way nobody else can take over an install you set up by hand.
- **Forgot the password?** Delete the `setup_password_hash` line from `config.php` and open `setup.php`. You'll be asked for your Client Secret, then for a new password.
- **Locking.** **Lock installer** sets `'setup_locked' => true` in `config.php`, and `setup.php` then returns 404. Set it back to `false` to unlock. Deleting `setup.php` works too.
- **Changing permissions.** If you tick "Include Only You activities" or "Allow access to FTP and weight" after connecting, the installer asks you to reconnect so Strava can show you the new permissions.
- **Environment variables** still win over `config.php`. The installer points out any setting that is overridden this way.
- **Callback domain.** The installer redirects back to itself, so your Strava app's *Authorization Callback Domain* must match your site's domain (e.g. `example.com`). If it doesn't, Strava shows `redirect_uri invalid`. `localhost` is always allowed.

---

## Configuration

All settings are in `config.php`. Each one can also be set with an environment variable, and environment variables take priority. For list settings, separate the values in the environment variable with commas.

| Setting | Env variable | Default | Description |
|---|---|---|---|
| `client_id` | `STRAVA_CLIENT_ID` | — | **Required.** From strava.com/settings/api. |
| `client_secret` | `STRAVA_CLIENT_SECRET` | — | **Required.** Keep this private. |
| `scope` | `STRAVA_SCOPE` | `read,activity:read_all,profile:read_all` | Permissions requested by `connect.php`. See [Scopes](#scopes). |
| `token_file` | `STRAVA_TOKEN_FILE` | `data/tokens.json` | Where OAuth tokens are stored. Must be writable. |
| `cache_file` | `STRAVA_CACHE_FILE` | `data/cache.json` | Where the cached response is stored. Must be writable. |
| `cache_ttl` | `STRAVA_CACHE_TTL` | `300` | Seconds to reuse a response before asking Strava again. `0` disables caching. |
| `activity_types` | `STRAVA_ACTIVITY_TYPES` | All cycling types | Which [sport types](https://developers.strava.com/docs/reference/#api-models-SportType) count. `['*']` means all. |
| `fetch_count` | `STRAVA_FETCH_COUNT` | `30` | Recent activities fetched (1–200). They feed the weekly totals and the `recent` list. |
| `recent_count` | `STRAVA_RECENT_COUNT` | `6` | Matching activities returned in `recent`. |
| `display_units` | `STRAVA_DISPLAY_UNITS` | `metric` | `metric` (km, m) or `imperial` (mi, ft). Only tells pages how to *display* values; the numbers in the feed are always metric. Returned as `display_units`. |
| `week_starts_on` | `STRAVA_WEEK_STARTS_ON` | `monday` | `monday` or `sunday`. |
| `timezone` | `STRAVA_TIMEZONE` | *auto* | Sets when "this week" starts, e.g. `Europe/London`. If empty, uses your latest activity's timezone, then UTC. |
| `athlete_fields` | `STRAVA_ATHLETE_FIELDS` | `id, firstname, lastname, city, state, country, profile, url` | Profile fields to publish. See [The JSON response](#athlete). |
| `stats_fields` | `STRAVA_STATS_FIELDS` | Ride totals + biggest ride/climb | Totals to publish from Strava's stats endpoint. |
| `allowed_origins` | `STRAVA_ALLOWED_ORIGINS` | *none* | Sites allowed to call the endpoint from the browser (CORS). See [Calling it from another domain](#calling-it-from-another-domain). |
| `browser_cache_ttl` | `STRAVA_BROWSER_CACHE_TTL` | `120` | `Cache-Control: max-age` sent to browsers and CDNs. |
| `debug` | `STRAVA_DEBUG` | `false` | Include detailed error messages in HTTP responses. |

You can keep `config.php` somewhere else by setting `STRAVA_CONFIG=/path/to/config.php`.

### Examples

**Runner instead of cyclist:**

```php
'activity_types' => ['Run', 'TrailRun', 'VirtualRun'],
'stats_fields'   => ['recent_run_totals', 'ytd_run_totals', 'all_run_totals'],
```

**Every activity, weeks starting Sunday, US Eastern time:**

```php
'activity_types' => ['*'],
'week_starts_on' => 'sunday',
'timezone'       => 'America/New_York',
```

**Show FTP and weight** (needs the `profile:read_all` scope):

```php
'athlete_fields' => ['id', 'firstname', 'lastname', 'profile', 'url', 'ftp', 'weight'],
```

**Configuration with environment variables only** (Docker, or a host control panel):

```bash
STRAVA_CLIENT_ID=12345
STRAVA_CLIENT_SECRET=abcdef...
STRAVA_TOKEN_FILE=/var/lib/strava-stats/tokens.json
STRAVA_CACHE_FILE=/var/lib/strava-stats/cache.json
STRAVA_ACTIVITY_TYPES=Ride,GravelRide,VirtualRide
```

### Scopes

| Scope | What it allows |
|---|---|
| `read` | Public profile information. Always needed. |
| `activity:read` | Activities visible to Everyone or Followers. |
| `activity:read_all` | All activities, **including "Only You" ones.** These count towards your totals and can appear in `recent`. |
| `profile:read_all` | Full profile, including FTP and weight. |

If you don't want private activities included, use `read,activity:read` and run `php connect.php` again.

---

## The JSON response

`GET strava.php` returns this. All values are in **metric units**: meters, seconds, meters per second, kilograms.

```json
{
  "athlete": {
    "id": 1234567,
    "firstname": "Jane",
    "lastname": "Rider",
    "city": "Asheville",
    "state": "North Carolina",
    "country": "United States",
    "profile": "https://dgalywyr863hv.cloudfront.net/.../medium.jpg",
    "url": "https://www.strava.com/athletes/1234567"
  },
  "stats": {
    "recent_ride_totals": { "count": 12, "distance": 482113.2, "moving_time": 63021, "elapsed_time": 70122, "elevation_gain": 6210, "achievement_count": 31 },
    "ytd_ride_totals":    { "count": 160, "distance": 6120334, "moving_time": 801234, "elapsed_time": 901223, "elevation_gain": 78012 },
    "all_ride_totals":    { "count": 1450, "distance": 52340112, "moving_time": 7012345, "elapsed_time": 8012345, "elevation_gain": 640123 },
    "biggest_ride_distance": 241032.1,
    "biggest_climb_elevation_gain": 1420.3
  },
  "week": {
    "count": 3,
    "distance": 112403.5,
    "elevation_gain": 1320,
    "moving_time": 14820,
    "start": "2026-10-26T00:00:00-04:00"
  },
  "seven": {
    "count": 4,
    "distance": 151220.1,
    "elevation_gain": 1802,
    "moving_time": 19930,
    "start": "2026-10-22T09:41:12-04:00"
  },
  "recent": [
    {
      "id": 12345678901,
      "name": "Morning gravel loop",
      "type": "Ride",
      "sport_type": "GravelRide",
      "start_date": "2026-10-29T12:03:44Z",
      "start_date_local": "2026-10-29T08:03:44Z",
      "distance": 48211.4,
      "moving_time": 6120,
      "elapsed_time": 6930,
      "total_elevation_gain": 612,
      "average_speed": 7.878,
      "max_speed": 16.2,
      "average_watts": 182.4,
      "kudos_count": 14,
      "url": "https://www.strava.com/activities/12345678901"
    }
  ],
  "units": { "distance": "meters", "elevation": "meters", "time": "seconds", "speed": "meters/second", "weight": "kilograms" },
  "display_units": "imperial",
  "generated_at": "2026-10-29T13:10:02+00:00"
}
```

| Key | Meaning |
|---|---|
| `athlete` | Profile fields listed in `athlete_fields`. |
| `stats` | Totals listed in `stats_fields`, exactly as Strava returns them. **Note:** `recent_*_totals` cover the **last 4 weeks**, not the current week. Use `week` for that. |
| `week` | Totals for matching activities in the current calendar week (from `week_starts_on`, midnight, in your timezone). |
| `seven` | Totals for matching activities in the last 7 × 24 hours. |
| `recent` | The latest `recent_count` matching activities, newest first. |
| `units` | The units of every number in the response. Always metric. |
| `display_units` | `metric` or `imperial`: how the site owner wants values shown (the `display_units` setting). Convert with the formulas below. |
| `stale` | Only present (as `true`) when Strava couldn't be reached and the last good response is being served. |
| `error` | Only present on failure, with a non-200 HTTP status. |

<a id="athlete"></a>**Available `athlete_fields`:** `id`, `firstname`, `lastname`, `username`, `city`, `state`, `country`, `sex`, `profile` (medium photo), `profile_large`, `premium`, `created_at`, `ftp`, `weight`, `url`.

**Available `stats_fields`:** `recent_ride_totals`, `ytd_ride_totals`, `all_ride_totals`, `recent_run_totals`, `ytd_run_totals`, `all_run_totals`, `recent_swim_totals`, `ytd_swim_totals`, `all_swim_totals`, `biggest_ride_distance`, `biggest_climb_elevation_gain`.

> Strava's stats endpoint only counts activities visible to **Everyone**, whatever scopes you granted. If your year-to-date numbers look low, check your activities' privacy settings.

---

## Using it on your site

### Plain JavaScript

```html
<div id="strava"></div>
<script>
fetch('/strava-stats/strava.php')
  .then(r => r.json())
  .then(data => {
    if (data.error) throw new Error(data.error);
    const km = (data.week.distance / 1000).toFixed(1); // or check data.display_units
    document.getElementById('strava').textContent =
      `${data.athlete.firstname} has ridden ${km} km in ${data.week.count} rides this week.`;
  })
  .catch(err => console.error('Strava stats:', err));
</script>
```

`example.html` has a fuller widget with a profile, weekly tiles, a recent activity list and auto-refresh. It shows kilometres or miles according to `display_units`.

### Converting units

```js
const km    = m => m / 1000;
const miles = m => m / 1609.344;
const feet  = m => m * 3.28084;
const kmh   = mps => mps * 3.6;
const mph   = mps => mps * 2.236936;
const hours = s => s / 3600;
```

### From PHP on the same server

You can use the library directly instead of making an HTTP request:

```php
require '/path/to/strava-stats/lib.php';
$data = strava_get_payload(strava_load_config());
echo number_format($data['week']['distance'] / 1000, 1) . ' km this week';
```

### Calling it from another domain

If your page and `strava.php` are on different domains (say the endpoint is at `api.example.com` and the page at `example.com`), allow the page's origin:

```php
'allowed_origins' => ['https://example.com', 'https://www.example.com'],
```

`['*']` allows any site. That's harmless because the data is public, but anyone could then embed your stats.

### Updating from cron (optional)

You can keep the cache warm so visitors never wait on Strava:

```cron
*/5 * * * * cd /var/www/strava-stats && php strava.php --refresh > /dev/null
```

Run cron as the same user as the web server so both can write to `data/`.

---

## Securing your tokens

`data/tokens.json` contains a refresh token, which **gives read access to your Strava account**. Make sure nobody can download it.

**Best option:** keep it outside the web root:

```php
'token_file' => '/home/me/private/strava-tokens.json',
'cache_file' => '/home/me/private/strava-cache.json',
```

**If it must stay in the web root:**

- **Apache / LiteSpeed:** the included `data/.htaccess` blocks the folder, and the top-level `.htaccess` blocks every PHP file except `strava.php`. Check that `AllowOverride` is enabled (it usually is on shared hosting).
- **nginx:** `.htaccess` files are ignored, so add this to your server block:

  ```nginx
  location ~ ^/strava-stats/(data/|config\.php|config\.example\.php|lib\.php|connect\.php) {
      deny all;
      return 404;
  }
  ```

- **Caddy:**

  ```
  @stravaPrivate path /strava-stats/data/* /strava-stats/config.php /strava-stats/lib.php /strava-stats/connect.php
  respond @stravaPrivate 404
  ```

**Check it.** This should return 403 or 404, not your tokens:

```bash
curl -I https://example.com/strava-stats/data/tokens.json
```

Also:

- `setup.php` checks this for you on its last step.
- Never commit `config.php` or `data/*.json`. The included `.gitignore` already excludes them.
- Token files are created with `0600` permissions, readable only by their owner. If you run `connect.php` as yourself but PHP runs as `www-data`, transfer ownership:
  ```bash
  sudo chown -R www-data: data/
  ```
  Or run setup as the web server user: `sudo -u www-data php connect.php`.
- To revoke access at any time, go to **Strava → Settings → My Apps** and click **Revoke Access**.

---

## Rate limits and caching

By default, Strava allows each app **100 read requests per 15 minutes and 1,000 per day**. Your app's page at strava.com/settings/api shows its actual limits.

Each refresh of the data uses **3 requests**: athlete, stats and activities. Some cache times compared:

| `cache_ttl` | Max requests per day | Share of the daily limit |
|---|---|---|
| `60` (1 min) | 4,320 | ❌ Over the limit |
| `300` (5 min, default) | 864 | ✅ 86% |
| `600` (10 min) | 432 | ✅ 43% |
| `900` (15 min) | 288 | ✅ 29% |

These numbers are the worst case, with constant traffic all day; a quiet site uses far fewer requests. If something else uses the same Strava app, raise `cache_ttl`.

The cache is shared by all visitors, and a file lock means only one request refreshes it at a time. A traffic spike can't multiply your API calls.

---

## Troubleshooting

Run `php strava.php --refresh` from the terminal. It always prints the full error message.

| Message | Cause and fix |
|---|---|
| `client_id and client_secret are not configured` | `config.php` is missing or empty. Open `setup.php`, or run `cp config.example.php config.php` and fill it in. |
| Strava shows `redirect_uri invalid` after clicking Connect | Your Strava app's Authorization Callback Domain doesn't match your site's domain. Edit it at strava.com/settings/api to the domain `setup.php` shows. |
| `setup.php` returns 404 | The installer is locked. Set `'setup_locked' => false` in `config.php`. |
| `No Strava account connected…` | No tokens yet, or `token_file` points to the wrong place. Connect in `setup.php` or run `php connect.php`. |
| `Could not refresh the Strava token…` | Access was revoked, the client secret changed, or the tokens belong to a different app. Reconnect in `setup.php` or run `php connect.php` again. |
| `Cannot write file: …/data/tokens.json` | The web server can't write to `data/`. Fix the ownership or permissions (`chown www-data data` or `chmod 775 data`). |
| `Strava rate limit reached` | Too many requests. Raise `cache_ttl`, and check whether other scripts use the same app. |
| `The PHP curl extension is required` | Install or enable it (`sudo apt install php-curl`, or enable it in your host's control panel). |
| `That address is from a different authorization attempt` | You pasted an address from an older link. Use the link `connect.php` just printed. |
| `Strava says: access_denied` | You clicked Cancel on Strava. Run it again and click Authorize. |
| In the browser: `Strava data is unavailable right now.` | The real reason is in your PHP error log, or set `'debug' => true` temporarily. |
| `ftp`/`weight` are `null` | The `profile:read_all` scope wasn't granted, or the values aren't set in your Strava profile. |
| Weekly totals look too low | You log more than `fetch_count` activities a week (of any type). Raise `fetch_count`. |
| The week starts at the wrong time | Set `timezone` explicitly. |
| You changed settings but nothing changed | You're seeing the cached response. Run `php strava.php --refresh` or delete `data/cache.json`. |

---

## FAQ

### I don't have SSH access to my host

You don't need it. Upload the folder and open `setup.php` in your browser. See the [README](../README.md#step-by-step).

### Can it show more than one athlete?

Each install connects one Strava account. For several athletes, make one copy per athlete, each with its own `token_file` and `cache_file`. They can all share one Strava app. New Strava apps can only authorize **one athlete** (yourself) until Strava approves them for more. You can request that from the app settings page.

### Why do I need `activity:read_all`?

You don't. It lets "Only You" activities count towards your totals. If you'd rather leave them out, use `activity:read`.

### Does it work with WordPress, Laravel or another framework?

Yes. It's a standalone PHP file, so you can put the folder anywhere PHP runs and fetch it from your theme. From PHP, you can also `require 'lib.php'` and call `strava_get_payload(strava_load_config())`.

### How do I update?

Replace every file except `config.php` and `data/`. If a new version adds options, compare your `config.php` with `config.example.php`. Missing options fall back to their defaults.

---

## Files

| File | Purpose |
|---|---|
| `strava.php` | The public JSON endpoint. Also works from the command line (`--refresh` skips the cache). |
| `setup.php` | Web installer: configuration, Strava connection and a feed test in the browser. Lock or delete it when you're done. |
| `connect.php` | One-time OAuth setup from the command line. Returns 404 over the web. |
| `lib.php` | Config, tokens, HTTP, caching and payload logic. |
| `config.example.php` | Documented config template. `setup.php` writes `config.php` for you, or copy this by hand. |
| `example.html` | Demo widget. |
| `README.md` | Plain-language setup guide. |
| `docs/ADVANCED.md` | This guide. |
| `data/` | Tokens and cache. Must be writable and private. |
| `.htaccess`, `data/.htaccess` | Apache rules that block everything except `strava.php`. |
