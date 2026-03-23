-- ═══════════════════════════════════════════════════════════════
-- CryptoScope Backend Database Schema
-- Fixed for shared hosting (Hostinger / cPanel)
-- 
-- ⚠ DO NOT run CREATE DATABASE — use your existing database:
--    Database: u921987559_Clawfi  (already exists)
--    User:     u921987559_Clawfi
--
-- HOW TO IMPORT:
--   1. Go to phpMyAdmin
--   2. Click on "u921987559_Clawfi" in the left sidebar
--   3. Click "Import" tab at the top
--   4. Upload this file → click Go
-- ═══════════════════════════════════════════════════════════════

-- Select your existing database (no CREATE DATABASE needed)
USE `u921987559_Clawfi`;

-- ── Users ──────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `cs_users` (
    `id`           VARCHAR(128)  NOT NULL,
    `email`        VARCHAR(255)  NOT NULL,
    `name`         VARCHAR(255)  DEFAULT NULL,
    `picture`      TEXT          DEFAULT NULL,
    `pass_hash`    VARCHAR(128)  DEFAULT NULL,
    `secret`       VARCHAR(128)  DEFAULT NULL,
    `provider`     ENUM('email','google','guest') NOT NULL DEFAULT 'email',
    `evm_address`  VARCHAR(42)   DEFAULT NULL,
    `sol_address`  VARCHAR(64)   DEFAULT NULL,
    `ton_address`  VARCHAR(80)   DEFAULT NULL,
    `created_at`   DATETIME      DEFAULT CURRENT_TIMESTAMP,
    `updated_at`   DATETIME      DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `last_login`   DATETIME      DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_email`   (`email`),
    KEY `idx_evm`     (`evm_address`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Sessions ───────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `cs_sessions` (
    `token`        VARCHAR(128)  NOT NULL,
    `user_id`      VARCHAR(128)  NOT NULL,
    `created_at`   DATETIME      DEFAULT CURRENT_TIMESTAMP,
    `expires_at`   DATETIME      NOT NULL,
    `ip`           VARCHAR(64)   DEFAULT NULL,
    `user_agent`   TEXT          DEFAULT NULL,
    PRIMARY KEY (`token`),
    KEY `idx_user_id` (`user_id`),
    KEY `idx_expires`  (`expires_at`),
    CONSTRAINT `fk_sess_user` FOREIGN KEY (`user_id`) REFERENCES `cs_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Deployed Tokens ────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `cs_deployed_tokens` (
    `id`            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `user_id`       VARCHAR(128)  NOT NULL,
    `contract_addr` VARCHAR(42)   NOT NULL,
    `token_name`    VARCHAR(128)  NOT NULL,
    `symbol`        VARCHAR(20)   NOT NULL,
    `decimals`      TINYINT UNSIGNED DEFAULT 18,
    `total_supply`  VARCHAR(64)   DEFAULT NULL,
    `chain`         VARCHAR(32)   DEFAULT 'base',
    `tx_hash`       VARCHAR(80)   DEFAULT NULL,
    `deployed_at`   DATETIME      DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_contract` (`contract_addr`),
    KEY `idx_user_id` (`user_id`),
    CONSTRAINT `fk_dep_user` FOREIGN KEY (`user_id`) REFERENCES `cs_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Imported Tokens ────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `cs_imported_tokens` (
    `id`            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `user_id`       VARCHAR(128)  NOT NULL,
    `contract_addr` VARCHAR(42)   NOT NULL,
    `token_name`    VARCHAR(128)  DEFAULT NULL,
    `symbol`        VARCHAR(20)   DEFAULT NULL,
    `decimals`      TINYINT UNSIGNED DEFAULT 18,
    `chain`         VARCHAR(16)   DEFAULT 'base',
    `imported_at`   DATETIME      DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_user_contract_chain` (`user_id`, `contract_addr`, `chain`),
    KEY `idx_user_chain` (`user_id`, `chain`),
    CONSTRAINT `fk_imp_user` FOREIGN KEY (`user_id`) REFERENCES `cs_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Airdrop Points ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `cs_airdrop_points` (
    `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `user_id`     VARCHAR(128)  NOT NULL,
    `action`      VARCHAR(64)   NOT NULL,
    `points`      INT           NOT NULL DEFAULT 10,
    `ref_data`    VARCHAR(256)  DEFAULT NULL,
    `created_at`  DATETIME      DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_user_id` (`user_id`),
    KEY `idx_action`  (`action`),
    CONSTRAINT `fk_air_user` FOREIGN KEY (`user_id`) REFERENCES `cs_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Referrals ──────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `cs_referrals` (
    `id`           INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `referrer_id`  VARCHAR(128)  NOT NULL,
    `referred_id`  VARCHAR(128)  NOT NULL,
    `created_at`   DATETIME      DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_referred` (`referred_id`),
    KEY `idx_referrer` (`referrer_id`),
    CONSTRAINT `fk_ref_referrer` FOREIGN KEY (`referrer_id`) REFERENCES `cs_users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_ref_referred` FOREIGN KEY (`referred_id`) REFERENCES `cs_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Price Cache ───────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `cs_price_cache` (
    `cache_key`   VARCHAR(128)  NOT NULL,
    `data`        LONGTEXT      NOT NULL,
    `cached_at`   DATETIME      DEFAULT CURRENT_TIMESTAMP,
    `ttl_seconds` INT UNSIGNED  DEFAULT 60,
    PRIMARY KEY (`cache_key`),
    KEY `idx_cached_at` (`cached_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Audit Log ─────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `cs_audit_log` (
    `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`     VARCHAR(128)  DEFAULT NULL,
    `action`      VARCHAR(64)   NOT NULL,
    `details`     TEXT          DEFAULT NULL,
    `ip`          VARCHAR(64)   DEFAULT NULL,
    `created_at`  DATETIME      DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_user_id`  (`user_id`),
    KEY `idx_action`   (`action`),
    KEY `idx_created`  (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Done ──────────────────────────────────────────────────────
SELECT 'CryptoScope tables created successfully!' AS status;
