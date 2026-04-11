-- =============================================================================
-- Database Reset Script - Black Legion Cold War Campaign
-- This will completely wipe and recreate the database
-- DANGER: This destroys ALL data in campaign_data database
-- =============================================================================

-- Drop the entire database and recreate it
DROP DATABASE IF EXISTS campaign_data;
CREATE DATABASE campaign_data CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE campaign_data;

-- At this point, run schema.sql then seed.sql
-- 1. Run: schema.sql
-- 2. Run: seed.sql