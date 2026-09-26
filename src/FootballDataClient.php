<?php
declare(strict_types=1);

final class FootballDataClient
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
     * Fetch matches across one or more competitions in a single call.
     * This is what keeps the free tier's 10 req/min limit comfortable:
     * 4 leagues == 1 request instead of 4.
     */
    public function getMatches(array $competitionCodes, string $dateFrom, string $dateTo): array
    {
        return $this->request('/matches', [
            'competitions' => implode(',', $competitionCodes),
            'dateFrom'     => $dateFrom,
            'dateTo'       => $dateTo,
        ])['matches'] ?? [];
    }

    /**
     * All standings tables for a competition. Leagues return one 'TOTAL' table;
     * cup competitions in group stage (Champions League, World Cup, Euros)
     * return one table per group instead.
     */
    public function getStandings(string $competitionCode): array
    {
        $data = $this->request("/competitions/{$competitionCode}/standings", []);
        return $data['standings'] ?? [];
    }

    /** A team's own recent finished matches — used to derive head-to-head history locally. */
    public function getTeamMatches(int $teamId, int $limit = 50): array
    {
        return $this->request("/teams/{$teamId}/matches", [
            'status' => 'FINISHED',
            'limit'  => $limit,
        ])['matches'] ?? [];
    }

    /**
     * Head-to-head aggregate + recent meetings for the two teams in a match.
     * Cached for 15 minutes — this aggregate barely changes within that window.
     */
    public function getHeadToHead(int $matchId, int $lastN = 10): array
    {
        return $this->request("/matches/{$matchId}", ['head2head' => $lastN], 900)['head2head'] ?? [];
    }

    /**
     * Top scorers for a competition's current season.
     * Cached for 15 minutes.
     */
    public function getScorers(string $competitionCode, int $limit = 15): array
    {
        return $this->request("/competitions/{$competitionCode}/scorers", [
            'limit' => $limit,
        ], 900)['scorers'] ?? [];
    }

    /**
     * Team profile: founded year, venue, colours, coach, website.
     * Cached for 24 hours — this data changes rarely.
     */
    public function getTeam(int $teamId): array
    {
        return $this->request("/teams/{$teamId}", [], 86400);
    }

    private function request(string $path, array $query, int $cacheTtlSeconds = 0): array
    {
        $url = $this->baseUrl . $path;
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }

        $cacheFile = $cacheTtlSeconds > 0
            ? $this->cacheDir . '/' . md5($url) . '.json'
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
            CURLOPT_HTTPHEADER     => ["X-Auth-Token: {$this->token}"],
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
            throw new RuntimeException('football-data.org rate limit hit (HTTP 429). Slow down the sync interval.');
        }

        if ($httpStatus >= 400) {
            throw new RuntimeException("football-data.org returned HTTP {$httpStatus} for {$url}: {$body}");
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException("Unexpected response body from {$url}");
        }

        if ($cacheFile !== null) {
            @file_put_contents($cacheFile, json_encode($decoded));
        }

        return $decoded;
    }
}
