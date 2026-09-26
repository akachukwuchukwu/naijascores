<?php
declare(strict_types=1);

final class Queries
{
    public function __construct(private PDO $db)
    {
    }

    public function competitions(): array
    {
        return $this->db->query(
            'SELECT * FROM competitions ORDER BY sort_order'
        )->fetchAll();
    }

    /** Matches for a given date across one or all competitions, home/away teams joined. */
    public function matchesForDate(string $date, ?string 
$competitionCode = null): array
    {
        $sql = 'SELECT m.*,
                       ht.name AS home_name, ht.crest_url AS home_crest, ht.tla AS home_tla,
                       at.name AS away_name, at.crest_url AS away_crest, at.tla AS away_tla,
                       c.name AS competition_name
                FROM matches m
                JOIN teams ht ON ht.id = m.home_team_id
                JOIN teams at ON at.id = m.away_team_id
                JOIN competitions c ON c.code = m.competition_code
                WHERE DATE(m.utc_kickoff) = :date';
        $params = ['date' => $date];

        if ($competitionCode !== null) {
            $sql .= ' AND m.competition_code = :code';
            $params['code'] = $competitionCode;
        }

        $sql .= ' ORDER BY m.competition_code, m.utc_kickoff';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Standings grouped by group_label: ['TOTAL' => [...rows]] for a normal league,
     * or ['Group A' => [...], 'Group B' => [...]] for a cup in group stage.
     */
    public function standingsFor(string $competitionCode, int $seasonStartYear): array
    {
        $stmt = $this->db->prepare(
            'SELECT s.*, t.name, t.short_name, t.tla, t.crest_url
             FROM standings s
             JOIN teams t ON t.id = s.team_id
             WHERE s.competition_code = :code AND s.season_start_year = :season
             ORDER BY s.group_label, s.position'
        );
        $stmt->execute(['code' => $competitionCode, 'season' => $seasonStartYear]);

        $grouped = [];
        foreach ($stmt->fetchAll() as $row) {
            $grouped[$row['group_label']][] = $row;
        }
        return $grouped;
    }

    /** A team's matches already in our DB — recent results and upcoming fixtures, no extra API call. */
    public function matchesForTeam(int $teamId, int $limit = 10): array
    {
        $stmt = $this->db->prepare(
            'SELECT m.*,
                    ht.name AS home_name, ht.crest_url AS home_crest, ht.tla AS home_tla,
                    at.name AS away_name, at.crest_url AS away_crest, at.tla AS away_tla,
                    c.name AS competition_name
             FROM matches m
             JOIN teams ht ON ht.id = m.home_team_id
             JOIN teams at ON at.id = m.away_team_id
             JOIN competitions c ON c.code = m.competition_code
             WHERE m.home_team_id = :id OR m.away_team_id = :id2
             ORDER BY m.utc_kickoff DESC
             LIMIT :lim'
        );
        $stmt->bindValue('id', $teamId, PDO::PARAM_INT);
        $stmt->bindValue('id2', $teamId, PDO::PARAM_INT);
        $stmt->bindValue('lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /** Simple team name search over whatever teams we already have synced. */
    public function searchTeams(string $query, int $limit = 20): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM teams WHERE name LIKE :q1 OR short_name LIKE :q2 OR tla LIKE :q3 ORDER BY name LIMIT :lim'
        );
        $like = '%' . $query . '%';
        $stmt->bindValue('q1', $like, PDO::PARAM_STR);
        $stmt->bindValue('q2', $like, PDO::PARAM_STR);
        $stmt->bindValue('q3', $like, PDO::PARAM_STR);
        $stmt->bindValue('lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function teamById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM teams WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** Past meetings between two teams, most recent first, built from whatever the DB already has. */
    public function headToHead(int $teamAId, int $teamBId, int $limit = 6): array
    {
        $stmt = $this->db->prepare(
            'SELECT m.*,
                    ht.name AS home_name, ht.crest_url AS home_crest, ht.tla AS home_tla,
                    at.name AS away_name, at.crest_url AS away_crest, at.tla AS away_tla,
                    c.name AS competition_name
             FROM matches m
             JOIN teams ht ON ht.id = m.home_team_id
             JOIN teams at ON at.id = m.away_team_id
             JOIN competitions c ON c.code = m.competition_code
             WHERE m.status = \'FINISHED\'
               AND ((m.home_team_id = :a AND m.away_team_id = :b)
                 OR (m.home_team_id = :b2 AND m.away_team_id = :a2))
             ORDER BY m.utc_kickoff DESC
             LIMIT :lim'
        );
        $stmt->bindValue('a', $teamAId, PDO::PARAM_INT);
        $stmt->bindValue('b', $teamBId, PDO::PARAM_INT);
        $stmt->bindValue('a2', $teamAId, PDO::PARAM_INT);
        $stmt->bindValue('b2', $teamBId, PDO::PARAM_INT);
        $stmt->bindValue('lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function matchById(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT m.*,
                    ht.name AS home_name, ht.crest_url AS home_crest, ht.tla AS home_tla,
                    at.name AS away_name, at.crest_url AS away_crest, at.tla AS away_tla,
                    c.name AS competition_name
             FROM matches m
             JOIN teams ht ON ht.id = m.home_team_id
             JOIN teams at ON at.id = m.away_team_id
             JOIN competitions c ON c.code = m.competition_code
             WHERE m.id = :id'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }
}

function status_label(string $status): string
{
    return match ($status) {
        'IN_PLAY', 'PAUSED' => 'LIVE',
        'FINISHED'          => 'FT',
        'SCHEDULED', 'TIMED' => 'Upcoming',
        'POSTPONED'         => 'Postponed',
        'SUSPENDED'         => 'Suspended',
        'CANCELLED'         => 'Cancelled',
        'AWARDED'           => 'Awarded',
        default             => $status,
    };
}

function is_live(string $status): bool
{
    return in_array($status, ['IN_PLAY', 'PAUSED'], true);
}

function kickoff_time(string $utcDatetime): string
{
    $dt = new DateTimeImmutable($utcDatetime, new DateTimeZone('UTC'));
    return $dt->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('H:i');
}
