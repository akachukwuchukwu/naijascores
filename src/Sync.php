<?php
declare(strict_types=1);

final class Sync
{
    public function __construct(
        private PDO $db,
        private FootballDataClient $client,
        private array $competitionCodes
    ) {
    }

    /** Pulls fixtures/scores for all configured competitions in ONE API call. */
    public function syncMatches(int $daysBack, int $daysAhead): int
    {
        $dateFrom = (new DateTimeImmutable("-{$daysBack} days"))->format('Y-m-d');
        $dateTo   = (new DateTimeImmutable("+{$daysAhead} days"))->format('Y-m-d');

        $matches = $this->client->getMatches($this->competitionCodes, $dateFrom, $dateTo);

        $this->db->beginTransaction();
        try {
            foreach ($matches as $match) {
                $this->upsertTeam($match['homeTeam']);
                $this->upsertTeam($match['awayTeam']);
                $this->upsertMatch($match);
            }
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return count($matches);
    }

    /** Pulls the league table(s) for one competition — one call, but may store multiple groups. */
    public function syncStandings(string $competitionCode, int $seasonStartYear): int
    {
        $tables = $this->client->getStandings($competitionCode);

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
        foreach ($tables as $table) {
            $type = $table['type'] ?? '';
            // Skip HOME/AWAY split tables that leagues also return — we only want
            // the overall table for a normal league, or each group for a cup.
            if (!in_array($type, ['TOTAL', 'GROUP'], true)) {
                continue;
            }
            $groupLabel = $type === 'GROUP' ? ($table['group'] ?? 'Group') : 'TOTAL';

            foreach ($table['table'] ?? [] as $row) {
                $this->upsertTeam($row['team']);
                $stmt->execute([
                    'code'     => $competitionCode,
                    'season'   => $seasonStartYear,
                    'group'    => $groupLabel,
                    'team_id'  => $row['team']['id'],
                    'position' => $row['position'],
                    'played'   => $row['playedGames'],
                    'won'      => $row['won'],
                    'draw'     => $row['draw'],
                    'lost'     => $row['lost'],
                    'points'   => $row['points'],
                    'gf'       => $row['goalsFor'],
                    'ga'       => $row['goalsAgainst'],
                    'gd'       => $row['goalDifference'],
                    'form'     => $row['form'] ?? null,
                ]);
                $rowCount++;
            }
        }

        return $rowCount;
    }

    /** Pulls a team's profile (founded, venue, colours, coach) — one API call, then cached in the DB. */
    public function syncTeamProfile(int $teamId): void
    {
        $team = $this->client->getTeam($teamId);

        $stmt = $this->db->prepare(
            'UPDATE teams SET
                name = :name, short_name = :short_name, tla = :tla, crest_url = :crest,
                founded = :founded, venue = :venue, club_colors = :colors,
                website = :website, coach_name = :coach, address = :address,
                profile_synced_at = NOW()
             WHERE id = :id'
        );

        $stmt->execute([
            'id'         => $teamId,
            'name'       => $team['name'],
            'short_name' => $team['shortName'] ?? null,
            'tla'        => $team['tla'] ?? null,
            'crest'      => $team['crest'] ?? null,
            'founded'    => $team['founded'] ?? null,
            'venue'      => $team['venue'] ?? null,
            'colors'     => $team['clubColors'] ?? null,
            'website'    => $team['website'] ?? null,
            'coach'      => $team['coach']['name'] ?? null,
            'address'    => $team['address'] ?? null,
        ]);
    }

    /**
     * Pulls a team's recent finished matches into the DB. Used on-demand when
     * a visitor opens a match's H2H tab and we don't have enough local history
     * for that pairing yet. Costs one API call.
     */
    public function syncTeamHistory(int $teamId, int $limit = 50): int
    {
        $matches = $this->client->getTeamMatches($teamId, $limit);

        $this->db->beginTransaction();
        try {
            foreach ($matches as $match) {
                // Only competitions we actually track have full team/competition rows to join against.
                if (!in_array($match['competition']['code'], $this->competitionCodes, true)) {
                    continue;
                }
                $this->upsertTeam($match['homeTeam']);
                $this->upsertTeam($match['awayTeam']);
                $this->upsertMatch($match);
            }
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return count($matches);
    }

    private function upsertTeam(array $team): void
    {
        static $stmt = null;
        $stmt ??= $this->db->prepare(
            'INSERT INTO teams (id, name, short_name, tla, crest_url)
             VALUES (:id, :name, :short_name, :tla, :crest)
             ON DUPLICATE KEY UPDATE
                name = VALUES(name), short_name = VALUES(short_name),
                tla = VALUES(tla), crest_url = VALUES(crest_url)'
        );

        $stmt->execute([
            'id'         => $team['id'],
            'name'       => $team['name'],
            'short_name' => $team['shortName'] ?? null,
            'tla'        => $team['tla'] ?? null,
            'crest'      => $team['crest'] ?? null,
        ]);
    }

    private function upsertMatch(array $match): void
    {
        static $stmt = null;
        $stmt ??= $this->db->prepare(
            'INSERT INTO matches
                (id, competition_code, season_start_year, matchday, utc_kickoff, status,
                 home_team_id, away_team_id, home_score, away_score,
                 home_ht_score, away_ht_score, winner, venue, last_synced_at)
             VALUES
                (:id, :comp, :season, :matchday, :kickoff, :status,
                 :home_id, :away_id, :home_score, :away_score,
                 :home_ht, :away_ht, :winner, :venue, NOW())
             ON DUPLICATE KEY UPDATE
                status = VALUES(status), home_score = VALUES(home_score), away_score = VALUES(away_score),
                home_ht_score = VALUES(home_ht_score), away_ht_score = VALUES(away_ht_score),
                winner = VALUES(winner), matchday = VALUES(matchday), last_synced_at = NOW()'
        );

        $score  = $match['score'];
        $kickoff = (new DateTimeImmutable($match['utcDate']))->format('Y-m-d H:i:s');

        $stmt->execute([
            'id'         => $match['id'],
            'comp'       => $match['competition']['code'],
            'season'     => (int) substr($match['season']['startDate'], 0, 4),
            'matchday'   => $match['matchday'] ?? null,
            'kickoff'    => $kickoff,
            'status'     => $match['status'],
            'home_id'    => $match['homeTeam']['id'],
            'away_id'    => $match['awayTeam']['id'],
            'home_score' => $score['fullTime']['home'] ?? null,
            'away_score' => $score['fullTime']['away'] ?? null,
            'home_ht'    => $score['halfTime']['home'] ?? null,
            'away_ht'    => $score['halfTime']['away'] ?? null,
            'winner'     => $score['winner'] ?? null,
            'venue'      => $match['venue'] ?? null,
        ]);
    }
}
