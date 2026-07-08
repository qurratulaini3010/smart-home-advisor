-- ─────────────────────────────────────────────────────────────────────────────
-- migrate_assessment_preferences.sql
-- Run ONCE on an existing database after schema.sql + migrate_financial_profile.sql.
-- Safe to re-run: uses IF NOT EXISTS guards.
--
-- Why: the assessment wizard's Step 2 ("Your Ideal Home") collects a tenure
-- preference, a minimum-bedrooms need, and two checkboxes (low flood risk,
-- near a school). These were rendered on the page and even transmitted in the
-- live-preview AJAX call, but nothing on the server read them, saved them, or
-- factored them into scoring — filling them in had no effect. This migration
-- adds columns so a saved assessment can persist what was actually asked for;
-- RecommendationEngine::score() now uses them as soft scoring bonuses/
-- penalties (see app/models/RecommendationEngine.php).
-- ─────────────────────────────────────────────────────────────────────────────

USE smart_home_advisor;

ALTER TABLE assessments
    ADD COLUMN IF NOT EXISTS tenure_preference VARCHAR(80) NULL AFTER property_type,
    ADD COLUMN IF NOT EXISTS min_bedrooms INT NULL AFTER tenure_preference,
    ADD COLUMN IF NOT EXISTS low_flood_risk TINYINT(1) NOT NULL DEFAULT 0 AFTER min_bedrooms,
    ADD COLUMN IF NOT EXISTS near_school TINYINT(1) NOT NULL DEFAULT 0 AFTER low_flood_risk;
