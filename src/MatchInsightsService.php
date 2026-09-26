<?php
declare(strict_types=1);

/**
 * Two more API-Football enrichments on top of the same resolved fixture:
 * confirmed lineups, and the API's own pre-match prediction. Same rule as
 * MatchEventsService — football-data.org stays the source of truth, this
 * only ever adds detail, and degrades to an empty result if anything fails.
 */
final class MatchInsightsService
{
    public function __construct(
        private ApiFootballClient $client,
        private FixtureResolver $resolver
    ) {
    }

    /**
     * @return array{home: ?array, away: ?array} Each side, if present, has
     *   formation, coach, startXI (name, number, position), substitutes.
     *   Empty arrays mean lineups aren't confirmed yet or aren't available.
     */
    public function getLineups(array $match): array
    {
        $fixtureId = $this->resolver->resolve($match);
        if ($fixtureId === null) {
            return ['home' => null, 'away' => null];
        }

        try {
            $teams = $this->client->getLineups($fixtureId)['response'] ?? [];
        } catch (Throwable $e) {
            return ['home' => null, 'away' => null];
        }

        if (count($teams) < 2) {
            return ['home' => null, 'away' => null]; // not confirmed yet
        }

        $localHome = self::normalize($match['home_name']);
        $result = ['home' => null, 'away' => null];

        foreach ($teams as $team) {
            $side = self::normalize($team['team']['name'] ?? '') === $localHome ? 'home' : 'away';
            $result[$side] = [
                'formation' => $team['formation'] ?? null,
                'coach'     => $team['coach']['name'] ?? null,
                'startXI'   => array_map(fn($p) => [
                    'name'   => $p['player']['name'] ?? '',
                    'number' => $p['player']['number'] ?? null,
                    'pos'    => $p['player']['pos'] ?? null,
                ], $team['startXI'] ?? []),
                'substitutes' => array_map(fn($p) => [
                    'name'   => $p['player']['name'] ?? '',
                    'number' => $p['player']['number'] ?? null,
                    'pos'    => $p['player']['pos'] ?? null,
                ], $team['substitutes'] ?? []),
            ];
        }

        return $result;
    }

    /**
     * @return array{} | array{advice: string, homePct: string, drawPct: string,
     *   awayPct: string, predictedHomeGoals: ?string, predictedAwayGoals: ?string}
     */
    public function getPredictions(array $match): array
    {
        // Only meaningful pre-kickoff.
        if (!in_array($match['status'], ['SCHEDULED', 'TIMED'], true)) {
            return [];
        }

        $fixtureId = $this->resolver->resolve($match);
        if ($fixtureId === null) {
            return [];
        }

        try {
            $data = $this->client->getPredictions($fixtureId)['response'][0] ?? null;
        } catch (Throwable $e) {
            return [];
        }

        if ($data === null) {
            return [];
        }

        $pred = $data['predictions'] ?? [];
        $percent = $pred['percent'] ?? [];
        $goals = $pred['goals'] ?? [];

        if (empty($pred)) {
            return [];
        }

        return [
            'advice'             => $pred['advice'] ?? '',
            'homePct'            => $percent['home'] ?? '—',
            'drawPct'            => $percent['draw'] ?? '—',
            'awayPct'            => $percent['away'] ?? '—',
            'predictedHomeGoals' => $goals['home'] ?? null,
            'predictedAwayGoals' => $goals['away'] ?? null,
        ];
    }

    private static function normalize(string $name): string
    {
        $name = strtolower($name);
        $name = (string) preg_replace('/\b(fc|cf|afc|sc|ac|cd|ud|rc|ca)\b/u', '', $name);
        $name = (string) preg_replace('/[^a-z0-9]+/u', ' ', $name);
        return trim((string) preg_replace('/\s+/', ' ', $name));
    }
}
