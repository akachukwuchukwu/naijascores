-- Livescore app schema (MySQL 8)
-- Covers all 12 competitions on football-data.org's free tier.

CREATE DATABASE IF NOT EXISTS livescore CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE livescore;

CREATE TABLE IF NOT EXISTS competitions (
    code        VARCHAR(8)   NOT NULL PRIMARY KEY,   -- PL, PD, BL1, SA, etc.
    name        VARCHAR(120) NOT NULL,
    country     VARCHAR(80)  NOT NULL,
    type        ENUM('LEAGUE','CUP') NOT NULL DEFAULT 'LEAGUE',
    emblem_url  VARCHAR(255) NULL,
    sort_order  TINYINT      NOT NULL DEFAULT 0
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS teams (
    id          INT UNSIGNED NOT NULL PRIMARY KEY,   -- football-data.org id, OR (id_offset + api-football id) — see config.php
    name        VARCHAR(120) NOT NULL,
    short_name  VARCHAR(60)  NULL,
    tla         VARCHAR(8)   NULL,
    crest_url   VARCHAR(255) NULL,
    founded            SMALLINT     NULL,
    venue              VARCHAR(150) NULL,
    club_colors        VARCHAR(100) NULL,
    website            VARCHAR(255) NULL,
    coach_name         VARCHAR(120) NULL,
    address            VARCHAR(255) NULL,
    profile_synced_at  DATETIME     NULL,
    data_source ENUM('football_data','api_football') NOT NULL DEFAULT 'football_data'
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS matches (
    id                  INT UNSIGNED NOT NULL PRIMARY KEY, -- football-data.org match id
    competition_code    VARCHAR(8)   NOT NULL,
    season_start_year   SMALLINT     NOT NULL,
    matchday            SMALLINT     NULL,
    utc_kickoff         DATETIME     NOT NULL,
    status              ENUM('SCHEDULED','TIMED','IN_PLAY','PAUSED','FINISHED',
                              'POSTPONED','SUSPENDED','CANCELLED','AWARDED') NOT NULL,
    home_team_id        INT UNSIGNED NOT NULL,
    away_team_id        INT UNSIGNED NOT NULL,
    home_score          TINYINT UNSIGNED NULL,
    away_score          TINYINT UNSIGNED NULL,
    home_ht_score       TINYINT UNSIGNED NULL,
    away_ht_score       TINYINT UNSIGNED NULL,
    winner              ENUM('HOME_TEAM','AWAY_TEAM','DRAW') NULL,
    venue               VARCHAR(150) NULL,
    last_synced_at      DATETIME     NOT NULL,
    api_football_fixture_id INT UNSIGNED NULL, -- resolved lazily, used to fetch goal events
    events_synced_at        DATETIME     NULL,
    CONSTRAINT fk_matches_competition FOREIGN KEY (competition_code) REFERENCES competitions(code),
    CONSTRAINT fk_matches_home FOREIGN KEY (home_team_id) REFERENCES teams(id),
    CONSTRAINT fk_matches_away FOREIGN KEY (away_team_id) REFERENCES teams(id),
    INDEX idx_matches_kickoff (utc_kickoff),
    INDEX idx_matches_competition_day (competition_code, utc_kickoff),
    INDEX idx_matches_status (status)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS standings (
    competition_code   VARCHAR(8)   NOT NULL,
    season_start_year  SMALLINT     NOT NULL,
    group_label         VARCHAR(50)  NOT NULL DEFAULT 'TOTAL', -- 'TOTAL' for a normal league table, or e.g. 'Group A' for cup group stages
    team_id            INT UNSIGNED NOT NULL,
    position            TINYINT UNSIGNED NOT NULL,
    played_games        TINYINT UNSIGNED NOT NULL DEFAULT 0,
    won                  TINYINT UNSIGNED NOT NULL DEFAULT 0,
    draw                 TINYINT UNSIGNED NOT NULL DEFAULT 0,
    lost                 TINYINT UNSIGNED NOT NULL DEFAULT 0,
    points               SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    goals_for            SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    goals_against        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    goal_difference       SMALLINT NOT NULL DEFAULT 0,
    form                 VARCHAR(20) NULL,
    updated_at           DATETIME NOT NULL,
    PRIMARY KEY (competition_code, season_start_year, group_label, team_id),
    CONSTRAINT fk_standings_team FOREIGN KEY (team_id) REFERENCES teams(id)
) ENGINE=InnoDB;

-- All 12 competitions football-data.org's free tier includes.
INSERT INTO competitions (code, name, country, type, sort_order) VALUES
    ('PL',  'Premier League',          'England',     'LEAGUE', 1),
    ('PD',  'La Liga',                 'Spain',       'LEAGUE', 2),
    ('BL1', 'Bundesliga',              'Germany',     'LEAGUE', 3),
    ('SA',  'Serie A',                 'Italy',       'LEAGUE', 4),
    ('FL1', 'Ligue 1',                 'France',      'LEAGUE', 5),
    ('DED', 'Eredivisie',              'Netherlands', 'LEAGUE', 6),
    ('PPL', 'Primeira Liga',           'Portugal',    'LEAGUE', 7),
    ('ELC', 'Championship',            'England',     'LEAGUE', 8),
    ('BSA', 'Série A',                 'Brazil',      'LEAGUE', 9),
    ('CL',  'UEFA Champions League',   'Europe',      'CUP',    10),
    ('EC',  'European Championship',   'Europe',      'CUP',    11),
    ('WC',  'FIFA World Cup',          'World',       'CUP',    12),
    ('NPFL','NPFL',                    'Nigeria',     'LEAGUE', 13) -- sourced from API-Football, see config.php
ON DUPLICATE KEY UPDATE name = VALUES(name), type = VALUES(type);
