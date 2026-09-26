#!/usr/bin/env php
<?php
declare(strict_types=1);

// Run regularly during matchdays, e.g. every 15-30 minutes:
//   */20 * * * * php /path/to/bin/sync_npfl_matches.php >> /path/to/storage/sync.log 2>&1
//
// Costs exactly 1 API-Football request per run. Shared 100/day budget with
// goal-event/lineup/prediction enrichment on the other 12 competitions —
// don't run this more often than every ~15 minutes if you're also actively
// browsing matches on those.

require __DIR__ . '/../src/Database.php';
require __DIR__ . '/../src/ApiFootballClient.php';
require __DIR__ . '/../src/ApiFootballSync.php';

$config = require __DIR__ . '/../config/config.php';
$leagueId = $config['api_football']['leagues']['NPFL'] ?? null;

if ($leagueId === null) {
    fwrite(STDERR, "NPFL league ID not configured. Run: php bin/lookup_league.php \"NPFL\"\n");
    fwrite(STDERR, "then fill in config.php's api_football.leagues['NPFL'].\n");
    exit(1);
}

$db     = Database::connection();
$client = new ApiFootballClient($config['api_football']);
$sync   = new ApiFootballSync($db, $client, $config['api_football']['id_offset']);

// NPFL runs August-May — same "which season year" convention used elsewhere in this app.
// Override in config.php's api_football.league_seasons if your plan doesn't allow the
// current season (API-Football's free tier restricts this per-competition).
$season = $config['api_football']['league_seasons']['NPFL']
    ?? (((int) date('n')) >= 7 ? (int) date('Y') : (int) date('Y') - 1);

$dateFrom = (new DateTimeImmutable("-{$config['sync']['days_back']} days"))->format('Y-m-d');
$dateTo   = (new DateTimeImmutable("+{$config['sync']['days_ahead']} days"))->format('Y-m-d');

// If a season override is configured (testing a historical season your plan
// allows, rather than the live current one), "today ± a few days" won't
// overlap with that season's actual matches — pull the whole season instead.
if (isset($config['api_football']['league_seasons']['NPFL'])) {
    $dateFrom = ($season - 1) . "-07-01";
    $dateTo   = "{$season}-06-30";
}

try {
    $count = $sync->syncFixtures('NPFL', $leagueId, $season, $dateFrom, $dateTo);
    echo sprintf("[%s] NPFL: synced %d fixtures\n", date('c'), $count);
} catch (Throwable $e) {
    fwrite(STDERR, sprintf("[%s] NPFL sync failed: %s\n", date('c'), $e->getMessage()));
    exit(1);
}