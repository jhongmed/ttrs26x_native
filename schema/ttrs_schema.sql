-- =================================================================
-- TTRS 2.6.x — Database Schema
-- Tee Time Reservation System — The Orchard Golf & Country Club
--
-- Creates:
--   - `users`         : application accounts
--   - `login_history` : a record of every login attempt (success/failed)
--
-- Usage:
--   mysql -u root -p < ttrs_schema.sql
-- =================================================================

CREATE DATABASE IF NOT EXISTS ttrs
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE ttrs;

-- -----------------------------------------------------------------
-- users
-- -----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username       VARCHAR(50)  NOT NULL,
    email          VARCHAR(255) NOT NULL,
    password_hash  VARCHAR(255) NOT NULL,          -- bcrypt hash from PHP password_hash()
    role           ENUM('administrator', 'staff', 'member') NOT NULL DEFAULT 'member',
    status         ENUM('active', 'inactive', 'locked') NOT NULL DEFAULT 'active',
    last_login_at  TIMESTAMP NULL DEFAULT NULL,
    created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_users_username (username),
    UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB;

-- -----------------------------------------------------------------
-- login_history
-- One row per login attempt, successful or not. Keeping the
-- username actually typed (username_attempted) as well as the
-- resolved user_id lets you audit attempts against usernames
-- that don't even exist.
-- -----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS login_history (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id             INT UNSIGNED NULL,
    username_attempted  VARCHAR(50)  NOT NULL,
    ip_address          VARCHAR(45)  NOT NULL,      -- IPv4 or IPv6
    user_agent          VARCHAR(255) NULL,
    status              ENUM('success', 'failed') NOT NULL,
    attempted_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_login_history_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE SET NULL,

    INDEX idx_login_history_username (username_attempted),
    INDEX idx_login_history_attempted_at (attempted_at),
    INDEX idx_login_history_status (status)
) ENGINE=InnoDB;

-- -----------------------------------------------------------------
-- password_resets
-- One active reset token per email at a time. The token itself is
-- never stored in plaintext — only its SHA-256 hash — so a leaked
-- database dump can't be used to reset accounts directly.
-- -----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS password_resets (
    email        VARCHAR(255) NOT NULL PRIMARY KEY,
    token_hash   VARCHAR(64)  NOT NULL,   -- sha256 hex digest
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at   TIMESTAMP NOT NULL,

    CONSTRAINT fk_password_resets_email
        FOREIGN KEY (email) REFERENCES users(email)
        ON DELETE CASCADE
) ENGINE=InnoDB;

-- -----------------------------------------------------------------
-- Demo seed user (REMOVE or change the password before production)
-- username: admin
-- password: admin123
-- -----------------------------------------------------------------
INSERT INTO users (username, email, password_hash, role, status)
VALUES (
    'admin',
    'admin@theorchardgolf.com',
    '$2b$10$zqHwClG7DLqB2SSEugSH7.3Az5SA/NJ3Nnt4F/DSWdEEVXDg6SAIy',
    'administrator',
    'active'
)
ON DUPLICATE KEY UPDATE username = username;
