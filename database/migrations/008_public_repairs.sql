-- Public intake metadata stays private; no anonymous ticket lookup is provided.
CREATE TABLE public_repair_submissions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ticket_id INT UNSIGNED NOT NULL,
  submission_hash CHAR(64) NOT NULL,
  ip_hash CHAR(64) NOT NULL,
  reporter_kind VARCHAR(10) NOT NULL,
  contact VARCHAR(150) NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_public_submission (submission_hash),
  UNIQUE KEY uq_public_ticket (ticket_id),
  KEY idx_public_rate (ip_hash, created_at),
  CONSTRAINT fk_public_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
