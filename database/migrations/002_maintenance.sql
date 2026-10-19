-- =====================================================================
-- 002 MAINTENANCE: master data, assets, tickets & workflow history
-- =====================================================================

CREATE TABLE maintenance_categories (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code VARCHAR(30) NOT NULL,
  name_th VARCHAR(100) NOT NULL,
  name_en VARCHAR(100) NOT NULL,
  icon VARCHAR(40) NOT NULL DEFAULT 'wrench',
  color CHAR(7) NOT NULL DEFAULT '#64748B',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_mcat_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Default SLA per priority (minutes). Per-hotel overrides in sla_rules.
CREATE TABLE priorities (
  id TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code VARCHAR(20) NOT NULL,
  name_th VARCHAR(60) NOT NULL,
  name_en VARCHAR(60) NOT NULL,
  color CHAR(7) NOT NULL,
  level TINYINT UNSIGNED NOT NULL,
  response_minutes INT UNSIGNED NOT NULL,
  resolution_minutes INT UNSIGNED NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_prio_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sla_rules (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  hotel_id INT UNSIGNED NOT NULL,
  priority_id TINYINT UNSIGNED NOT NULL,
  response_minutes INT UNSIGNED NOT NULL,
  resolution_minutes INT UNSIGNED NOT NULL,
  updated_by INT UNSIGNED NULL,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sla (hotel_id, priority_id),
  CONSTRAINT fk_sla_hotel FOREIGN KEY (hotel_id) REFERENCES hotels (id),
  CONSTRAINT fk_sla_prio FOREIGN KEY (priority_id) REFERENCES priorities (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- hotel_id NULL = shared group-wide supplier
CREATE TABLE suppliers (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  hotel_id INT UNSIGNED NULL,
  name VARCHAR(200) NOT NULL,
  contact_name VARCHAR(150) NULL,
  phone VARCHAR(50) NULL,
  email VARCHAR(150) NULL,
  tax_id VARCHAR(20) NULL,
  address VARCHAR(500) NULL,
  is_contractor TINYINT(1) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_sup_hotel (hotel_id),
  CONSTRAINT fk_sup_hotel FOREIGN KEY (hotel_id) REFERENCES hotels (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE asset_categories (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code VARCHAR(6) NOT NULL,
  name_th VARCHAR(100) NOT NULL,
  name_en VARCHAR(100) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_acat_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE assets (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  hotel_id INT UNSIGNED NOT NULL,
  asset_code VARCHAR(30) NOT NULL,
  category_id INT UNSIGNED NOT NULL,
  name VARCHAR(200) NOT NULL,
  brand VARCHAR(100) NULL,
  model VARCHAR(100) NULL,
  serial_no VARCHAR(100) NULL,
  building_id INT UNSIGNED NULL,
  floor_id INT UNSIGNED NULL,
  location_id INT UNSIGNED NULL,
  department_id INT UNSIGNED NULL,
  supplier_id INT UNSIGNED NULL,
  purchase_date DATE NULL,
  purchase_cost DECIMAL(14,2) NULL,
  warranty_start DATE NULL,
  warranty_end DATE NULL,
  asset_condition VARCHAR(20) NOT NULL DEFAULT 'GOOD',
  status VARCHAR(30) NOT NULL DEFAULT 'ACTIVE',
  image_path VARCHAR(255) NULL,
  notes TEXT NULL,
  qr_token CHAR(32) NOT NULL,
  is_demo TINYINT(1) NOT NULL DEFAULT 0,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  deleted_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_asset_code (asset_code),
  UNIQUE KEY uq_asset_qr (qr_token),
  UNIQUE KEY uq_asset_id_hotel (id, hotel_id),
  KEY idx_asset_hotel_status (hotel_id, status),
  KEY idx_asset_loc (location_id, hotel_id),
  KEY idx_asset_bld (building_id, hotel_id),
  KEY idx_asset_floor (floor_id, hotel_id),
  KEY idx_asset_dept (department_id, hotel_id),
  CONSTRAINT chk_asset_status CHECK (status IN ('ACTIVE','UNDER_MAINTENANCE','OUT_OF_SERVICE','DISPOSED')),
  CONSTRAINT chk_asset_cond CHECK (asset_condition IN ('GOOD','FAIR','POOR','CRITICAL')),
  CONSTRAINT fk_asset_hotel FOREIGN KEY (hotel_id) REFERENCES hotels (id),
  CONSTRAINT fk_asset_cat FOREIGN KEY (category_id) REFERENCES asset_categories (id),
  CONSTRAINT fk_asset_bld FOREIGN KEY (building_id, hotel_id) REFERENCES buildings (id, hotel_id),
  CONSTRAINT fk_asset_floor FOREIGN KEY (floor_id, hotel_id) REFERENCES floors (id, hotel_id),
  CONSTRAINT fk_asset_loc FOREIGN KEY (location_id, hotel_id) REFERENCES locations (id, hotel_id),
  CONSTRAINT fk_asset_dept FOREIGN KEY (department_id, hotel_id) REFERENCES departments (id, hotel_id),
  CONSTRAINT fk_asset_sup FOREIGN KEY (supplier_id) REFERENCES suppliers (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE asset_attachments (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  asset_id INT UNSIGNED NOT NULL,
  hotel_id INT UNSIGNED NOT NULL,
  kind VARCHAR(20) NOT NULL DEFAULT 'DOCUMENT',
  disk_path VARCHAR(255) NOT NULL,
  original_name VARCHAR(255) NOT NULL,
  mime VARCHAR(100) NOT NULL,
  size_bytes INT UNSIGNED NOT NULL,
  uploaded_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_aatt_asset (asset_id, hotel_id),
  CONSTRAINT chk_aatt_kind CHECK (kind IN ('IMAGE','MANUAL','DOCUMENT')),
  CONSTRAINT fk_aatt_asset FOREIGN KEY (asset_id, hotel_id) REFERENCES assets (id, hotel_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE asset_history (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  asset_id INT UNSIGNED NOT NULL,
  hotel_id INT UNSIGNED NOT NULL,
  event VARCHAR(30) NOT NULL,
  description VARCHAR(500) NOT NULL,
  ticket_id INT UNSIGNED NULL,
  user_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_ahist_asset (asset_id, created_at),
  CONSTRAINT fk_ahist_asset FOREIGN KEY (asset_id, hotel_id) REFERENCES assets (id, hotel_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE tickets (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  hotel_id INT UNSIGNED NOT NULL,
  ticket_no VARCHAR(30) NOT NULL,
  ticket_type VARCHAR(20) NOT NULL DEFAULT 'CORRECTIVE',
  status VARCHAR(30) NOT NULL DEFAULT 'NEW',
  title VARCHAR(200) NOT NULL,
  description TEXT NULL,
  category_id INT UNSIGNED NOT NULL,
  priority_id TINYINT UNSIGNED NOT NULL,
  asset_id INT UNSIGNED NULL,
  building_id INT UNSIGNED NULL,
  floor_id INT UNSIGNED NULL,
  location_id INT UNSIGNED NULL,
  location_text VARCHAR(150) NULL,
  reporter_id INT UNSIGNED NULL,
  reporter_name VARCHAR(150) NOT NULL,
  reporter_department_id INT UNSIGNED NULL,
  guest_impact VARCHAR(20) NOT NULL DEFAULT 'NONE',
  est_interruption_minutes INT UNSIGNED NULL,
  notes TEXT NULL,
  assigned_to INT UNSIGNED NULL,
  requested_at DATETIME NOT NULL,
  scheduled_start DATETIME NULL,
  scheduled_end DATETIME NULL,
  sla_response_due DATETIME NULL,
  sla_resolution_due DATETIME NULL,
  acknowledged_at DATETIME NULL,
  started_at DATETIME NULL,
  technically_completed_at DATETIME NULL,
  verified_at DATETIME NULL,
  verified_by INT UNSIGNED NULL,
  closed_at DATETIME NULL,
  cancelled_at DATETIME NULL,
  cancel_reason VARCHAR(500) NULL,
  reopen_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  waiting_seconds INT UNSIGNED NOT NULL DEFAULT 0,
  waiting_since DATETIME NULL,
  overdue_notified_at DATETIME NULL,
  estimated_cost DECIMAL(14,2) NULL,
  cost_parts DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  cost_labor DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  cost_contractor DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  cost_other DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  cost_total DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  is_demo TINYINT(1) NOT NULL DEFAULT 0,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ticket_no (ticket_no),
  UNIQUE KEY uq_ticket_id_hotel (id, hotel_id),
  KEY idx_ticket_hotel_status (hotel_id, status),
  KEY idx_ticket_hotel_req (hotel_id, requested_at),
  KEY idx_ticket_assignee (assigned_to, status),
  KEY idx_ticket_asset (asset_id, hotel_id),
  KEY idx_ticket_due (sla_resolution_due),
  KEY idx_ticket_sched (scheduled_start),
  KEY idx_ticket_reporter (reporter_id),
  KEY idx_ticket_loc (location_id, hotel_id),
  KEY idx_ticket_bld (building_id, hotel_id),
  KEY idx_ticket_floor (floor_id, hotel_id),
  KEY idx_ticket_dept (reporter_department_id, hotel_id),
  CONSTRAINT chk_ticket_status CHECK (status IN ('NEW','PENDING_REVIEW','ASSIGNED','IN_PROGRESS','WAITING_PARTS','WAITING_CONTRACTOR','WAITING_APPROVAL','PENDING_VERIFICATION','COMPLETED','CANCELLED','REOPENED')),
  CONSTRAINT chk_ticket_type CHECK (ticket_type IN ('CORRECTIVE','PREVENTIVE')),
  CONSTRAINT chk_ticket_impact CHECK (guest_impact IN ('NONE','LOW','HIGH','ROOM_OUT_OF_ORDER')),
  CONSTRAINT fk_ticket_hotel FOREIGN KEY (hotel_id) REFERENCES hotels (id),
  CONSTRAINT fk_ticket_cat FOREIGN KEY (category_id) REFERENCES maintenance_categories (id),
  CONSTRAINT fk_ticket_prio FOREIGN KEY (priority_id) REFERENCES priorities (id),
  CONSTRAINT fk_ticket_asset FOREIGN KEY (asset_id, hotel_id) REFERENCES assets (id, hotel_id),
  CONSTRAINT fk_ticket_bld FOREIGN KEY (building_id, hotel_id) REFERENCES buildings (id, hotel_id),
  CONSTRAINT fk_ticket_floor FOREIGN KEY (floor_id, hotel_id) REFERENCES floors (id, hotel_id),
  CONSTRAINT fk_ticket_loc FOREIGN KEY (location_id, hotel_id) REFERENCES locations (id, hotel_id),
  CONSTRAINT fk_ticket_dept FOREIGN KEY (reporter_department_id, hotel_id) REFERENCES departments (id, hotel_id),
  CONSTRAINT fk_ticket_reporter FOREIGN KEY (reporter_id) REFERENCES users (id),
  CONSTRAINT fk_ticket_assignee FOREIGN KEY (assigned_to) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ticket_assignments (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  ticket_id INT UNSIGNED NOT NULL,
  hotel_id INT UNSIGNED NOT NULL,
  technician_id INT UNSIGNED NOT NULL,
  assigned_by INT UNSIGNED NULL,
  assigned_at DATETIME NOT NULL,
  accepted_at DATETIME NULL,
  ended_at DATETIME NULL,
  is_current TINYINT(1) NOT NULL DEFAULT 1,
  note VARCHAR(500) NULL,
  PRIMARY KEY (id),
  KEY idx_ta_ticket (ticket_id, hotel_id),
  KEY idx_ta_tech (technician_id, is_current),
  CONSTRAINT fk_ta_ticket FOREIGN KEY (ticket_id, hotel_id) REFERENCES tickets (id, hotel_id),
  CONSTRAINT fk_ta_tech FOREIGN KEY (technician_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ticket_status_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ticket_id INT UNSIGNED NOT NULL,
  hotel_id INT UNSIGNED NOT NULL,
  from_status VARCHAR(30) NULL,
  to_status VARCHAR(30) NOT NULL,
  action VARCHAR(40) NOT NULL,
  note TEXT NULL,
  user_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_tsl_ticket (ticket_id, created_at),
  KEY idx_tsl_hotel (hotel_id, created_at),
  CONSTRAINT fk_tsl_ticket FOREIGN KEY (ticket_id, hotel_id) REFERENCES tickets (id, hotel_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ticket_comments (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  ticket_id INT UNSIGNED NOT NULL,
  hotel_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  body TEXT NOT NULL,
  is_internal TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_tc_ticket (ticket_id, hotel_id),
  CONSTRAINT fk_tc_ticket FOREIGN KEY (ticket_id, hotel_id) REFERENCES tickets (id, hotel_id),
  CONSTRAINT fk_tc_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ticket_attachments (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  ticket_id INT UNSIGNED NOT NULL,
  hotel_id INT UNSIGNED NOT NULL,
  stage VARCHAR(20) NOT NULL DEFAULT 'BEFORE',
  disk_path VARCHAR(255) NOT NULL,
  original_name VARCHAR(255) NOT NULL,
  mime VARCHAR(100) NOT NULL,
  size_bytes INT UNSIGNED NOT NULL,
  uploaded_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_tatt_ticket (ticket_id, hotel_id),
  CONSTRAINT chk_tatt_stage CHECK (stage IN ('BEFORE','AFTER','DOCUMENT')),
  CONSTRAINT fk_tatt_ticket FOREIGN KEY (ticket_id, hotel_id) REFERENCES tickets (id, hotel_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Hourly labour rate per technician per hotel, effective-dated
CREATE TABLE technician_rates (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  hotel_id INT UNSIGNED NOT NULL,
  hourly_rate DECIMAL(10,2) NOT NULL,
  effective_from DATE NOT NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_rate (user_id, hotel_id, effective_from),
  CONSTRAINT fk_rate_user FOREIGN KEY (user_id) REFERENCES users (id),
  CONSTRAINT fk_rate_hotel FOREIGN KEY (hotel_id) REFERENCES hotels (id),
  CONSTRAINT chk_rate_pos CHECK (hourly_rate >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE work_logs (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  ticket_id INT UNSIGNED NOT NULL,
  hotel_id INT UNSIGNED NOT NULL,
  technician_id INT UNSIGNED NOT NULL,
  started_at DATETIME NOT NULL,
  ended_at DATETIME NOT NULL,
  hours DECIMAL(7,2) NOT NULL,
  description TEXT NOT NULL,
  labor_rate DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  labor_cost DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_wl_ticket (ticket_id, hotel_id),
  KEY idx_wl_tech (technician_id, started_at),
  CONSTRAINT fk_wl_ticket FOREIGN KEY (ticket_id, hotel_id) REFERENCES tickets (id, hotel_id),
  CONSTRAINT fk_wl_tech FOREIGN KEY (technician_id) REFERENCES users (id),
  CONSTRAINT chk_wl_hours CHECK (hours > 0 AND hours <= 24)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ticket_approvals (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  ticket_id INT UNSIGNED NOT NULL,
  hotel_id INT UNSIGNED NOT NULL,
  approval_type VARCHAR(20) NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'PENDING',
  amount DECIMAL(14,2) NULL,
  note VARCHAR(1000) NULL,
  requested_by INT UNSIGNED NULL,
  requested_at DATETIME NOT NULL,
  decided_by INT UNSIGNED NULL,
  decided_at DATETIME NULL,
  decision_note VARCHAR(1000) NULL,
  PRIMARY KEY (id),
  KEY idx_tapp_ticket (ticket_id, hotel_id),
  KEY idx_tapp_status (hotel_id, status),
  CONSTRAINT chk_tapp_type CHECK (approval_type IN ('COMPLETION','WORK','PARTS','CONTRACTOR')),
  CONSTRAINT chk_tapp_status CHECK (status IN ('PENDING','APPROVED','REJECTED','CANCELLED')),
  CONSTRAINT fk_tapp_ticket FOREIGN KEY (ticket_id, hotel_id) REFERENCES tickets (id, hotel_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
