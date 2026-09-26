<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

$q = trim($_GET['q'] ?? '');
$results = $q !== '' ? $queries->searchTeams($q) : [];

$pageTitle = 'Search';
require __DIR__ . '/partials/header.php';
?>

<div class="competition-heading">
    <h1>Search</h1>
    <p class="competition-heading__country">Find a team you've already seen synced in this app</p>
</div>

<form class="search-form" action="/search.php" method="get">
    <input type="text" name="q" value="<?= h($q) ?>" placeholder="Team name…" autofocus>
    <button type="submit">Search</button>
</form>

<?php if ($q === ''): ?>
    <p class="empty-state">Type a team name above to search.</p>
<?php elseif (empty($results)): ?>
    <p class="empty-state">
        No teams matching "<?= h($q) ?>" found in synced data.
        Only teams that have appeared in already-synced fixtures or standings are searchable.
    </p>
<?php else: ?>
    <div class="search-results">
        <?php foreach ($results as $team): ?>
        <a href="/team.php?id=<?= (int) $team['id'] ?>" class="search-result">
            <span class="fixture__crest" style="width:28px;height:28px;">
                <?php if ($team['crest_url']): ?>
                    <img src="<?= h($team['crest_url']) ?>" alt="" loading="lazy" onerror="this.remove()">
                <?php endif; ?>
                <span class="fixture__crest-fallback"><?= h(substr($team['tla'] ?? $team['name'], 0, 1)) ?></span>
            </span>
            <span class="fixture__team-name"><?= h($team['name']) ?></span>
        </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/partials/footer.php'; ?>
