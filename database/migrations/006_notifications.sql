-- =====================================================================
-- 006 NOTIFICATIONS: LINE destinations, rules, durable queue, logs
-- =====================================================================

-- A LINE group discovered via webhook (join/message). Must be explicitly
-- BOUND to a hotel by an administrator before it receives notifications.
CREATE TABLE line_destinations (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  hotel_id INT UNSIGNED NULL,
  group_id VARCHAR(64) NOT NULL,
  display_name VARCHAR(150) NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'DISCOVERED',
  bound_by INT UNSIGNED NULL,
  bound_at DATETIME NULL,
  discovered_at DATETIME NOT NULL,
  last_event_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_line_group (group_id),
  KEY idx_line_hotel (hotel_id, status),
  CONSTRAINT chk_line_status CHECK (status IN ('DISCOVERED','BOUND','DISABLED','LEFT')),
  CONSTRAINT fk_line_hotel FOREIGN KEY (hotel_id) REFERENCES hotels (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- hotel_id NULL = group default. Hotel row overrides default.
CREATE TABLE notification_rules (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  hotel_id INT UNSIGNED NULL,
  event_type VARCHAR(50) NOT NULL,
  is_enabled TINYINT(1) NOT NULL DEFAULT 1,
  hotel_key INT UNSIGNED AS (IFNULL(hotel_id, 0)) STORED,
  updated_by INT UNSIGNED NULL,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_nrule (hotel_key, event_type),
  CONSTRAINT fk_nrule_hotel FOREIGN KEY (hotel_id) REFERENCES hotels (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE notification_queue (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  hotel_id INT UNSIGNED NULL,
  event_type VARCHAR(50) NOT NULL,
  channel VARCHAR(20) NOT NULL DEFAULT 'LINE',
  destination_id INT UNSIGNED NULL,
  dedupe_key VARCHAR(191) NOT NULL,
  payload LONGTEXT NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'PENDING',
  attempts INT UNSIGNED NOT NULL DEFAULT 0,
  max_attempts INT UNSIGNED NOT NULL DEFAULT 5,
  next_attempt_at DATETIME NOT NULL,
  locked_at DATETIME NULL,
  last_error VARCHAR(1000) NULL,
  sent_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_nq_dedupe (dedupe_key),
  KEY idx_nq_pending (status, next_attempt_at),
  KEY idx_nq_hotel (hotel_id, created_at),
  CONSTRAINT chk_nq_status CHECK (status IN ('PENDING','PROCESSING','SENT','FAILED','DEAD','SKIPPED')),
  CONSTRAINT fk_nq_dest FOREIGN KEY (destination_id) REFERENCES line_destinations (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE notification_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  queue_id BIGINT UNSIGNED NOT NULL,
  attempt_no INT UNSIGNED NOT NULL,
  transport VARCHAR(20) NOT NULL,
  http_status SMALLINT NULL,
  success TINYINT(1) NOT NULL,
  response_excerpt VARCHAR(1000) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_nl_queue (queue_id),
  CONSTRAINT fk_nl_queue FOREIGN KEY (queue_id) REFERENCES notification_queue (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Webhook de-duplication (LINE may redeliver events). Message text is NOT stored.
CREATE TABLE line_webhook_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  webhook_event_id VARCHAR(64) NOT NULL,
  event_type VARCHAR(30) NOT NULL,
  source_type VARCHAR(20) NULL,
  group_id VARCHAR(64) NULL,
  received_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_lwe (webhook_event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
