-- Run this once if your `livescore` database already exists from an earlier
-- version of schema.sql. Safe to skip on a brand new install — schema.sql
-- already includes these columns.
USE livescore;

ALTER TABLE teams
    ADD COLUMN founded SMALLINT NULL,
    ADD COLUMN venue VARCHAR(150) NULL,
    ADD COLUMN club_colors VARCHAR(100) NULL,
    ADD COLUMN website VARCHAR(255) NULL,
    ADD COLUMN coach_name VARCHAR(120) NULL,
    ADD COLUMN address VARCHAR(255) NULL,
    ADD COLUMN profile_synced_at DATETIME NULL;
