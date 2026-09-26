# NaijaScores — PHP 8 + MySQL livescore site

Fixtures, scores, standings, top scorers, head-to-head history, and team
profiles for the Premier League, La Liga, Bundesliga and Serie A, built on
the **football-data.org free tier**.

## Features
- **Fixtures & scores** — all 12 free-tier competitions, by date, delayed per football-data.org's free tier
- **Standings** — full table per league, including grouped tables for Champions League/World Cup/Euros group stages
- **Top scorers** — per competition
- **Head-to-head** — aggregate wins/draws/losses + last 10 meetings
- **Team profiles** — founded year, venue, colours, coach, recent results, upcoming fixtures
- **Goal events** — who scored, when, and who assisted, enriched from a second provider (API-Football)
- **Lineups & predictions** — confirmed starting XI/formation, and pre-match win/draw/loss forecasts
- **NPFL** — fully tracked (fixtures, scores, standings), sourced primarily from API-Football
- **Favorites, notifications, search** — client-side team following, browser notifications for watched matches, team search

## Two data sources, one system of record
football-data.org is the **primary source** for 12 competitions — every match, team, and standing
for those comes from it. API-Football serves two roles:
1. **Enrichment** on those 12 competitions — goal events, lineups, predictions, resolved by
   matching kickoff date + team names (the two providers use unrelated ID schemes).
2. **Primary source** for competitions football-data.org doesn't cover at all — currently NPFL.
   For these, API-Football's fixtures/teams/standings are written directly into the same
   `matches`/`teams`/`standings` tables, with every ID it writes namespaced as
   `(id_offset + their_id)` so it can never collide with football-data.org's IDs. This means
   every existing page (fixtures, competition, match, team) works on NPFL data unmodified.

If either provider is unreachable, unconfigured, or a match can't be resolved, the dependent
feature just doesn't render — nothing else on the site depends on it.

## Adding NPFL (or any other API-Football-only competition)
1. Get an API-Football key (see step 1 above) and set `API_FOOTBALL_KEY`.
2. Find the correct numeric league ID — **don't guess**, a wrong ID silently syncs the wrong
   league's data:
   ```bash
   php bin/lookup_league.php "NPFL"
   ```
3. Copy the ID into `config.php`'s `api_football.leagues['NPFL']`.
4. Run the migration if you haven't already: `mysql -u root -p < sql/005_add_npfl.sql`
5. Sync once by hand, then set up cron:
   ```bash
   php bin/sync_npfl_matches.php
   php bin/sync_npfl_standings.php
   ```
   ```cron
   */20 * * * * php /path/to/app/bin/sync_npfl_matches.php >> /path/to/app/storage/sync.log 2>&1
   0 */4 * * * php /path/to/app/bin/sync_npfl_standings.php >> /path/to/app/storage/sync.log 2>&1
   ```
   These share API-Football's 100-requests/day budget with the enrichment features on your other
   12 competitions — 1 call per NPFL matches run, 1 per standings run, so keep the schedule modest.

## Stack
- PHP 8.1+ (no framework, no Composer dependencies — just PDO + cURL, both
  bundled with PHP; requires the `pdo_mysql` extension, which ships with
  XAMPP by default)
- MySQL 8 (or MariaDB 10.5+)
- Plain HTML/CSS, no JS build step

## 1. Get API tokens
Register for a free key at https://www.football-data.org/client/register.
The free tier gives you 10 requests/minute and all 12 tracked competitions, but
**scores are delayed** (not second-by-second live) and lineups/stats require
a paid add-on — the site is built around that limitation, not against it.

Optionally, for goal-scorer/assist data, also register a free key at
https://www.api-football.com (100 requests/day free). Skip this if you don't
want goal events — the rest of the site works fine without it.

## 2. Create the database
```bash
mysql -u root -p < sql/schema.sql
```
Then create an app-specific DB user with access to the `livescore` database.

**Already have this app running from before adding scorers/H2H/team pages?**
Run the migration instead of dropping your data:
```bash
mysql -u root -p < sql/002_add_team_profile.sql
```
(Skip this on a brand new install — `schema.sql` already includes those columns.)

## 3. Make sure `storage/cache/` is writable
Head-to-head, scorers, and team profile lookups are cached to disk so
repeat page views don't burn API calls. PHP needs write access to this
folder:
```bash
mkdir -p storage/cache
chmod 775 storage/cache   # or whatever your web server user needs
```
If this folder isn't writable, the app still works — it just re-fetches
from the API on every view of those tabs instead of caching.

## 3. Configure
Set these as real environment variables (in your web server config, a
`.env` loader, or your shell) rather than editing `config/config.php`
directly:

```
DB_HOST=127.0.0.1
DB_NAME=livescore
DB_USER=livescore_app
DB_PASS=your-db-password
FOOTBALL_DATA_TOKEN=your-football-data-org-token
API_FOOTBALL_KEY=your-api-football-key
```

## 4. Run the sync jobs
Two cron entries — deliberately split so you never get close to the free
tier's rate limit:

```cron
# Fixtures + scores: 1 API call per run, safe to run every minute
* * * * * php /path/to/app/bin/sync_matches.php >> /path/to/app/storage/sync.log 2>&1

# Standings: 4 API calls per run (one per league), run less often
*/15 * * * * php /path/to/app/bin/sync_standings.php >> /path/to/app/storage/sync.log 2>&1
```

Run both once by hand first so the site isn't empty:
```bash
php bin/sync_matches.php
php bin/sync_standings.php
```

## 5. Serve the site
Point your web server's document root at `public/`. For local testing:
```bash
php -S localhost:8000 -t public
```
Then visit http://localhost:8000.

## Project layout
```
config/config.php            DB + both providers' credentials (reads from env vars)
src/Database.php              PDO connection
src/FootballDataClient.php    HTTP client for api.football-data.org/v4 (primary, 12 competitions)
src/ApiFootballClient.php     HTTP client for API-Football (enrichment + NPFL primary source)
src/Sync.php                  Upserts football-data.org data into MySQL
src/ApiFootballSync.php       Upserts API-Football data into MySQL, with ID-offset namespacing
src/FixtureResolver.php       Resolves a match to its API-Football fixture ID, cached
src/MatchEventsService.php    Goal events (who scored, when, assisted by whom)
src/MatchInsightsService.php  Lineups + pre-match predictions
src/Queries.php               Read-only queries + view helpers used by pages
bin/sync_matches.php          Cron: football-data.org fixtures & scores (1 API call/run)
bin/sync_standings.php        Cron: football-data.org standings (12 API calls/run, spaced)
bin/sync_npfl_matches.php     Cron: NPFL fixtures & scores from API-Football (1 API call/run)
bin/sync_npfl_standings.php   Cron: NPFL standings from API-Football (1 API call/run)
bin/lookup_league.php         One-off: find a competition's API-Football league ID
public/index.php              Homepage — all tracked competitions, by date
public/competition.php        One competition: Fixtures & Table / Top Scorers tabs
public/match.php              Match detail — Summary / Lineups / Table / H2H tabs
public/team.php                Team profile — bio, recent results, upcoming fixtures
public/search.php              Team search
sql/schema.sql                 Full schema for a fresh install
sql/002-005_*.sql              Migrations for existing installs, in order
storage/cache/                  File cache for enrichment API responses
```

## Notes on the free tiers
- football-data.org's scores are delayed, not push-live — the UI polls the page rather than
  promising real-time ticking. A page meta-refresh or a small `fetch()` polling loop (every
  30–60s) is the honest way to simulate "live" here; don't build WebSocket infrastructure
  against a feed that isn't real-time.
- API-Football's free tier is 100 requests/day total, shared across enrichment (events,
  lineups, predictions) and NPFL's primary sync. Everything that uses it is cached — but if
  you add more API-Football-primary competitions or get heavy simultaneous traffic during
  live matches, you can still hit the ceiling. When that happens, the affected feature just
  stops updating until quota resets at midnight — nothing else on the site is affected.
