-- =====================================================================
-- 005 FINANCE: single cost ledger + budgets
-- Cost recognition: maintenance cost is recognised when parts are
-- CONSUMED on a ticket (PART_USAGE) or labour is LOGGED (WORK_LOG), or a
-- MANUAL expense (contractor/replacement/other) is APPROVED.
-- Inventory purchases (stock RECEIVE) are NOT maintenance costs.
-- =====================================================================

CREATE TABLE cost_categories (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code VARCHAR(20) NOT NULL,
  name_th VARCHAR(100) NOT NULL,
  name_en VARCHAR(100) NOT NULL,
  is_system TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ccat_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cost_entries (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  hotel_id INT UNSIGNED NOT NULL,
  ticket_id INT UNSIGNED NULL,
  asset_id INT UNSIGNED NULL,
  category_id INT UNSIGNED NOT NULL,
  source VARCHAR(20) NOT NULL DEFAULT 'MANUAL',
  source_id BIGINT UNSIGNED NULL,
  description VARCHAR(500) NOT NULL,
  quantity DECIMAL(12,2) NOT NULL DEFAULT 1.00,
  unit_cost DECIMAL(14,4) NOT NULL,
  total_cost DECIMAL(14,2) NOT NULL,
  expense_date DATE NOT NULL,
  supplier_id INT UNSIGNED NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'PENDING',
  approved_by INT UNSIGNED NULL,
  approved_at DATETIME NULL,
  reject_reason VARCHAR(500) NULL,
  document_path VARCHAR(255) NULL,
  document_name VARCHAR(255) NULL,
  document_mime VARCHAR(100) NULL,
  is_demo TINYINT(1) NOT NULL DEFAULT 0,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_cost_source (source, source_id),
  KEY idx_cost_hotel_date (hotel_id, expense_date),
  KEY idx_cost_ticket (ticket_id, hotel_id),
  KEY idx_cost_asset (asset_id, hotel_id),
  KEY idx_cost_status (hotel_id, status),
  CONSTRAINT chk_cost_status CHECK (status IN ('PENDING','APPROVED','REJECTED','VOID')),
  CONSTRAINT chk_cost_source CHECK (source IN ('MANUAL','PART_USAGE','WORK_LOG')),
  CONSTRAINT chk_cost_nonneg CHECK (total_cost >= 0),
  CONSTRAINT fk_cost_hotel FOREIGN KEY (hotel_id) REFERENCES hotels (id),
  CONSTRAINT fk_cost_ticket FOREIGN KEY (ticket_id, hotel_id) REFERENCES tickets (id, hotel_id),
  CONSTRAINT fk_cost_asset FOREIGN KEY (asset_id, hotel_id) REFERENCES assets (id, hotel_id),
  CONSTRAINT fk_cost_cat FOREIGN KEY (category_id) REFERENCES cost_categories (id),
  CONSTRAINT fk_cost_sup FOREIGN KEY (supplier_id) REFERENCES suppliers (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- month NULL = annual budget; category_id NULL = all categories
CREATE TABLE budgets (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  hotel_id INT UNSIGNED NOT NULL,
  year SMALLINT UNSIGNED NOT NULL,
  month TINYINT UNSIGNED NULL,
  category_id INT UNSIGNED NULL,
  amount DECIMAL(14,2) NOT NULL,
  note VARCHAR(500) NULL,
  month_key TINYINT UNSIGNED AS (IFNULL(month, 0)) STORED,
  category_key INT UNSIGNED AS (IFNULL(category_id, 0)) STORED,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_budget (hotel_id, year, month_key, category_key),
  CONSTRAINT chk_budget_month CHECK (month IS NULL OR (month BETWEEN 1 AND 12)),
  CONSTRAINT chk_budget_amt CHECK (amount >= 0),
  CONSTRAINT fk_budget_hotel FOREIGN KEY (hotel_id) REFERENCES hotels (id),
  CONSTRAINT fk_budget_cat FOREIGN KEY (category_id) REFERENCES cost_categories (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
