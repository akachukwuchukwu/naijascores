<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

$date = $_GET['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $date = date('Y-m-d');
}

$prevDate = (new DateTimeImmutable($date))->modify('-1 day')->format('Y-m-d');
$nextDate = (new DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d');

$matches = $queries->matchesForDate($date);
$byCompetition = [];
foreach ($matches as $m) {
    $byCompetition[$m['competition_code']][] = $m;
}

$hasLiveMatch = false;
$liveCount = 0;
foreach ($matches as $m) {
    if (is_live($m['status'])) { $hasLiveMatch = true; $liveCount++; }
}

$pageTitle = 'Fixtures';
$autoRefreshSeconds = $hasLiveMatch ? 60 : null;
require __DIR__ . '/partials/header.php';
?>

<div class="date-strip">
    <a href="/?date=<?= h($prevDate) ?>" class="date-strip__arrow" aria-label="Previous day">&lsaquo;</a>
    <div class="date-strip__center">
        <?php if ($liveCount > 0): ?><span class="live-badge"><?= $liveCount ?> LIVE</span><?php endif; ?>
        <h1 class="date-strip__current"><?= h((new DateTimeImmutable($date))->format('l j F')) ?></h1>
    </div>
    <a href="/?date=<?= h($nextDate) ?>" class="date-strip__arrow" aria-label="Next day">&rsaquo;</a>
</div>

<div class="myteams-toggle">
    <button type="button" id="myteams-toggle-btn">My Teams</button>
</div>

<?php if (empty($byCompetition)): ?>
    <p class="empty-state">No fixtures across any tracked competition on this date.</p>
<?php endif; ?>

<?php foreach ($queries->competitions() as $comp): ?>
    <?php if (empty($byCompetition[$comp['code']])) continue; ?>
    <section class="league-block">
        <h2 class="league-block__title">
            <?= h($comp['name']) ?> <span class="league-block__country"><?= h($comp['country']) ?></span>
        </h2>
        <div class="fixture-list">
            <?php foreach ($byCompetition[$comp['code']] as $match): ?>
                <?php require __DIR__ . '/partials/match_row.php'; ?>
            <?php endforeach; ?>
        </div>
    </section>
<?php endforeach; ?>

<?php require __DIR__ . '/partials/footer.php'; ?>
