-- ═══════════════════════════════════════════════════
-- VELQORA — Schemat bazy danych MySQL
-- Wgraj przez phpMyAdmin na OVHCloud
-- ═══════════════════════════════════════════════════

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ── UŻYTKOWNICY ──────────────────────────────────────
CREATE TABLE `users` (
  `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `email`           VARCHAR(255) NOT NULL UNIQUE,
  `password_hash`   VARCHAR(255) NOT NULL,
  `first_name`      VARCHAR(100) NOT NULL,
  `last_name`       VARCHAR(100) NOT NULL,
  `phone`           VARCHAR(30) DEFAULT NULL,
  `avatar`          VARCHAR(10) DEFAULT NULL,
  `plan`            ENUM('starter','pro','business') DEFAULT 'starter',
  `plan_expires_at` DATETIME DEFAULT NULL,
  `stripe_customer_id` VARCHAR(100) DEFAULT NULL,
  `is_active`       TINYINT(1) DEFAULT 1,
  `email_verified`  TINYINT(1) DEFAULT 0,
  `verify_token`    VARCHAR(100) DEFAULT NULL,
  `reset_token`     VARCHAR(100) DEFAULT NULL,
  `reset_expires`   DATETIME DEFAULT NULL,
  `two_fa_secret`   VARCHAR(100) DEFAULT NULL,
  `two_fa_enabled`  TINYINT(1) DEFAULT 0,
  `created_at`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── FIRMY ────────────────────────────────────────────
CREATE TABLE `companies` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id`     INT UNSIGNED NOT NULL,
  `name`        VARCHAR(255) NOT NULL,
  `nip`         VARCHAR(20) DEFAULT NULL,
  `regon`       VARCHAR(20) DEFAULT NULL,
  `krs`         VARCHAR(20) DEFAULT NULL,
  `address`     VARCHAR(255) DEFAULT NULL,
  `city`        VARCHAR(100) DEFAULT NULL,
  `postal_code` VARCHAR(10) DEFAULT NULL,
  `country`     VARCHAR(100) DEFAULT 'Polska',
  `iban`        VARCHAR(50) DEFAULT NULL,
  `bank_name`   VARCHAR(100) DEFAULT NULL,
  `email`       VARCHAR(255) DEFAULT NULL,
  `phone`       VARCHAR(30) DEFAULT NULL,
  `website`     VARCHAR(255) DEFAULT NULL,
  `vat_rate`    TINYINT DEFAULT 23,
  `currency`    VARCHAR(3) DEFAULT 'PLN',
  `logo_path`   VARCHAR(255) DEFAULT NULL,
  `created_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── SESJE ────────────────────────────────────────────
CREATE TABLE `sessions` (
  `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id`    INT UNSIGNED NOT NULL,
  `token`      VARCHAR(255) NOT NULL UNIQUE,
  `ip`         VARCHAR(45) DEFAULT NULL,
  `user_agent` TEXT DEFAULT NULL,
  `expires_at` DATETIME NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  INDEX (`token`),
  INDEX (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── KLIENCI (KONTRAHENCI) ────────────────────────────
CREATE TABLE `clients` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id`     INT UNSIGNED NOT NULL,
  `name`        VARCHAR(255) NOT NULL,
  `nip`         VARCHAR(20) DEFAULT NULL,
  `email`       VARCHAR(255) DEFAULT NULL,
  `phone`       VARCHAR(30) DEFAULT NULL,
  `address`     VARCHAR(255) DEFAULT NULL,
  `city`        VARCHAR(100) DEFAULT NULL,
  `postal_code` VARCHAR(10) DEFAULT NULL,
  `country`     VARCHAR(100) DEFAULT 'Polska',
  `notes`       TEXT DEFAULT NULL,
  `created_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  INDEX (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── FAKTURY ──────────────────────────────────────────
CREATE TABLE `invoices` (
  `id`             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id`        INT UNSIGNED NOT NULL,
  `client_id`      INT UNSIGNED DEFAULT NULL,
  `number`         VARCHAR(50) NOT NULL,
  `status`         ENUM('draft','sent','paid','overdue','cancelled') DEFAULT 'draft',
  `issue_date`     DATE NOT NULL,
  `due_date`       DATE NOT NULL,
  `service_desc`   TEXT DEFAULT NULL,
  `net_amount`     DECIMAL(12,2) NOT NULL DEFAULT 0,
  `vat_rate`       TINYINT DEFAULT 23,
  `vat_amount`     DECIMAL(12,2) NOT NULL DEFAULT 0,
  `gross_amount`   DECIMAL(12,2) NOT NULL DEFAULT 0,
  `currency`       VARCHAR(3) DEFAULT 'PLN',
  `payment_method` VARCHAR(50) DEFAULT 'przelew',
  `notes`          TEXT DEFAULT NULL,
  `pdf_path`       VARCHAR(255) DEFAULT NULL,
  `paid_at`        DATETIME DEFAULT NULL,
  `sent_at`        DATETIME DEFAULT NULL,
  `created_at`     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`client_id`) REFERENCES `clients`(`id`) ON DELETE SET NULL,
  INDEX (`user_id`),
  INDEX (`status`),
  INDEX (`due_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── POZYCJE FAKTURY ──────────────────────────────────
CREATE TABLE `invoice_items` (
  `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `invoice_id`   INT UNSIGNED NOT NULL,
  `name`         VARCHAR(255) NOT NULL,
  `quantity`     DECIMAL(10,2) DEFAULT 1,
  `unit`         VARCHAR(20) DEFAULT 'szt.',
  `unit_price`   DECIMAL(12,2) NOT NULL,
  `vat_rate`     TINYINT DEFAULT 23,
  `net_total`    DECIMAL(12,2) NOT NULL,
  `gross_total`  DECIMAL(12,2) NOT NULL,
  FOREIGN KEY (`invoice_id`) REFERENCES `invoices`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── WYDATKI ──────────────────────────────────────────
CREATE TABLE `expenses` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id`     INT UNSIGNED NOT NULL,
  `category`    VARCHAR(100) DEFAULT NULL,
  `description` VARCHAR(255) NOT NULL,
  `amount`      DECIMAL(12,2) NOT NULL,
  `vat_amount`  DECIMAL(12,2) DEFAULT 0,
  `currency`    VARCHAR(3) DEFAULT 'PLN',
  `date`        DATE NOT NULL,
  `receipt_path` VARCHAR(255) DEFAULT NULL,
  `notes`       TEXT DEFAULT NULL,
  `created_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  INDEX (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── DOKUMENTY AI ─────────────────────────────────────
CREATE TABLE `ai_documents` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id`     INT UNSIGNED NOT NULL,
  `filename`    VARCHAR(255) NOT NULL,
  `file_path`   VARCHAR(255) NOT NULL,
  `file_size`   INT UNSIGNED DEFAULT 0,
  `mime_type`   VARCHAR(100) DEFAULT NULL,
  `analysis`    LONGTEXT DEFAULT NULL,
  `status`      ENUM('pending','processing','done','error') DEFAULT 'pending',
  `questions`   INT DEFAULT 0,
  `created_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  INDEX (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── CHAT AI ──────────────────────────────────────────
CREATE TABLE `ai_chat` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `document_id` INT UNSIGNED NOT NULL,
  `role`        ENUM('user','assistant') NOT NULL,
  `content`     TEXT NOT NULL,
  `created_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`document_id`) REFERENCES `ai_documents`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── PŁATNOŚCI (STRIPE) ───────────────────────────────
CREATE TABLE `payments` (
  `id`                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id`               INT UNSIGNED NOT NULL,
  `stripe_session_id`     VARCHAR(255) DEFAULT NULL UNIQUE,
  `stripe_payment_intent` VARCHAR(255) DEFAULT NULL,
  `plan`                  ENUM('starter','pro','business') NOT NULL,
  `billing`               ENUM('monthly','yearly') DEFAULT 'monthly',
  `amount`                DECIMAL(10,2) NOT NULL,
  `currency`              VARCHAR(3) DEFAULT 'PLN',
  `status`                ENUM('pending','paid','failed','refunded') DEFAULT 'pending',
  `period_start`          DATE DEFAULT NULL,
  `period_end`            DATE DEFAULT NULL,
  `invoice_pdf`           VARCHAR(255) DEFAULT NULL,
  `created_at`            TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  INDEX (`user_id`),
  INDEX (`stripe_session_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── POWIADOMIENIA ────────────────────────────────────
CREATE TABLE `notifications` (
  `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id`    INT UNSIGNED NOT NULL,
  `type`       VARCHAR(50) DEFAULT 'info',
  `title`      VARCHAR(255) NOT NULL,
  `message`    TEXT NOT NULL,
  `is_read`    TINYINT(1) DEFAULT 0,
  `link`       VARCHAR(255) DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  INDEX (`user_id`, `is_read`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── LOGI AKTYWNOŚCI ──────────────────────────────────
CREATE TABLE `activity_logs` (
  `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id`    INT UNSIGNED DEFAULT NULL,
  `action`     VARCHAR(100) NOT NULL,
  `details`    TEXT DEFAULT NULL,
  `ip`         VARCHAR(45) DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── PRZYKŁADOWE DANE (OPCJONALNE) ────────────────────
INSERT INTO `users` (`email`,`password_hash`,`first_name`,`last_name`,`plan`,`is_active`,`email_verified`) VALUES
('demo@velqora.pl', '$2y$12$demoHashPlaceholder12345678901234567890123456', 'Jan', 'Kowalski', 'pro', 1, 1);

SET FOREIGN_KEY_CHECKS = 1;
