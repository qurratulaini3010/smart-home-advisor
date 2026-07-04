-- ─────────────────────────────────────────────────────────────────────────────
-- migrate_financial_profile.sql
-- Run ONCE on an existing database after schema.sql + migrate_properties_dataset.sql
-- Safe to re-run: all statements use IF NOT EXISTS / IF EXISTS guards.
-- ─────────────────────────────────────────────────────────────────────────────

-- 1. Add date_of_birth to users (age is derived from this at query time,
--    so we never have a stale "age" number sitting in the DB).
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS date_of_birth DATE NULL AFTER occupation,
    ADD COLUMN IF NOT EXISTS gross_monthly_income DECIMAL(12,2) NULL AFTER date_of_birth;

-- 2. Itemised monthly commitments table.
--    Each row is one commitment line belonging to a user.
--    total_commitment on the user's financial profile is SUM(amount) over their rows.
CREATE TABLE IF NOT EXISTS user_commitments (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    user_id      INT NOT NULL,
    label        VARCHAR(150) NOT NULL,          -- e.g. "Myvi car loan", "PTPTN"
    category     ENUM(
                   'car_loan',
                   'study_loan',
                   'personal_loan',
                   'credit_card',
                   'existing_mortgage',
                   'other'
                 ) NOT NULL DEFAULT 'other',
    amount       DECIMAL(12,2) NOT NULL DEFAULT 0,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_uc_user (user_id)
) ENGINE=InnoDB;

-- 3. Assessment no longer stores household_size (redundant with min bedrooms)
--    or age (now on users.date_of_birth).
--    Keep the columns for backward compatibility but mark them nullable/default
--    so existing rows are not broken and new inserts can omit them.
ALTER TABLE assessments
    MODIFY COLUMN IF EXISTS age INT NULL DEFAULT NULL,
    MODIFY COLUMN IF EXISTS household_size INT NOT NULL DEFAULT 1;
-- Note: we keep household_size column so old recommendation rows referencing it
-- are not broken; the assessment form simply no longer collects it.
