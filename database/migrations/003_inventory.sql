-- =====================================================================
-- 003 INVENTORY: shared catalogue, per-hotel stock ledger, transfers, PRs
-- Policy: hotel_stock is a cached balance; stock_movements is the ledger.
-- Every balance change MUST insert a stock_movements row in the same tx.
-- =====================================================================

CREATE TABLE part_categories (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name_th VARCHAR(100) NOT NULL,
  name_en VARCHAR(100) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pcat_name (name_th)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE spare_parts (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  sku VARCHAR(40) NOT NULL,
  name VARCHAR(200) NOT NULL,
  category_id INT UNSIGNED NULL,
  description VARCHAR(1000) NULL,
  unit VARCHAR(20) NOT NULL DEFAULT 'ชิ้น',
  supplier_id INT UNSIGNED NULL,
  cost_price DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  min_stock DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  reorder_point DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  image_path VARCHAR(255) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  is_demo TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_part_sku (sku),
  CONSTRAINT fk_part_cat FOREIGN KEY (category_id) REFERENCES part_categories (id),
  CONSTRAINT fk_part_sup FOREIGN KEY (supplier_id) REFERENCES suppliers (id),
  CONSTRAINT chk_part_cost CHECK (cost_price >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE hotel_stock (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  hotel_id INT UNSIGNED NOT NULL,
  part_id INT UNSIGNED NOT NULL,
  qty_on_hand DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  avg_cost DECIMAL(14,4) NOT NULL DEFAULT 0.0000,
  min_stock DECIMAL(12,2) NULL,
  reorder_point DECIMAL(12,2) NULL,
  bin_location VARCHAR(50) NULL,
  low_stock_notified_at DATETIME NULL,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_stock (hotel_id, part_id),
  CONSTRAINT fk_stock_hotel FOREIGN KEY (hotel_id) REFERENCES hotels (id),
  CONSTRAINT fk_stock_part FOREIGN KEY (part_id) REFERENCES spare_parts (id),
  CONSTRAINT chk_stock_nonneg CHECK (qty_on_hand >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE stock_movements (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  hotel_id INT UNSIGNED NOT NULL,
  part_id INT UNSIGNED NOT NULL,
  movement_type VARCHAR(20) NOT NULL,
  quantity DECIMAL(12,2) NOT NULL,
  unit_cost DECIMAL(14,4) NOT NULL DEFAULT 0.0000,
  total_cost DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  balance_after DECIMAL(12,2) NOT NULL,
  ref_type VARCHAR(20) NULL,
  ref_id BIGINT UNSIGNED NULL,
  reversal_of_id BIGINT UNSIGNED NULL,
  supplier_id INT UNSIGNED NULL,
  note VARCHAR(500) NULL,
  user_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sm_reversal (reversal_of_id),
  KEY idx_sm_hotel_part (hotel_id, part_id, created_at),
  KEY idx_sm_ref (ref_type, ref_id),
  KEY idx_sm_type (hotel_id, movement_type, created_at),
  CONSTRAINT chk_sm_type CHECK (movement_type IN ('RECEIVE','ISSUE','RETURN','ADJUSTMENT','TRANSFER_OUT','TRANSFER_IN')),
  CONSTRAINT chk_sm_qty CHECK (quantity <> 0),
  CONSTRAINT fk_sm_hotel FOREIGN KEY (hotel_id) REFERENCES hotels (id),
  CONSTRAINT fk_sm_part FOREIGN KEY (part_id) REFERENCES spare_parts (id),
  CONSTRAINT fk_sm_rev FOREIGN KEY (reversal_of_id) REFERENCES stock_movements (id),
  CONSTRAINT fk_sm_sup FOREIGN KEY (supplier_id) REFERENCES suppliers (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE stock_transfers (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  transfer_no VARCHAR(30) NOT NULL,
  from_hotel_id INT UNSIGNED NOT NULL,
  to_hotel_id INT UNSIGNED NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'REQUESTED',
  note VARCHAR(1000) NULL,
  requested_by INT UNSIGNED NOT NULL,
  requested_at DATETIME NOT NULL,
  approved_by INT UNSIGNED NULL,
  approved_at DATETIME NULL,
  received_by INT UNSIGNED NULL,
  received_at DATETIME NULL,
  closed_by INT UNSIGNED NULL,
  closed_at DATETIME NULL,
  close_reason VARCHAR(500) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_transfer_no (transfer_no),
  KEY idx_tr_from (from_hotel_id, status),
  KEY idx_tr_to (to_hotel_id, status),
  CONSTRAINT chk_tr_status CHECK (status IN ('REQUESTED','IN_TRANSIT','RECEIVED','REJECTED','CANCELLED')),
  CONSTRAINT chk_tr_diff CHECK (from_hotel_id <> to_hotel_id),
  CONSTRAINT fk_tr_from FOREIGN KEY (from_hotel_id) REFERENCES hotels (id),
  CONSTRAINT fk_tr_to FOREIGN KEY (to_hotel_id) REFERENCES hotels (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE stock_transfer_items (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  transfer_id INT UNSIGNED NOT NULL,
  part_id INT UNSIGNED NOT NULL,
  quantity DECIMAL(12,2) NOT NULL,
  unit_cost DECIMAL(14,4) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_tri (transfer_id, part_id),
  CONSTRAINT fk_tri_tr FOREIGN KEY (transfer_id) REFERENCES stock_transfers (id),
  CONSTRAINT fk_tri_part FOREIGN KEY (part_id) REFERENCES spare_parts (id),
  CONSTRAINT chk_tri_qty CHECK (quantity > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ticket_part_usages (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  ticket_id INT UNSIGNED NOT NULL,
  hotel_id INT UNSIGNED NOT NULL,
  part_id INT UNSIGNED NOT NULL,
  quantity DECIMAL(12,2) NOT NULL,
  unit_cost DECIMAL(14,4) NOT NULL,
  total_cost DECIMAL(14,2) NOT NULL,
  movement_id BIGINT UNSIGNED NOT NULL,
  reversal_movement_id BIGINT UNSIGNED NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'ISSUED',
  issued_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  reversed_by INT UNSIGNED NULL,
  reversed_at DATETIME NULL,
  reverse_reason VARCHAR(500) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_tpu_mv (movement_id),
  KEY idx_tpu_ticket (ticket_id, hotel_id),
  CONSTRAINT chk_tpu_status CHECK (status IN ('ISSUED','REVERSED')),
  CONSTRAINT chk_tpu_qty CHECK (quantity > 0),
  CONSTRAINT fk_tpu_ticket FOREIGN KEY (ticket_id, hotel_id) REFERENCES tickets (id, hotel_id),
  CONSTRAINT fk_tpu_part FOREIGN KEY (part_id) REFERENCES spare_parts (id),
  CONSTRAINT fk_tpu_mv FOREIGN KEY (movement_id) REFERENCES stock_movements (id),
  CONSTRAINT fk_tpu_rmv FOREIGN KEY (reversal_movement_id) REFERENCES stock_movements (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE purchase_requests (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  pr_no VARCHAR(30) NOT NULL,
  hotel_id INT UNSIGNED NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'SUBMITTED',
  supplier_id INT UNSIGNED NULL,
  note VARCHAR(1000) NULL,
  total_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  requested_by INT UNSIGNED NOT NULL,
  requested_at DATETIME NOT NULL,
  approved_by INT UNSIGNED NULL,
  approved_at DATETIME NULL,
  received_by INT UNSIGNED NULL,
  received_at DATETIME NULL,
  reject_reason VARCHAR(500) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pr_no (pr_no),
  KEY idx_pr_hotel (hotel_id, status),
  CONSTRAINT chk_pr_status CHECK (status IN ('SUBMITTED','APPROVED','REJECTED','RECEIVED','CANCELLED')),
  CONSTRAINT fk_pr_hotel FOREIGN KEY (hotel_id) REFERENCES hotels (id),
  CONSTRAINT fk_pr_sup FOREIGN KEY (supplier_id) REFERENCES suppliers (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE purchase_request_items (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  pr_id INT UNSIGNED NOT NULL,
  part_id INT UNSIGNED NOT NULL,
  quantity DECIMAL(12,2) NOT NULL,
  unit_cost DECIMAL(14,4) NOT NULL,
  total_cost DECIMAL(14,2) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pri (pr_id, part_id),
  CONSTRAINT fk_pri_pr FOREIGN KEY (pr_id) REFERENCES purchase_requests (id),
  CONSTRAINT fk_pri_part FOREIGN KEY (part_id) REFERENCES spare_parts (id),
  CONSTRAINT chk_pri_qty CHECK (quantity > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
