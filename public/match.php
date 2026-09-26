<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/../src/FootballDataClient.php';
require __DIR__ . '/../src/Sync.php';
require __DIR__ . '/../src/ApiFootballClient.php';
require __DIR__ . '/../src/FixtureResolver.php';
require __DIR__ . '/../src/MatchEventsService.php';
require __DIR__ . '/../src/MatchInsightsService.php';

$id = (int) ($_GET['id'] ?? 0);
$match = $id > 0 ? $queries->matchById($id) : null;

if ($match === null) {
    http_response_code(404);
    $pageTitle = 'Not found';
    require __DIR__ . '/partials/header.php';
    echo '<p class="empty-state">Match not found.</p>';
    require __DIR__ . '/partials/footer.php';
    exit;
}

$tab = $_GET['tab'] ?? 'summary';
if (!in_array($tab, ['summary', 'table', 'h2h', 'lineups'], true)) {
    $tab = 'summary';
}

$live = is_live($match['status']);
$scoreKnown = $match['home_score'] !== null;

// Goal events, lineups, and predictions all come from API-Football, resolved
// via the same cached fixture-ID lookup. football-data.org's free tier
// doesn't include any of this — everything here degrades to an empty result
// (not an error) if the secondary source is unavailable or unconfigured.
//
// API-Football's free plan also restricts the date-based fixture lookup
// itself to roughly a day either side of today — not just historical
// seasons. A match from days ago or days in the future will always fail to
// resolve on the free plan, no matter how correctly everything else works.
// Checking this upfront avoids a wasted API call and lets the UI say
// something honest instead of the misleading "not confirmed yet".
$kickoffDaysAway = (int) (new DateTimeImmutable($match['utc_kickoff']))
    ->diff(new DateTimeImmutable('today'))->format('%r%a');
$outsideEnrichmentWindow = $match['api_football_fixture_id'] === null && abs($kickoffDaysAway) > 1;

$goalEvents = [];
$predictions = [];
$lineups = ['home' => null, 'away' => null];

if (in_array($tab, ['summary', 'lineups'], true) && !$outsideEnrichmentWindow) {
    try {
        $afClient = new ApiFootballClient($config['api_football']);
        $resolver = new FixtureResolver($db, $afClient);

        if ($tab === 'summary') {
            if (in_array($match['status'], ['IN_PLAY', 'PAUSED', 'FINISHED'], true)) {
                $goalEvents = (new MatchEventsService($afClient, $resolver))->getGoalEvents($match);
            } elseif (in_array($match['status'], ['SCHEDULED', 'TIMED'], true)) {
                $predictions = (new MatchInsightsService($afClient, $resolver))->getPredictions($match);
            }
        }

        if ($tab === 'lineups') {
            $lineups = (new MatchInsightsService($afClient, $resolver))->getLineups($match);
        }
    } catch (Throwable $e) {
        // Secondary source unavailable — leave the defaults above and move on.
    }
}

if ($tab === 'table') {
    $standings = $queries->standingsFor($match['competition_code'], seasonForCompetition($match['competition_code'], $config, $currentSeasonStartYear));
}

// H2H: football-data.org has a dedicated endpoint for this — aggregate wins/draws/losses
// plus the last 10 meetings — so we call it directly rather than guessing from local data.
// Cached for 15 minutes inside FootballDataClient, so repeat views don't cost extra calls.
if ($tab === 'h2h') {
    try {
        $client = new FootballDataClient($config['football_data']);
        $h2h = $client->getHeadToHead($id, 10);
    } catch (Throwable $e) {
        $h2hError = "Couldn't fetch head-to-head history right now.";
        $h2h = [];
    }
}

$pageTitle = $match['home_name'] . ' vs ' . $match['away_name'];
$autoRefreshSeconds = $live ? 60 : null;
require __DIR__ . '/partials/header.php';
?>

<div class="match-card <?= $live ? 'match-card--live' : '' ?>">
    <p class="match-card__competition"><?= h($match['competition_name']) ?></p>

    <div class="match-card__scoreline">
        <div class="match-card__team">
            <a href="/team.php?id=<?= (int) $match['home_team_id'] ?>" class="match-card__team-name">
                <?= h($match['home_name']) ?>
            </a>
        </div>
        <div class="match-card__score">
            <?= $scoreKnown ? (int) $match['home_score'] : '–' ?>
            <span class="match-card__dash">:</span>
            <?= $scoreKnown ? (int) $match['away_score'] : '–' ?>
        </div>
        <div class="match-card__team">
            <a href="/team.php?id=<?= (int) $match['away_team_id'] ?>" class="match-card__team-name">
                <?= h($match['away_name']) ?>
            </a>
        </div>
    </div>

    <p class="match-card__status">
        <?php if ($live): ?><span class="live-dot" aria-hidden="true"></span><?php endif; ?>
        <?= h(status_label($match['status'])) ?>
        <?php if ($match['home_ht_score'] !== null): ?>
            &middot; HT <?= (int) $match['home_ht_score'] ?>-<?= (int) $match['away_ht_score'] ?>
        <?php endif; ?>
    </p>

    <?php if (!in_array($match['status'], ['FINISHED', 'CANCELLED', 'AWARDED'], true)): ?>
    <div class="match-card__notify">
        <button type="button" class="notify-btn"
                data-match-id="<?= (int) $match['id'] ?>"
                data-home-name="<?= h($match['home_name']) ?>"
                data-away-name="<?= h($match['away_name']) ?>"
                data-home-score="<?= $scoreKnown ? (int) $match['home_score'] : '' ?>"
                data-away-score="<?= $scoreKnown ? (int) $match['away_score'] : '' ?>"
                data-status="<?= h($match['status']) ?>">
            🔔 Notify me
        </button>
    </div>
    <?php endif; ?>
</div>

<nav class="tabs">
    <a href="?id=<?= $id ?>&tab=summary" class="<?= $tab === 'summary' ? 'is-active' : '' ?>">Summary</a>
    <a href="?id=<?= $id ?>&tab=lineups" class="<?= $tab === 'lineups' ? 'is-active' : '' ?>">Lineups</a>
    <a href="?id=<?= $id ?>&tab=table" class="<?= $tab === 'table' ? 'is-active' : '' ?>">Table</a>
    <a href="?id=<?= $id ?>&tab=h2h" class="<?= $tab === 'h2h' ? 'is-active' : '' ?>">H2H</a>
</nav>

<?php if ($tab === 'summary'): ?>

    <?php if (!empty($goalEvents)): ?>
    <div class="goals-timeline">
        <?php foreach ($goalEvents as $goal): ?>
        <div class="goal-event goal-event--<?= h($goal['side']) ?>">
            <span class="goal-event__minute"><?= h($goal['minute']) ?></span>
            <span class="goal-event__detail">
                <span class="goal-event__player"><?= h($goal['player']) ?></span>
                <?php if ($goal['assist']): ?><span class="goal-event__assist">assist: <?= h($goal['assist']) ?></span><?php endif; ?>
                <?php if ($goal['type'] !== 'Normal Goal'): ?><span class="goal-event__tag"><?= h($goal['type']) ?></span><?php endif; ?>
            </span>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if (!empty($predictions)): ?>
    <div class="prediction-card">
        <p class="prediction-card__title">Match prediction</p>
        <div class="prediction-card__bars">
            <div class="prediction-bar">
                <div class="prediction-bar__fill" style="width: <?= h($predictions['homePct']) ?>"></div>
            </div>
        </div>
        <div class="prediction-card__labels">
            <span><?= h($match['home_name']) ?> <strong><?= h($predictions['homePct']) ?></strong></span>
            <span>Draw <strong><?= h($predictions['drawPct']) ?></strong></span>
            <span><?= h($match['away_name']) ?> <strong><?= h($predictions['awayPct']) ?></strong></span>
        </div>
        <?php if ($predictions['predictedHomeGoals'] || $predictions['predictedAwayGoals']): ?>
        <p class="prediction-card__goals">
            Predicted goals: <?= h($predictions['predictedHomeGoals'] ?? '?') ?> &ndash; <?= h($predictions['predictedAwayGoals'] ?? '?') ?>
        </p>
        <?php endif; ?>
        <?php if ($predictions['advice']): ?>
        <p class="prediction-card__advice"><?= h($predictions['advice']) ?></p>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <dl class="match-card__meta match-card__meta--standalone">
        <dt>Kickoff</dt>
        <dd><?= h((new DateTimeImmutable($match['utc_kickoff']))->format('l j F, H:i')) ?></dd>
        <?php if ($match['venue']): ?>
            <dt>Venue</dt>
            <dd><?= h($match['venue']) ?></dd>
        <?php endif; ?>
        <?php if ($match['matchday']): ?>
            <dt>Matchday</dt>
            <dd><?= (int) $match['matchday'] ?></dd>
        <?php endif; ?>
    </dl>
    <p class="tab-note">
        Goals, predictions and lineups come from a secondary source (API-Football) and may briefly
        lag behind the score. Live in-match stats still aren't included on either free tier.
    </p>

<?php elseif ($tab === 'lineups'): ?>

    <?php if ($lineups['home'] === null && $lineups['away'] === null): ?>
        <p class="empty-state">
            <?php if ($outsideEnrichmentWindow): ?>
                Detailed match data (lineups, goal events, predictions) is only available for matches
                within about a day of today &mdash; a limit of the free data plan this site runs on,
                not something specific to this match.
            <?php else: ?>
                Lineups aren't confirmed yet &mdash; these are usually published 60&ndash;75 minutes before kickoff.
            <?php endif; ?>
        </p>
    <?php else: ?>
    <div class="lineups-grid">
        <?php foreach (['home', 'away'] as $side): ?>
        <div class="lineup-side">
            <?php if ($lineups[$side] === null): ?>
                <p class="empty-state">No lineup available for this side.</p>
            <?php else: ?>
                <p class="lineup-side__team"><?= h($match[$side . '_name']) ?></p>
                <p class="lineup-side__meta">
                    <?php if ($lineups[$side]['formation']): ?>Formation <?= h($lineups[$side]['formation']) ?><?php endif; ?>
                    <?php if ($lineups[$side]['coach']): ?> &middot; Coach: <?= h($lineups[$side]['coach']) ?><?php endif; ?>
                </p>
                <ul class="lineup-list">
                    <?php foreach ($lineups[$side]['startXI'] as $p): ?>
                    <li><span class="lineup-list__num"><?= h((string) ($p['number'] ?? '')) ?></span><?= h($p['name']) ?></li>
                    <?php endforeach; ?>
                </ul>
                <?php if (!empty($lineups[$side]['substitutes'])): ?>
                <p class="lineup-side__subs-title">Substitutes</p>
                <ul class="lineup-list lineup-list--subs">
                    <?php foreach ($lineups[$side]['substitutes'] as $p): ?>
                    <li><span class="lineup-list__num"><?= h((string) ($p['number'] ?? '')) ?></span><?= h($p['name']) ?></li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

<?php elseif ($tab === 'table'): ?>

    <?php if (empty($standings)): ?>
        <p class="empty-state">No table available for this competition right now.</p>
    <?php else: ?>
        <?php foreach ($standings as $groupLabel => $rows): ?>
        <?php if ($groupLabel !== 'TOTAL'): ?><h3 class="standings-group-title"><?= h($groupLabel) ?></h3><?php endif; ?>
        <table class="standings">
            <thead>
                <tr><th>#</th><th>Team</th><th>P</th><th>W</th><th>D</th><th>L</th><th>GD</th><th>Pts</th></tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row): ?>
                <?php $isMatchTeam = in_array((int) $row['team_id'], [$match['home_team_id'], $match['away_team_id']], true); ?>
                <tr class="<?= $isMatchTeam ? 'standings__row--highlight' : '' ?>">
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

<?php elseif ($tab === 'h2h'): ?>

    <?php if (!empty($h2hError)): ?>
        <p class="empty-state"><?= h($h2hError) ?></p>
    <?php elseif (empty($h2h) || ($h2h['numberOfMatches'] ?? 0) === 0): ?>
        <p class="empty-state">No previous meetings on record for these two teams.</p>
    <?php else: ?>
        <?php $draws = (int) $h2h['numberOfMatches'] - (int) $h2h['homeTeam']['wins'] - (int) $h2h['awayTeam']['wins']; ?>
        <div class="h2h-summary">
            <div class="h2h-summary__stat">
                <strong><?= (int) $h2h['homeTeam']['wins'] ?></strong>
                <span><?= h($match['home_name']) ?> wins</span>
            </div>
            <div class="h2h-summary__stat">
                <strong><?= $draws ?></strong>
                <span>Draws</span>
            </div>
            <div class="h2h-summary__stat">
                <strong><?= (int) $h2h['awayTeam']['wins'] ?></strong>
                <span><?= h($match['away_name']) ?> wins</span>
            </div>
        </div>
        <div class="fixture-list">
            <?php foreach (($h2h['matches'] ?? []) as $row): ?>
                <?php require __DIR__ . '/partials/h2h_row.php'; ?>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

<?php endif; ?>

<?php require __DIR__ . '/partials/footer.php'; ?>