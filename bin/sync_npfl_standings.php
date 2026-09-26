#!/usr/bin/env php
<?php
declare(strict_types=1);

// Standings change less often than scores — run a few times a day:
//   0 */4 * * * php /path/to/bin/sync_npfl_standings.php >> /path/to/storage/sync.log 2>&1
//
// Costs exactly 1 API-Football request per run.

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

// Override in config.php's api_football.league_seasons if your plan doesn't allow the
// current season (API-Football's free tier restricts this per-competition).
$season = $config['api_football']['league_seasons']['NPFL']
    ?? (((int) date('n')) >= 7 ? (int) date('Y') : (int) date('Y') - 1);

try {
    $count = $sync->syncStandings('NPFL', $leagueId, $season);
    echo sprintf("[%s] NPFL: synced %d standings rows\n", date('c'), $count);
} catch (Throwable $e) {
    fwrite(STDERR, sprintf("[%s] NPFL standings sync failed: %s\n", date('c'), $e->getMessage()));
    exit(1);
}