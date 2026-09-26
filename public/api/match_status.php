<?php
declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

header('Content-Type: application/json');

$idsParam = $_GET['ids'] ?? '';
$ids = array_filter(array_map('intval', explode(',', $idsParam)));

if (empty($ids)) {
    echo json_encode([]);
    exit;
}

$placeholders = implode(',', array_fill(0, count($ids), '?'));
$stmt = $db->prepare("SELECT id, status, home_score, away_score FROM matches WHERE id IN ($placeholders)");
$stmt->execute(array_values($ids));

$results = array_map(static function (array $row): array {
    return [
        'id'          => (int) $row['id'],
        'status'      => $row['status'],
        'home_score'  => $row['home_score'] !== null ? (int) $row['home_score'] : null,
        'away_score'  => $row['away_score'] !== null ? (int) $row['away_score'] : null,
    ];
}, $stmt->fetchAll());

echo json_encode($results);
