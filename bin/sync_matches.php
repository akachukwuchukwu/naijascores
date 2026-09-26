#!/usr/bin/env php
<?php
declare(strict_types=1);

// Run frequently (e.g. every minute during matchdays) via cron:
//   * * * * * php /path/to/bin/sync_matches.php >> /path/to/storage/sync.log 2>&1
//
// This makes exactly ONE football-data.org API call regardless of how many
// of the 4 leagues have matches that day, so it stays well inside the
// free tier's 10 requests/minute limit.

require __DIR__ . '/../src/Database.php';
require __DIR__ . '/../src/FootballDataClient.php';
require __DIR__ . '/../src/Sync.php';

$config = require __DIR__ . '/../config/config.php';

$db     = Database::connection();
$client = new FootballDataClient($config['football_data']);
$sync   = new Sync($db, $client, $config['football_data']['competitions']);

try {
    $count = $sync->syncMatches(
        $config['sync']['days_back'],
        $config['sync']['days_ahead']
    );
    echo sprintf("[%s] Synced %d matches\n", date('c'), $count);
} catch (Throwable $e) {
    fwrite(STDERR, sprintf("[%s] Sync failed: %s\n", date('c'), $e->getMessage()));
    exit(1);
}
