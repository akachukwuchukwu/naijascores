# NaijaScores

Live football scores, standings, lineups and head-to-head history for 13 competitions, built on two third-party APIs with PHP 8 and MySQL. No framework.

**Live demo:** https://naijascores.duckdns.org

<!-- Add a screenshot: save one as docs/screenshot.png, delete this comment, and uncomment the line below.
![NaijaScores home page](docs/screenshot.png)
-->

## What it does

- Fixtures and scores by date for 12 competitions (Premier League, La Liga, Bundesliga, Serie A, Ligue 1, Eredivisie, Primeira Liga, Championship, Brazilian Série A, Champions League, European Championship, World Cup), plus Nigeria's NPFL
- Standings, including group-stage tables for cup competitions
- Top scorers, head-to-head history, and team profiles
- Goal scorers and assists, confirmed lineups, and pre-match predictions, from a second API
- Follow teams, filter the fixture list down to your teams, get browser notifications for matches you're watching (while the tab is open), and search teams

## How it's built

**Two APIs, one schema.** football-data.org is the primary source for 12 competitions. API-Football supplies goal events, lineups and predictions, and is the primary source for NPFL, which football-data.org doesn't cover. The two providers use unrelated numeric IDs, so every ID from API-Football is stored as `id_offset + their_id` (an offset of 1,000,000,000). Both sources live in the same `matches`, `teams` and `standings` tables with no collisions, and every page works on either source without changes.

**Built around rate limits.** football-data.org allows 10 requests a minute and API-Football 100 a day. One request fetches fixtures for every competition at once. Slower-changing data (standings, scorers, head-to-head, team profiles, lineups) is cached on disk with lifetimes matched to how fast it changes, and the standings job spaces its calls to stay under the per-minute cap.

**Failures degrade quietly.** If a provider is down, over quota or unconfigured, the feature that depends on it doesn't render. Nothing else breaks.

**Secrets stay out of the repo.** `config/config.php` is gitignored and reads everything from environment variables. The Docker image is built from `config.example.php`, and real values are injected at runtime, so nothing sensitive is baked into an image layer.

## Run it locally

You need PHP 8.1+ with `pdo_mysql` and `curl`, MySQL 8 or MariaDB 10.5+, and a free [football-data.org](https://www.football-data.org/client/register) token. A free [API-Football](https://www.api-football.com) key is optional; it enables goal events, lineups, predictions and NPFL.

```bash
git clone https://github.com/akachukwuchukwu/naijascores.git
cd naijascores

# Create the database and a user for the app
mysql -u root -p < sql/schema.sql
mysql -u root -p -e "CREATE USER 'livescore_app'@'localhost' IDENTIFIED BY 'choose-a-password'; GRANT ALL ON livescore.* TO 'livescore_app'@'localhost';"

# Create your config, then fill in the database password and API keys
cp config/config.example.php config/config.php
```

`config.php` reads these environment variables, falling back to the values written in the file: `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`, `FOOTBALL_DATA_TOKEN`, `API_FOOTBALL_KEY`.

Then load some data and start the server:

```bash
php bin/sync_standings.php
php bin/sync_matches.php
php -S localhost:8000 -t public
```

Open http://localhost:8000.

`sql/schema.sql` is complete for a fresh install. The files `sql/002` to `sql/005` only upgrade databases created by older versions.

For NPFL, find the league ID with `php bin/lookup_league.php "NPFL"` and set it under `api_football.leagues` in `config.php`. See the limitations below before you do.

## Run it with Docker

```bash
cp .env.example .env          # fill in passwords and API keys
docker compose up -d --build
docker compose exec app php bin/sync_standings.php
```

Open http://localhost:8080. This starts the app (PHP 8.3 with Apache) and MySQL 8, with the scheduled jobs running inside the app container. [DOCKER.md](DOCKER.md) covers how it works and the problems worth knowing about.

## Scheduled jobs

| Job | Schedule | API calls per run |
|---|---|---|
| `bin/sync_matches.php` (fixtures and scores) | every minute | 1 |
| `bin/sync_standings.php` (12 competitions) | every 15 minutes | 12, spaced 7 seconds apart |
| `bin/sync_npfl_matches.php` | every 20 minutes | 1 |
| `bin/sync_npfl_standings.php` | every 4 hours | 1 |

The Docker image already includes these in `docker/crontab`. On a plain server, add them to your own crontab.

## Known limitations

These come from the free tiers of the two APIs, not from the code:

- **Scores are delayed.** football-data.org's free tier isn't live. The page refreshes every minute during live matches.
- **Goal events, lineups and predictions only work for matches within about a day of today.** API-Football's free plan restricts its date lookup to that window. Older matches show a message saying so.
- **NPFL shows a past season.** On API-Football's free plan the NPFL is only available for 2022 to 2024, so the demo shows the 2023/24 season. A paid plan unlocks the current one.
- **Browser notifications need the tab open.** They poll while the page is open; they aren't push notifications.

## Project layout

```
bin/          Sync jobs and the league-ID lookup tool
config/       config.example.php (copy to config.php)
docker/       Container entrypoint and cron schedule
public/       Web root: pages, partials, assets, and the JSON status endpoint
sql/          schema.sql, plus upgrade migrations
src/          API clients, sync services, queries, fixture resolver, events and insights services
storage/      On-disk cache (gitignored contents)
```

## Deployment

The live demo runs on Oracle Cloud's Always Free tier: Ubuntu 24.04, Apache, PHP 8.3 and MySQL 8, with HTTPS from Let's Encrypt and the four sync jobs on real cron.
