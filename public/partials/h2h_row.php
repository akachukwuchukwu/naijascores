<?php
/** @var array $row expected: a raw match object as returned by the football-data.org API */
$homeScore = $row['score']['fullTime']['home'] ?? null;
$awayScore = $row['score']['fullTime']['away'] ?? null;
$scoreKnown = $homeScore !== null;
$kickoffLabel = (new DateTimeImmutable($row['utcDate']))->format('d M Y');
$compCode = $row['competition']['code'] ?? '';
?>
<div class="fixture fixture--static">
    <span class="fixture__time">
        <?= h($kickoffLabel) ?>
        <?php if ($compCode): ?><small><?= h($compCode) ?></small><?php endif; ?>
    </span>

    <span class="fixture__teams">
        <span class="fixture__row">
            <span class="fixture__crest">
                <?php if (!empty($row['homeTeam']['crest'])): ?>
                    <img src="<?= h($row['homeTeam']['crest']) ?>" alt="" loading="lazy" onerror="this.remove()">
                <?php endif; ?>
                <span class="fixture__crest-fallback"><?= h(substr($row['homeTeam']['tla'] ?? $row['homeTeam']['name'], 0, 1)) ?></span>
            </span>
            <span class="fixture__team-name"><?= h($row['homeTeam']['name']) ?></span>
            <span class="fixture__score"><?= $scoreKnown ? (int) $homeScore : '-' ?></span>
        </span>
        <span class="fixture__row">
            <span class="fixture__crest">
                <?php if (!empty($row['awayTeam']['crest'])): ?>
                    <img src="<?= h($row['awayTeam']['crest']) ?>" alt="" loading="lazy" onerror="this.remove()">
                <?php endif; ?>
                <span class="fixture__crest-fallback"><?= h(substr($row['awayTeam']['tla'] ?? $row['awayTeam']['name'], 0, 1)) ?></span>
            </span>
            <span class="fixture__team-name"><?= h($row['awayTeam']['name']) ?></span>
            <span class="fixture__score"><?= $scoreKnown ? (int) $awayScore : '-' ?></span>
        </span>
    </span>
</div>
