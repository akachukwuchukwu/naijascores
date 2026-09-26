<?php
declare(strict_types=1);

/**
 * Enriches a football-data.org match with goal-event detail (who scored,
 * when, assisted by whom) pulled from API-Football. football-data.org stays
 * the system of record for scores/fixtures/standings — this class only ever
 * adds detail on top, and degrades to "no events" if anything about the
 * secondary source fails.
 */
final class MatchEventsService
{
    public function __construct(
        private ApiFootballClient $client,
        private FixtureResolver $resolver
    ) {
    }

    /**
     * @param array $match A row from Queries::matchById() / matchesForDate() etc.
     * @return array<int, array{minute: string, side: string, player: string, assist: ?string, type: string}>
     */
    public function getGoalEvents(array $match): array
    {
        // Events only exist once a match has actually kicked off.
        if (!in_array($match['status'], ['IN_PLAY', 'PAUSED', 'FINISHED'], true)) {
            return [];
        }

        $fixtureId = $this->resolver->resolve($match);
        if ($fixtureId === null) {
            return [];
        }

        $events = $this->client->getFixtureEvents($fixtureId)['response'] ?? [];

        $localHome = self::normalize($match['home_name']);
        $goals = [];

        foreach ($events as $event) {
            if (($event['type'] ?? '') !== 'Goal') {
                continue;
            }
            $detail = $event['detail'] ?? '';
            if (!in_array($detail, ['Normal Goal', 'Penalty', 'Own Goal'], true)) {
                continue; // skip "Missed Penalty" etc — not an actual goal
            }

            $eventTeam = self::normalize($event['team']['name'] ?? '');
            $side = $eventTeam === $localHome ? 'home' : 'away';

            $elapsed = $event['time']['elapsed'] ?? null;
            $extra   = $event['time']['extra'] ?? null;
            $minute  = $elapsed !== null ? $elapsed . ($extra ? "+{$extra}" : '') . "'" : '?';

            $goals[] = [
                'minute' => $minute,
                'sortKey' => ((int) ($elapsed ?? 0)) * 100 + (int) ($extra ?? 0),
                'side'   => $side,
                'player' => $event['player']['name'] ?? 'Unknown',
                'assist' => $event['assist']['name'] ?? null,
                'type'   => $detail,
            ];
        }

        usort($goals, fn($a, $b) => $a['sortKey'] <=> $b['sortKey']);

        return $goals;
    }

    private static function normalize(string $name): string
    {
        $name = strtolower($name);
        $name = (string) preg_replace('/\b(fc|cf|afc|sc|ac|cd|ud|rc|ca)\b/u', '', $name);
        $name = (string) preg_replace('/[^a-z0-9]+/u', ' ', $name);
        return trim((string) preg_replace('/\s+/', ' ', $name));
    }
}
