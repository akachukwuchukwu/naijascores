<?php
declare(strict_types=1);

date_default_timezone_set('Europe/London');

require __DIR__ . '/../src/Database.php';
require __DIR__ . '/../src/Queries.php';

$config = require __DIR__ . '/../config/config.php';
$db      = Database::connection();
$queries = new Queries($db);

$currentSeasonStartYear = ((int) date('n')) >= 7 ? (int) date('Y') : (int) date('Y') - 1;

/** Small helper so templates can escape output tersely. */
function h(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * The season year to query for a given competition. Most competitions use
 * the auto-computed current season, but a competition can be pinned to a
 * specific season in config.php's api_football.league_seasons (e.g. when a
 * free-tier plan restricts which season is actually accessible) — pages
 * should display whatever season was actually synced, not assume "now".
 */
function seasonForCompetition(string $competitionCode, array $config, int $default): int
{
    return $config['api_football']['league_seasons'][$competitionCode] ?? $default;
}