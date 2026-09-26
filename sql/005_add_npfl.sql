-- Run this once against an existing `livescore` database to add NPFL as a
-- fully tracked competition sourced from API-Football (rather than just
-- used for enrichment like the other 12).
USE livescore;

ALTER TABLE teams
    ADD COLUMN data_source ENUM('football_data','api_football') NOT NULL DEFAULT 'football_data';

INSERT INTO competitions (code, name, country, type, sort_order) VALUES
    ('NPFL', 'NPFL', 'Nigeria', 'LEAGUE', 13)
ON DUPLICATE KEY UPDATE name = VALUES(name);
