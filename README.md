# Strava Stats

A small, dependency-free PHP endpoint that turns your Strava account into a clean JSON feed for your website. It covers your profile, this week's totals, year-to-date and all-time stats, and your recent activities.

It handles the OAuth tokens for you (including automatic refresh), caches responses so a busy site never hits Strava's rate limits, and only publishes the fields you choose.

```
Browser ──▶ strava.php ──▶ cache (5 min) ──▶ Strava API
                │
                └── tokens.json (refreshed automatically)
```

- **No framework, no Composer.** Upload four PHP files and you're done.
- **Safe by default.** Your client secret and tokens never reach the browser, and the rest of your profile (bikes, clubs, email) stays private unless you choose to publish it.
- **Rate-limit friendly.** A shared cache and a lock mean Strava gets at most 3 requests per cache period, however much traffic you get.
- **Keeps working if Strava is down.** It serves the last good response, marked `"stale": true`, instead of an error.
- **Configurable.** Choose activity types, the week start day, the timezone, which fields to expose, CORS and cache times, from a config file or from environment variables.

---

## Contents

- [Requirements](#requirements)
- [Quick start](#quick-start)
- [Configuration](#configuration)
- [The JSON response](#the-json-response)
- [Using it on your site](#using-it-on-your-site)
- [Securing your tokens](#securing-your-tokens)
- [Rate limits and caching](#rate-limits-and-caching)
- [Troubleshooting](#troubleshooting)
- [FAQ](#faq)
- [Strava API terms](#strava-api-terms)

---

## Requirements

- PHP **7.4 or newer** with the `curl` and `json` extensions (both standard on almost every host)
- A web server that runs PHP (Apache, nginx with PHP-FPM, LiteSpeed, Caddy, …)
- Terminal access for one-time setup: SSH, or PHP on your own computer (see [No SSH?](#i-dont-have-ssh-access-to-my-host))
- A Strava account

## Quick start

### 1. Create a Strava API application

1. Go to **<https://www.strava.com/settings/api>**.
2. Fill in the form. The values are mostly cosmetic:
   - **Application Name:** anything, e.g. "My Website"
   - **Category:** anything sensible
   - **Website:** your site's URL
   - **Authorization Callback Domain:** your site's domain (e.g. `example.com`). The setup script uses `localhost`, which Strava always allows, so this value doesn't matter much.
3. Click **Create**, then note the **Client ID** and **Client Secret**.

### 2. Download and configure

```bash
git clone https://github.com/JShipps/strava-stats.git
cd strava-stats
cp config.example.php config.php
```

Open `config.php` and paste in your Client ID and Client Secret:

```php
'client_id'     => '12345',
'client_secret' => 'abcdef0123456789abcdef0123456789abcdef01',
```

Every other setting has a sensible default. See [Configuration](#configuration).

### 3. Connect your Strava account (one time)

```bash
php connect.php
```

The script prints a link:

1. Open the link and click **Authorize**.
2. Your browser goes to an address starting with `http://localhost/exchange_token?...` and shows an error page. **This is expected.**
3. Copy the whole address from the address bar and paste it into the terminal.

```
Connected as Jane Rider. Tokens saved to /var/www/strava-stats/data/tokens.json
```

From now on, the tokens refresh automatically. You won't need to run this again unless you revoke access or change accounts.

### 4. Test it

```bash
php strava.php --refresh
```

You should see your stats as JSON. Then try it in a browser at `https://example.com/strava-stats/strava.php`.

### 5. Upload

Upload the folder to your web server if you set it up somewhere else. Make sure that:

- the `data/` folder is **writable by the web server** (it stores refreshed tokens and the cache), and
- the tokens can't be downloaded. Read [Securing your tokens](#securing-your-tokens).

To display the stats, see [Using it on your site](#using-it-on-your-site), or open `example.html` for a working demo.

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
    const km = (data.week.distance / 1000).toFixed(1);
    document.getElementById('strava').textContent =
      `${data.athlete.firstname} has ridden ${km} km in ${data.week.count} rides this week.`;
  })
  .catch(err => console.error('Strava stats:', err));
</script>
```

`example.html` has a fuller widget with a profile, weekly tiles, a recent activity list, a metric/imperial switch and auto-refresh.

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
| `client_id and client_secret are not configured` | `config.php` is missing or empty. Run `cp config.example.php config.php` and fill it in. |
| `No Strava account connected. Run: php connect.php` | No tokens yet, or `token_file` points to the wrong place. |
| `Could not refresh the Strava token … run php connect.php again` | Access was revoked, the client secret changed, or the tokens belong to a different app. Run `php connect.php` again. |
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

Run the setup on your own computer, which needs PHP installed:

1. Download the project, create `config.php`, and run `php connect.php` locally.
2. Upload the whole folder, **including `data/tokens.json`**, to your host.
3. Make sure `data/` is writable on the host, because tokens are rewritten every ~6 hours when they refresh.

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
| `connect.php` | One-time OAuth setup. Command line only, and returns 404 over the web. |
| `lib.php` | Config, tokens, HTTP, caching and payload logic. |
| `config.example.php` | Documented config template. Copy it to `config.php`. |
| `example.html` | Demo widget. |
| `data/` | Tokens and cache. Must be writable and private. |
| `.htaccess`, `data/.htaccess` | Apache rules that block everything except `strava.php`. |

## Strava API terms

By using the Strava API you agree to the [Strava API Agreement](https://www.strava.com/legal/api) and the [brand guidelines](https://developers.strava.com/guidelines/). In particular:

- Display a **"Powered by Strava"** or **"Compatible with Strava"** logo where you show the data.
- Link back to Strava where you show Strava data. The response includes `url` fields for this.
- Don't imply that Strava endorses your site.

This project is not affiliated with or endorsed by Strava, Inc.

## License

Add your license of choice here (for example MIT), and include a matching `LICENSE` file.
