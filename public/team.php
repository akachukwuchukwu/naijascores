<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/../src/FootballDataClient.php';
require __DIR__ . '/../src/Sync.php';

$id = (int) ($_GET['id'] ?? 0);
$team = $id > 0 ? $queries->teamById($id) : null;

if ($team === null) {
    http_response_code(404);
    $pageTitle = 'Not found';
    require __DIR__ . '/partials/header.php';
    echo '<p class="empty-state">Team not found.</p>';
    require __DIR__ . '/partials/footer.php';
    exit;
}

// Profile fields (founded, venue, colours, coach) come from football-data.org
// and only apply to teams sourced from it. API-Football-sourced teams (e.g.
// NPFL clubs) skip this entirely — that provider's team-profile endpoint
// isn't wired up here, so there's nothing to fetch, and no point trying.
$needsProfileSync = $team['data_source'] === 'football_data'
    && ($team['profile_synced_at'] === null
        || (new DateTimeImmutable($team['profile_synced_at']))->modify('+30 days') < new DateTimeImmutable());

if ($needsProfileSync) {
    try {
        $client = new FootballDataClient($config['football_data']);
        $sync   = new Sync($db, $client, $config['football_data']['competitions']);
        $sync->syncTeamProfile($id);
        $team = $queries->teamById($id);
    } catch (Throwable $e) {
        // Non-fatal — just show whatever we already had (possibly nothing yet).
    }
}

$recentMatches = $queries->matchesForTeam($id, 12);
$upcoming = array_values(array_filter($recentMatches, fn($m) => !in_array($m['status'], ['FINISHED'], true)));
$played   = array_values(array_filter($recentMatches, fn($m) => $m['status'] === 'FINISHED'));

$pageTitle = $team['name'];
require __DIR__ . '/partials/header.php';
?>

<div class="team-header">
    <span class="team-header__crest">
        <?php if ($team['crest_url']): ?>
            <img src="<?= h($team['crest_url']) ?>" alt="" loading="lazy" onerror="this.remove()">
        <?php endif; ?>
        <span class="team-header__crest-fallback"><?= h(substr($team['tla'] ?? $team['name'], 0, 1)) ?></span>
    </span>
    <div class="team-header__info">
        <h1><?= h($team['name']) ?></h1>
        <?php if ($team['venue']): ?><p class="team-header__venue"><?= h($team['venue']) ?></p><?php endif; ?>
    </div>
    <button type="button" class="star-btn" data-team-id="<?= (int) $team['id'] ?>" data-team-name="<?= h($team['name']) ?>" title="Follow this team" aria-label="Follow this team">
        <svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2l2.9 6.9 7.4.6-5.6 4.9 1.7 7.2L12 17.8l-6.4 3.8 1.7-7.2-5.6-4.9 7.4-.6L12 2z"/></svg>
    </button>
</div>

<dl class="team-meta">
    <?php if ($team['founded']): ?><dt>Founded</dt><dd><?= (int) $team['founded'] ?></dd><?php endif; ?>
    <?php if ($team['club_colors']): ?><dt>Colours</dt><dd><?= h($team['club_colors']) ?></dd><?php endif; ?>
    <?php if ($team['coach_name']): ?><dt>Coach</dt><dd><?= h($team['coach_name']) ?></dd><?php endif; ?>
    <?php if ($team['website']): ?><dt>Website</dt><dd><a href="<?= h($team['website']) ?>" target="_blank" rel="noopener"><?= h($team['website']) ?></a></dd><?php endif; ?>
</dl>

<?php if (!empty($upcoming)): ?>
<section class="league-block">
    <h2 class="league-block__title">Upcoming</h2>
    <div class="fixture-list">
        <?php foreach ($upcoming as $match): ?>
            <?php require __DIR__ . '/partials/match_row.php'; ?>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<?php if (!empty($played)): ?>
<section class="league-block">
    <h2 class="league-block__title">Recent results</h2>
    <div class="fixture-list">
        <?php foreach ($played as $match): ?>
            <?php require __DIR__ . '/partials/match_row.php'; ?>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<?php if (empty($upcoming) && empty($played)): ?>
    <p class="empty-state">No matches synced for this team yet.</p>
<?php endif; ?>

<?php require __DIR__ . '/partials/footer.php'; ?>
