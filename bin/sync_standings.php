#!/usr/bin/env php
<?php
declare(strict_types=1);

// Standings don't need to change every minute, and each competition costs
// its own API call, so run this on a looser schedule:
//   */15 * * * * php /path/to/bin/sync_standings.php >> /path/to/storage/sync.log 2>&1
//
// 12 competitions = 12 requests per run. The free tier allows 10/minute, so
// calls are spaced ~7s apart below — that spreads the run over ~80 seconds,
// keeping any rolling 60-second window safely under the limit.

require __DIR__ . '/../src/Database.php';
require __DIR__ . '/../src/FootballDataClient.php';
require __DIR__ . '/../src/Sync.php';

$config = require __DIR__ . '/../config/config.php';

$db     = Database::connection();
$client = new FootballDataClient($config['football_data']);
$sync   = new Sync($db, $client, $config['football_data']['competitions']);

$seasonStartYear = (int) date('n') >= 7 ? (int) date('Y') : (int) date('Y') - 1;

foreach ($config['football_data']['competitions'] as $code) {
    try {
        $count = $sync->syncStandings($code, $seasonStartYear);
        echo sprintf("[%s] %s: synced %d standings rows\n", date('c'), $code, $count);
    } catch (Throwable $e) {
        fwrite(STDERR, sprintf("[%s] %s standings sync failed: %s\n", date('c'), $code, $e->getMessage()));
    }
    // Space calls out so 12 sequential requests never cluster past the free tier's
    // 10-per-minute limit — spread over ~80s total for this run.
    sleep(7);
}
