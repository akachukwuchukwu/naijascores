-- Run this once against an existing `livescore` database to enable
-- goal-event enrichment from API-Football (in addition to your existing
-- football-data.org data, which stays the primary source).
USE livescore;

ALTER TABLE matches
    ADD COLUMN api_football_fixture_id INT UNSIGNED NULL,
    ADD COLUMN events_synced_at DATETIME NULL;
