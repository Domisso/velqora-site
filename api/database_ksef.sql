-- ═══════════════════════════════════════════════════
-- VELQORA — Tabele KSeF
-- Dodaj do istniejącej bazy przez phpMyAdmin
-- ═══════════════════════════════════════════════════

-- ── SESJE KSeF ───────────────────────────────────────
CREATE TABLE IF NOT EXISTS `ksef_sessions` (
  `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id`       INT UNSIGNED NOT NULL UNIQUE,
  `session_token` VARCHAR(500) NOT NULL,
  `nip`           VARCHAR(20) NOT NULL,
  `expires_at`    DATETIME NOT NULL,
  `created_at`    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── LOGI KSeF ────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `ksef_logs` (
  `id`             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id`        INT UNSIGNED NOT NULL,
  `invoice_id`     INT UNSIGNED DEFAULT NULL,
  `action`         VARCHAR(50) NOT NULL,
  `ksef_reference` VARCHAR(255) DEFAULT NULL,
  `ksef_number`    VARCHAR(255) DEFAULT NULL,
  `status`         VARCHAR(50) DEFAULT NULL,
  `request_xml`    LONGTEXT DEFAULT NULL,
  `response_json`  LONGTEXT DEFAULT NULL,
  `error_message`  TEXT DEFAULT NULL,
  `created_at`     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  INDEX (`user_id`),
  INDEX (`ksef_reference`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── KOLUMNY KSeF W TABELI FAKTUR ─────────────────────
ALTER TABLE `invoices`
  ADD COLUMN IF NOT EXISTS `ksef_reference` VARCHAR(255) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `ksef_number`    VARCHAR(255) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `ksef_status`    ENUM('pending','sent','accepted','rejected','error') DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `ksef_sent_at`   DATETIME DEFAULT NULL,
  ADD INDEX IF NOT EXISTS (`ksef_number`);

-- ── TOKEN KSeF W TABELI UŻYTKOWNIKÓW ─────────────────
ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `ksef_token` TEXT DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `ksef_token_expires` DATETIME DEFAULT NULL;
