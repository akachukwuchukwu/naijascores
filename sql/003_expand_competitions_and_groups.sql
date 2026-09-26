-- Run this once against an existing `livescore` database to add the
-- remaining 8 free-tier competitions and support group-stage standings
-- (needed for Champions League / World Cup / Euros).
USE livescore;

ALTER TABLE competitions
    ADD COLUMN type ENUM('LEAGUE','CUP') NOT NULL DEFAULT 'LEAGUE' AFTER country;

INSERT INTO competitions (code, name, country, type, sort_order) VALUES
    ('FL1', 'Ligue 1',                 'France',      'LEAGUE', 5),
    ('DED', 'Eredivisie',              'Netherlands', 'LEAGUE', 6),
    ('PPL', 'Primeira Liga',           'Portugal',    'LEAGUE', 7),
    ('ELC', 'Championship',            'England',     'LEAGUE', 8),
    ('BSA', 'Série A',                 'Brazil',      'LEAGUE', 9),
    ('CL',  'UEFA Champions League',   'Europe',      'CUP',    10),
    ('EC',  'European Championship',   'Europe',      'CUP',    11),
    ('WC',  'FIFA World Cup',          'World',       'CUP',    12)
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- Standings need a group label now (Champions League etc. have "Group A",
-- "Group B"... rather than one flat table). Existing rows become 'TOTAL'.
ALTER TABLE standings
    ADD COLUMN group_label VARCHAR(50) NOT NULL DEFAULT 'TOTAL' AFTER season_start_year,
    DROP PRIMARY KEY,
    ADD PRIMARY KEY (competition_code, season_start_year, group_label, team_id);
