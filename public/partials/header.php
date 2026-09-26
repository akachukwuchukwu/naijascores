<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?php if (!empty($autoRefreshSeconds)): ?>
<meta http-equiv="refresh" content="<?= (int) $autoRefreshSeconds ?>">
<?php endif; ?>
<title><?= isset($pageTitle) ? h($pageTitle) . ' · ' : '' ?>NaijaScores</title>
<link rel="icon" href="/assets/img/favicon.ico" sizes="any">
<link rel="icon" type="image/svg+xml" href="/assets/img/favicon.svg">
<link rel="apple-touch-icon" href="/assets/img/apple-touch-icon.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Newsreader:ital,wght@1,500;1,600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
<header class="topbar">
    <div class="wrap topbar__inner">
        <a href="/" class="brand">
            <svg class="brand__mark" width="24" height="24" viewBox="0 0 512 512" aria-hidden="true">
                <rect x="0" y="0" width="512" height="512" rx="104" fill="#0C0F0D"/>
                <circle cx="256" cy="256" r="140" fill="none" stroke="#2FA97A" stroke-width="20"/>
                <line x1="135" y1="326" x2="377" y2="326" stroke="#2FA97A" stroke-width="20" stroke-linecap="round"/>
                <circle cx="256" cy="256" r="18" fill="#F1F3F1"/>
            </svg>
            <span>Naija<em>Scores</em></span>
        </a>
        <nav class="topbar__nav">
            <a href="/" class="<?= !isset($activeCompetition) ? 'is-active' : '' ?>">Scores</a>
        </nav>
        <div class="topbar__actions">
            <form class="topbar__search" action="/search.php" method="get">
                <svg viewBox="0 0 20 20" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="9" cy="9" r="6.5"/><line x1="18" y1="18" x2="13.8" y2="13.8"/></svg>
                <input type="text" name="q" placeholder="Search teams…" value="<?= h($_GET['q'] ?? '') ?>">
            </form>
        </div>
    </div>
</header>

<div class="shell wrap">
    <aside class="sidebar">
        <?php
        $leagueComps = array_filter($queries->competitions(), fn($c) => $c['type'] === 'LEAGUE');
        $cupComps    = array_filter($queries->competitions(), fn($c) => $c['type'] === 'CUP');
        ?>
        <p class="sidebar__heading">Leagues</p>
        <ul class="sidebar__list">
            <?php foreach ($leagueComps as $comp): ?>
            <li>
                <a href="/competition.php?code=<?= h($comp['code']) ?>"
                   class="<?= (isset($activeCompetition) && $activeCompetition === $comp['code']) ? 'is-active' : '' ?>">
                    <span class="sidebar__badge"><?= h(substr($comp['code'], 0, 2)) ?></span>
                    <span class="sidebar__label">
                        <?= h($comp['name']) ?>
                        <small><?= h($comp['country']) ?></small>
                    </span>
                </a>
            </li>
            <?php endforeach; ?>
        </ul>

        <p class="sidebar__heading">Cups &amp; Tournaments</p>
        <ul class="sidebar__list">
            <?php foreach ($cupComps as $comp): ?>
            <li>
                <a href="/competition.php?code=<?= h($comp['code']) ?>"
                   class="<?= (isset($activeCompetition) && $activeCompetition === $comp['code']) ? 'is-active' : '' ?>">
                    <span class="sidebar__badge"><?= h(substr($comp['code'], 0, 2)) ?></span>
                    <span class="sidebar__label">
                        <?= h($comp['name']) ?>
                        <small><?= h($comp['country']) ?></small>
                    </span>
                </a>
            </li>
            <?php endforeach; ?>
        </ul>

        <hr class="sidebar__divider">
        <p class="sidebar__heading">Following</p>
        <ul class="sidebar__list" id="favorites-list">
            <li class="empty-favorites" style="padding:8px 10px; font-size:0.78rem; color:var(--text-faint);">
                No teams followed yet — star a team to add it here.
            </li>
        </ul>
    </aside>

    <main class="content-col">
