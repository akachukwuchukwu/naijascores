<?php
declare(strict_types=1);

final class ApiFootballClient
{
    private string $baseUrl;
    private string $token;
    private string $cacheDir;

    public function __construct(array $config)
    {
        $this->baseUrl  = rtrim($config['base_url'], '/');
        $this->token    = $config['token'];
        $this->cacheDir = __DIR__ . '/../storage/cache';
        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0775, true);
        }
    }

    /**
     * Searches leagues/cups by name — used by bin/lookup_league.php to find
     * the correct numeric league ID before configuring a new primary-sourced
     * competition. Not cached; this is a one-off lookup, not a hot path.
     */
    public function searchLeagues(string $query): array
    {
        return $this->request('/leagues', ['search' => $query], 0);
    }

    /**
     * Fixtures for one league+season+date range — used to sync a competition
     * where API-Football IS the primary source (not just enrichment).
     */
    public function getFixturesByLeague(int $leagueId, int $season, string $from, string $to): array
    {
        return $this->request('/fixtures', [
            'league' => $leagueId,
            'season' => $season,
            'from'   => $from,
            'to'     => $to,
        ], 0);
    }

    /** Standings for one league+season — used for primary-sourced competitions. */
    public function getStandingsByLeague(int $leagueId, int $season): array
    {
        return $this->request('/standings', ['league' => $leagueId, 'season' => $season], 0);
    }

    /**
     * All fixtures across every league for one date, in a single call.
     * Cached for an hour — this is what lets fixture-ID resolution stay cheap
     * against the free tier's 100 requests/day.
     */
    public function getFixturesForDate(string $date): array
    {
        return $this->request('/fixtures', ['date' => $date], 3600);
    }

    /**
     * Goal/card/substitution timeline for one fixture.
     * Cached briefly — long enough to avoid hammering quota on repeat page
     * views, short enough to stay reasonably current for a live match.
     */
    public function getFixtureEvents(int $fixtureId): array
    {
        return $this->request('/fixtures/events', ['fixture' => $fixtureId], 90);
    }

    /**
     * Confirmed starting XI, substitutes, formation and coach for both teams.
     * Empty before lineups are confirmed (typically 60-75 min pre-kickoff).
     * Cached briefly since this can change right up to kickoff.
     */
    public function getLineups(int $fixtureId): array
    {
        return $this->request('/fixtures/lineups', ['fixture' => $fixtureId], 300);
    }

    /**
     * API-Football's own algorithmic pre-match forecast (winner, over/under,
     * predicted scoreline, comparative team stats). Only meaningful before
     * kickoff, so cached longer — it won't change once the match starts.
     */
    public function getPredictions(int $fixtureId): array
    {
        return $this->request('/predictions', ['fixture' => $fixtureId], 3600);
    }

    private function request(string $path, array $query, int $cacheTtlSeconds = 0): array
    {
        $url = $this->baseUrl . $path;
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }

        $cacheFile = $cacheTtlSeconds > 0
            ? $this->cacheDir . '/af_' . md5($url) . '.json'
            : null;

        if ($cacheFile !== null && is_file($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTtlSeconds) {
            $cached = json_decode((string) file_get_contents($cacheFile), true);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ["x-apisports-key: {$this->token}"],
            CURLOPT_TIMEOUT        => 15,
        ]);

        $body       = curl_exec($ch);
        $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error      = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new RuntimeException("Request to {$url} failed: {$error}");
        }

        if ($httpStatus === 429) {
            throw new RuntimeException('API-Football rate limit hit (HTTP 429) — free tier is 100 requests/day.');
        }

        if ($httpStatus >= 400) {
            throw new RuntimeException("API-Football returned HTTP {$httpStatus} for {$url}: {$body}");
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException("Unexpected response body from {$url}");
        }

        // API-Football returns HTTP 200 even for quota/param errors — the
        // actual error lives in the 'errors' field of the body.
        if (!empty($decoded['errors'])) {
            $message = is_array($decoded['errors']) ? json_encode($decoded['errors']) : (string) $decoded['errors'];
            throw new RuntimeException("API-Football error: {$message}");
        }

        if ($cacheFile !== null) {
            @file_put_contents($cacheFile, json_encode($decoded));
        }

        return $decoded;
    }
}
