<?php
declare(strict_types=1);

/**
 * Resolves a football-data.org match to its API-Football fixture ID by
 * matching kickoff date + team names — the two providers use unrelated ID
 * schemes, so this is the only reliable link between them. Result is cached
 * in matches.api_football_fixture_id so this lookup runs at most once per
 * match, no matter how many enrichment features (events, lineups,
 * predictions) end up using it.
 */
final class FixtureResolver
{
    public function __construct(
        private PDO $db,
        private ApiFootballClient $client
    ) {
    }

    public function resolve(array $match): ?int
    {
        $cached = $match['api_football_fixture_id'] ?? null;
        if ($cached !== null) {
            return (int) $cached;
        }

        $date = (new DateTimeImmutable($match['utc_kickoff']))->format('Y-m-d');

        try {
            $fixtures = $this->client->getFixturesForDate($date)['response'] ?? [];
        } catch (Throwable $e) {
            return null; // secondary source unavailable — degrade silently
        }

        $localHome = self::normalize($match['home_name']);
        $localAway = self::normalize($match['away_name']);

        $fallback = null;
        foreach ($fixtures as $fixture) {
            $apiHome = self::normalize($fixture['teams']['home']['name'] ?? '');
            $apiAway = self::normalize($fixture['teams']['away']['name'] ?? '');

            if ($apiHome === $localHome && $apiAway === $localAway) {
                $id = (int) $fixture['fixture']['id'];
                $this->cache((int) $match['id'], $id);
                return $id;
            }

            // Loose fallback for name variants (e.g. "Man United" vs "Manchester United").
            if ($fallback === null
                && (str_contains($apiHome, $localHome) || str_contains($localHome, $apiHome))
                && (str_contains($apiAway, $localAway) || str_contains($localAway, $apiAway))
            ) {
                $fallback = (int) $fixture['fixture']['id'];
            }
        }

        if ($fallback !== null) {
            $this->cache((int) $match['id'], $fallback);
        }

        return $fallback;
    }

    private function cache(int $matchId, int $fixtureId): void
    {
        $stmt = $this->db->prepare(
            'UPDATE matches SET api_football_fixture_id = :fid, events_synced_at = NOW() WHERE id = :id'
        );
        $stmt->execute(['fid' => $fixtureId, 'id' => $matchId]);
    }

    private static function normalize(string $name): string
    {
        $name = strtolower($name);
        $name = (string) preg_replace('/\b(fc|cf|afc|sc|ac|cd|ud|rc|ca)\b/u', '', $name);
        $name = (string) preg_replace('/[^a-z0-9]+/u', ' ', $name);
        return trim((string) preg_replace('/\s+/', ' ', $name));
    }
}
