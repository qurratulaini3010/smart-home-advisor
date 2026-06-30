-- Migration: Add monthly_commitment and net_income to assessments table
-- Run this if you already have an existing database from a previous version.

USE smart_home_advisor;

-- Add monthly_commitment column (default 0 — existing rows have no commitments recorded)
ALTER TABLE assessments
    ADD COLUMN monthly_commitment DECIMAL(12,2) NOT NULL DEFAULT 0
    AFTER monthly_income;

-- Add net_income as a generated (computed) column: gross income minus commitments
ALTER TABLE assessments
    ADD COLUMN net_income DECIMAL(12,2) GENERATED ALWAYS AS (monthly_income - monthly_commitment) STORED
    AFTER monthly_commitment;
