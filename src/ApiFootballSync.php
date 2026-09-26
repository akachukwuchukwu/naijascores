<?php
declare(strict_types=1);

/**
 * For competitions football-data.org doesn't cover (e.g. NPFL), API-Football
 * becomes the primary source instead of just an enrichment layer. This class
 * writes into the same matches/teams/standings tables the football-data.org
 * sync uses — so every existing page (index, competition, match, team)
 * works unchanged — but namespaces every ID it writes by config's
 * `id_offset` so they can never collide with football-data.org's IDs, and
 * translates API-Football's status codes into our shared status enum.
 */
final class ApiFootballSync
{
    public function __construct(
        private PDO $db,
        private ApiFootballClient $client,
        private int $idOffset
    ) {
    }

    /** Pulls fixtures/scores for one competition + date range. Returns rows synced. */
    public function syncFixtures(string $competitionCode, int $leagueId, int $season, string $dateFrom, string $dateTo): int
    {
        $fixtures = $this->client->getFixturesByLeague($leagueId, $season, $dateFrom, $dateTo)['response'] ?? [];

        $this->db->beginTransaction();
        try {
            foreach ($fixtures as $fixture) {
                $this->upsertTeam($fixture['teams']['home']);
                $this->upsertTeam($fixture['teams']['away']);
                $this->upsertMatch($competitionCode, $season, $fixture);
            }
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return count($fixtures);
    }

    /** Pulls the league table for one competition + season. Returns rows synced. */
    public function syncStandings(string $competitionCode, int $leagueId, int $season): int
    {
        $data = $this->client->getStandingsByLeague($leagueId, $season)['response'] ?? [];
        $tables = $data[0]['league']['standings'] ?? [];

        $stmt = $this->db->prepare(
            'INSERT INTO standings
                (competition_code, season_start_year, group_label, team_id, position, played_games,
                 won, draw, lost, points, goals_for, goals_against, goal_difference, form, updated_at)
             VALUES
                (:code, :season, :group, :team_id, :position, :played,
                 :won, :draw, :lost, :points, :gf, :ga, :gd, :form, NOW())
             ON DUPLICATE KEY UPDATE
                position = VALUES(position), played_games = VALUES(played_games),
                won = VALUES(won), draw = VALUES(draw), lost = VALUES(lost),
                points = VALUES(points), goals_for = VALUES(goals_for),
                goals_against = VALUES(goals_against), goal_difference = VALUES(goal_difference),
                form = VALUES(form), updated_at = NOW()'
        );

        $rowCount = 0;
        // API-Football returns one array of groups; a normal league has one
        // group (the whole table), while group-stage cups have several.
        foreach ($tables as $groupIndex => $group) {
            $groupLabel = count($tables) > 1 ? 'Group ' . chr(65 + $groupIndex) : 'TOTAL';

            foreach ($group as $row) {
                $teamId = $this->idOffset + (int) $row['team']['id'];
                $this->upsertTeam($row['team']);

                $stmt->execute([
                    'code'     => $competitionCode,
                    'season'   => $season,
                    'group'    => $groupLabel,
                    'team_id'  => $teamId,
                    'position' => $row['rank'],
                    'played'   => $row['all']['played'],
                    'won'      => $row['all']['win'],
                    'draw'     => $row['all']['draw'],
                    'lost'     => $row['all']['lose'],
                    'points'   => $row['points'],
                    'gf'       => $row['all']['goals']['for'],
                    'ga'       => $row['all']['goals']['against'],
                    'gd'       => $row['goalsDiff'],
                    'form'     => $row['form'] ?? null,
                ]);
                $rowCount++;
            }
        }

        return $rowCount;
    }

    private function upsertTeam(array $team): void
    {
        static $stmt = null;
        $stmt ??= $this->db->prepare(
            'INSERT INTO teams (id, name, short_name, tla, crest_url, data_source)
             VALUES (:id, :name, :short_name, :tla, :crest, \'api_football\')
             ON DUPLICATE KEY UPDATE name = VALUES(name), crest_url = VALUES(crest_url)'
        );

        $stmt->execute([
            'id'         => $this->idOffset + (int) $team['id'],
            'name'       => $team['name'],
            'short_name' => null,
            'tla'        => null,
            'crest'      => $team['logo'] ?? null,
        ]);
    }

    private function upsertMatch(string $competitionCode, int $season, array $fixture): void
    {
        static $stmt = null;
        $stmt ??= $this->db->prepare(
            'INSERT INTO matches
                (id, competition_code, season_start_year, matchday, utc_kickoff, status,
                 home_team_id, away_team_id, home_score, away_score,
                 home_ht_score, away_ht_score, winner, venue, last_synced_at,
                 api_football_fixture_id, events_synced_at)
             VALUES
                (:id, :comp, :season, :matchday, :kickoff, :status,
                 :home_id, :away_id, :home_score, :away_score,
                 :home_ht, :away_ht, :winner, :venue, NOW(),
                 :af_id, NOW())
             ON DUPLICATE KEY UPDATE
                status = VALUES(status), home_score = VALUES(home_score), away_score = VALUES(away_score),
                home_ht_score = VALUES(home_ht_score), away_ht_score = VALUES(away_ht_score),
                winner = VALUES(winner), matchday = VALUES(matchday), last_synced_at = NOW()'
        );

        $rawFixtureId = (int) $fixture['fixture']['id'];
        $home = $fixture['teams']['home'];
        $away = $fixture['teams']['away'];

        $winner = null;
        if ($home['winner'] === true) $winner = 'HOME_TEAM';
        elseif ($away['winner'] === true) $winner = 'AWAY_TEAM';
        elseif (self::isFinished($fixture['fixture']['status']['short']) && $home['winner'] === null && $away['winner'] === null) {
            $winner = 'DRAW';
        }

        $stmt->execute([
            'id'         => $this->idOffset + $rawFixtureId,
            'comp'       => $competitionCode,
            'season'     => $season,
            'matchday'   => self::extractMatchday($fixture['league']['round'] ?? ''),
            'kickoff'    => (new DateTimeImmutable($fixture['fixture']['date']))->format('Y-m-d H:i:s'),
            'status'     => self::mapStatus($fixture['fixture']['status']['short']),
            'home_id'    => $this->idOffset + (int) $home['id'],
            'away_id'    => $this->idOffset + (int) $away['id'],
            'home_score' => $fixture['goals']['home'],
            'away_score' => $fixture['goals']['away'],
            'home_ht'    => $fixture['score']['halftime']['home'] ?? null,
            'away_ht'    => $fixture['score']['halftime']['away'] ?? null,
            'winner'     => $winner,
            'venue'      => $fixture['fixture']['venue']['name'] ?? null,
            'af_id'      => $rawFixtureId, // raw (non-offset) — used to call API-Football's own endpoints
        ]);
    }

    /** Translates API-Football's short status codes into our shared status enum. */
    private static function mapStatus(string $short): string
    {
        return match ($short) {
            'NS', 'TBD'                    => 'SCHEDULED',
            'HT'                            => 'PAUSED',
            '1H', '2H', 'ET', 'BT', 'P', 'LIVE' => 'IN_PLAY',
            'FT', 'AET', 'PEN'              => 'FINISHED',
            'SUSP', 'INT'                   => 'SUSPENDED',
            'PST'                           => 'POSTPONED',
            'CANC', 'ABD'                   => 'CANCELLED',
            'AWD', 'WO'                     => 'AWARDED',
            default                         => 'SCHEDULED',
        };
    }

    private static function isFinished(string $short): bool
    {
        return in_array($short, ['FT', 'AET', 'PEN'], true);
    }

    /** Best-effort matchday number from a round string like "Regular Season - 5". */
    private static function extractMatchday(string $round): ?int
    {
        return preg_match('/(\d+)\s*$/', $round, $m) ? (int) $m[1] : null;
    }
}
