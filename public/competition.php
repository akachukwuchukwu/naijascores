<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/../src/FootballDataClient.php';

$code = $_GET['code'] ?? '';
$validCodes = array_column($queries->competitions(), 'code');
if (!in_array($code, $validCodes, true)) {
    http_response_code(404);
    echo 'Unknown competition.';
    exit;
}

$activeCompetition = $code;
$competition = null;
foreach ($queries->competitions() as $c) {
    if ($c['code'] === $code) $competition = $c;
}

$tab = $_GET['tab'] ?? 'fixtures';
if (!in_array($tab, ['fixtures', 'scorers'], true)) {
    $tab = 'fixtures';
}

$date = $_GET['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $date = date('Y-m-d');
}
$prevDate = (new DateTimeImmutable($date))->modify('-1 day')->format('Y-m-d');
$nextDate = (new DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d');

$seasonStartYear = seasonForCompetition($code, $config, $currentSeasonStartYear);

if ($tab === 'fixtures') {
    $matches   = $queries->matchesForDate($date, $code);
    $standings = $queries->standingsFor($code, $seasonStartYear);
}

// Top scorers: one API call, cached for 15 minutes inside FootballDataClient.
if ($tab === 'scorers') {
    try {
        $client  = new FootballDataClient($config['football_data']);
        $scorers = $client->getScorers($code, 20);
    } catch (Throwable $e) {
        $scorersError = "Couldn't load top scorers right now.";
        $scorers = [];
    }
}

$pageTitle = $competition['name'];
require __DIR__ . '/partials/header.php';
?>

<div class="competition-heading">
    <h1><?= h($competition['name']) ?></h1>
    <p class="competition-heading__country"><?= h($competition['country']) ?></p>
</div>

<nav class="tabs">
    <a href="?code=<?= h($code) ?>&tab=fixtures" class="<?= $tab === 'fixtures' ? 'is-active' : '' ?>">Fixtures &amp; Table</a>
    <a href="?code=<?= h($code) ?>&tab=scorers" class="<?= $tab === 'scorers' ? 'is-active' : '' ?>">Top Scorers</a>
</nav>

<?php if ($tab === 'fixtures'): ?>

<div class="two-col">
    <section class="league-block">
        <h2 class="league-block__title">Table</h2>
        <?php if (empty($standings)): ?>
            <p class="empty-state">
                <?= $competition['type'] === 'CUP'
                    ? 'No group-stage table available right now (may be between group stage and knockouts, or standings haven\'t synced yet).'
                    : 'Standings haven\'t synced yet. Run <code>bin/sync_standings.php</code>.' ?>
            </p>
        <?php else: ?>
            <?php foreach ($standings as $groupLabel => $rows): ?>
            <?php if ($groupLabel !== 'TOTAL'): ?><h3 class="standings-group-title"><?= h($groupLabel) ?></h3><?php endif; ?>
            <table class="standings">
                <thead>
                    <tr>
                        <th>#</th><th>Team</th><th>P</th><th>W</th><th>D</th><th>L</th><th>GD</th><th>Pts</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><?= (int) $row['position'] ?></td>
                        <td class="standings__team">
                            <a href="/team.php?id=<?= (int) $row['team_id'] ?>"><?= h($row['name']) ?></a>
                        </td>
                        <td><?= (int) $row['played_games'] ?></td>
                        <td><?= (int) $row['won'] ?></td>
                        <td><?= (int) $row['draw'] ?></td>
                        <td><?= (int) $row['lost'] ?></td>
                        <td><?= (int) $row['goal_difference'] ?></td>
                        <td class="standings__pts"><?= (int) $row['points'] ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endforeach; ?>
        <?php endif; ?>
    </section>

    <section class="league-block">
        <h2 class="league-block__title">
            <a href="?code=<?= h($code) ?>&tab=fixtures&date=<?= h($prevDate) ?>" class="date-strip__arrow" aria-label="Previous day" style="margin-right:4px;">&lsaquo;</a>
            <?= h((new DateTimeImmutable($date))->format('j F')) ?> fixtures
            <a href="?code=<?= h($code) ?>&tab=fixtures&date=<?= h($nextDate) ?>" class="date-strip__arrow" aria-label="Next day" style="margin-left:4px;">&rsaquo;</a>
        </h2>
        <?php if (empty($matches)): ?>
            <p class="empty-state">No fixtures on this date.</p>
        <?php else: ?>
        <div class="fixture-list">
            <?php foreach ($matches as $match): ?>
                <?php require __DIR__ . '/partials/match_row.php'; ?>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </section>
</div>

<?php else: ?>

    <?php if (!empty($scorersError)): ?>
        <p class="empty-state"><?= h($scorersError) ?></p>
    <?php elseif (empty($scorers)): ?>
        <p class="empty-state">No scorer data available for this competition yet.</p>
    <?php else: ?>
    <table class="standings scorers-table">
        <thead>
            <tr><th>#</th><th>Player</th><th>Team</th><th>Goals</th><th>Assists</th><th>Pens</th><th>Apps</th></tr>
        </thead>
        <tbody>
            <?php foreach ($scorers as $i => $s): ?>
            <tr>
                <td><?= $i + 1 ?></td>
                <td class="standings__team"><?= h($s['player']['name'] ?? '—') ?></td>
                <td><?= h($s['team']['name'] ?? '—') ?></td>
                <td class="standings__pts"><?= (int) ($s['goals'] ?? 0) ?></td>
                <td><?= ($s['assists'] ?? null) !== null ? (int) $s['assists'] : '—' ?></td>
                <td><?= ($s['penalties'] ?? null) !== null ? (int) $s['penalties'] : '—' ?></td>
                <td><?= (int) ($s['playedMatches'] ?? 0) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

<?php endif; ?>

<?php require __DIR__ . '/partials/footer.php'; ?>