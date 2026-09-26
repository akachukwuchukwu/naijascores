<?php
declare(strict_types=1);

// Copy this file to config.php and fill in your real values.
// config.php itself is gitignored and never committed.

return [
    'db' => [
        'host'     => getenv('DB_HOST') ?: '127.0.0.1',
        'port'     => getenv('DB_PORT') ?: '3306',
        'name'     => getenv('DB_NAME') ?: 'livescore',
        'user'     => getenv('DB_USER') ?: 'livescore_app',
        'pass'     => getenv('DB_PASS') ?: 'change-me',
        'charset'  => 'utf8mb4',
    ],

    'football_data' => [
        'base_url' => 'https://api.football-data.org/v4',
        'token'    => getenv('FOOTBALL_DATA_TOKEN') ?: 'YOUR_API_TOKEN_HERE',
        'competitions' => ['PL', 'PD', 'BL1', 'SA', 'FL1', 'DED', 'PPL', 'ELC', 'BSA', 'CL', 'EC', 'WC'],
    ],

    'api_football' => [
        'base_url' => 'https://v3.football.api-sports.io',
        'token'    => getenv('API_FOOTBALL_KEY') ?: 'YOUR_API_FOOTBALL_KEY_HERE',
        'id_offset' => 1_000_000_000,
        'leagues' => [
            'NPFL' => null,
        ],
        'league_seasons' => [
            'NPFL' => 2024,
        ],
    ],

    'sync' => [
        'days_back'  => 1,
        'days_ahead' => 6,
    ],
];
