#!/usr/bin/env php
<?php
declare(strict_types=1);

// Run this once to find the correct API-Football league ID for a competition
// before adding it to config.php's api_football.leagues map. Don't guess —
// a wrong ID silently syncs the wrong league's data.
//
//   php bin/lookup_league.php "NPFL"
//   php bin/lookup_league.php "Nigeria"

require __DIR__ . '/../src/ApiFootballClient.php';

$config = require __DIR__ . '/../config/config.php';
$query  = $argv[1] ?? null;

if ($query === null) {
    fwrite(STDERR, "Usage: php bin/lookup_league.php \"search term\"\n");
    exit(1);
}

$client = new ApiFootballClient($config['api_football']);

try {
    $results = $client->searchLeagues($query)['response'] ?? [];
} catch (Throwable $e) {
    fwrite(STDERR, "Lookup failed: {$e->getMessage()}\n");
    exit(1);
}

if (empty($results)) {
    echo "No leagues found matching \"{$query}\".\n";
    exit(0);
}

foreach ($results as $entry) {
    $league  = $entry['league'];
    $country = $entry['country']['name'] ?? 'Unknown';
    $seasons = array_map(fn($s) => $s['year'], $entry['seasons'] ?? []);
    $current = null;
    foreach ($entry['seasons'] ?? [] as $s) {
        if ($s['current'] ?? false) $current = $s['year'];
    }

    echo str_repeat('-', 50) . "\n";
    echo "ID:              {$league['id']}\n";
    echo "Name:            {$league['name']} ({$league['type']})\n";
    echo "Country:         {$country}\n";
    echo "Current season:  " . ($current ?? 'unknown') . "\n";
    echo "Available seasons: " . implode(', ', $seasons) . "\n";
}

echo str_repeat('-', 50) . "\n";
echo "Copy the ID for the correct league into config.php's api_football.leagues map.\n";
