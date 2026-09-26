<?php
/** @var array $match expected in scope */
$live = is_live($match['status']);
$finished = $match['status'] === 'FINISHED';
$scoreKnown = $match['home_score'] !== null;
?>
<a href="/match.php?id=<?= (int) $match['id'] ?>"
   class="fixture <?= $live ? 'fixture--live' : '' ?>"
   data-home-id="<?= (int) $match['home_team_id'] ?>"
   data-away-id="<?= (int) $match['away_team_id'] ?>">
    <span class="fixture__time">
        <?php if ($live): ?>
            <span class="live-dot" aria-hidden="true"></span>LIVE
        <?php elseif ($finished): ?>
            FT
        <?php else: ?>
            <?= h(kickoff_time($match['utc_kickoff'])) ?>
        <?php endif; ?>
    </span>

    <span class="fixture__teams">
        <span class="fixture__row">
            <span class="fixture__crest">
                <?php if ($match['home_crest']): ?>
                    <img src="<?= h($match['home_crest']) ?>" alt="" loading="lazy" onerror="this.remove()">
                <?php endif; ?>
                <span class="fixture__crest-fallback"><?= h(substr($match['home_tla'] ?? $match['home_name'], 0, 1)) ?></span>
            </span>
            <span class="fixture__team-name"><?= h($match['home_name']) ?></span>
            <span class="fixture__score"><?= $scoreKnown ? (int) $match['home_score'] : '-' ?></span>
        </span>
        <span class="fixture__row">
            <span class="fixture__crest">
                <?php if ($match['away_crest']): ?>
                    <img src="<?= h($match['away_crest']) ?>" alt="" loading="lazy" onerror="this.remove()">
                <?php endif; ?>
                <span class="fixture__crest-fallback"><?= h(substr($match['away_tla'] ?? $match['away_name'], 0, 1)) ?></span>
            </span>
            <span class="fixture__team-name"><?= h($match['away_name']) ?></span>
            <span class="fixture__score"><?= $scoreKnown ? (int) $match['away_score'] : '-' ?></span>
        </span>
    </span>
</a>
