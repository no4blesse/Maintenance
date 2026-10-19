-- =====================================================================
-- 004 PREVENTIVE MAINTENANCE
-- Each generated PM work order is backed by a PREVENTIVE ticket so it
-- flows through the same workflow, calendar, costs and KPI logic.
-- UNIQUE(pm_plan_id, due_date) makes cron generation idempotent.
-- =====================================================================

CREATE TABLE pm_plans (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  hotel_id INT UNSIGNED NOT NULL,
  plan_no VARCHAR(30) NOT NULL,
  asset_id INT UNSIGNED NULL,
  category_id INT UNSIGNED NOT NULL,
  priority_id TINYINT UNSIGNED NOT NULL,
  task_name VARCHAR(200) NOT NULL,
  description TEXT NULL,
  frequency VARCHAR(20) NOT NULL,
  interval_days INT UNSIGNED NULL,
  planned_minutes INT UNSIGNED NOT NULL DEFAULT 60,
  assigned_to INT UNSIGNED NULL,
  start_date DATE NOT NULL,
  next_due_date DATE NOT NULL,
  lead_days INT UNSIGNED NOT NULL DEFAULT 0,
  last_generated_due DATE NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  is_demo TINYINT(1) NOT NULL DEFAULT 0,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pm_no (plan_no),
  UNIQUE KEY uq_pm_id_hotel (id, hotel_id),
  KEY idx_pm_due (is_active, next_due_date),
  KEY idx_pm_asset (asset_id, hotel_id),
  CONSTRAINT chk_pm_freq CHECK (frequency IN ('DAILY','WEEKLY','MONTHLY','QUARTERLY','SEMIANNUAL','YEARLY','CUSTOM')),
  CONSTRAINT chk_pm_custom CHECK (frequency <> 'CUSTOM' OR (interval_days IS NOT NULL AND interval_days > 0)),
  CONSTRAINT fk_pm_hotel FOREIGN KEY (hotel_id) REFERENCES hotels (id),
  CONSTRAINT fk_pm_asset FOREIGN KEY (asset_id, hotel_id) REFERENCES assets (id, hotel_id),
  CONSTRAINT fk_pm_cat FOREIGN KEY (category_id) REFERENCES maintenance_categories (id),
  CONSTRAINT fk_pm_prio FOREIGN KEY (priority_id) REFERENCES priorities (id),
  CONSTRAINT fk_pm_user FOREIGN KEY (assigned_to) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE pm_checklist_items (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  pm_plan_id INT UNSIGNED NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  label VARCHAR(255) NOT NULL,
  value_type VARCHAR(10) NOT NULL DEFAULT 'CHECK',
  unit VARCHAR(20) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  KEY idx_pci_plan (pm_plan_id, sort_order),
  CONSTRAINT chk_pci_type CHECK (value_type IN ('CHECK','NUMBER','TEXT')),
  CONSTRAINT fk_pci_plan FOREIGN KEY (pm_plan_id) REFERENCES pm_plans (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE pm_work_orders (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  hotel_id INT UNSIGNED NOT NULL,
  pm_plan_id INT UNSIGNED NOT NULL,
  ticket_id INT UNSIGNED NOT NULL,
  due_date DATE NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'OPEN',
  completed_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pmwo_due (pm_plan_id, due_date),
  UNIQUE KEY uq_pmwo_ticket (ticket_id),
  KEY idx_pmwo_hotel (hotel_id, due_date),
  CONSTRAINT chk_pmwo_status CHECK (status IN ('OPEN','COMPLETED','CANCELLED')),
  CONSTRAINT fk_pmwo_plan FOREIGN KEY (pm_plan_id, hotel_id) REFERENCES pm_plans (id, hotel_id),
  CONSTRAINT fk_pmwo_ticket FOREIGN KEY (ticket_id, hotel_id) REFERENCES tickets (id, hotel_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE pm_checklist_results (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  pm_work_order_id INT UNSIGNED NOT NULL,
  checklist_item_id INT UNSIGNED NOT NULL,
  is_done TINYINT(1) NOT NULL DEFAULT 0,
  value_text VARCHAR(255) NULL,
  comment VARCHAR(500) NULL,
  is_defect TINYINT(1) NOT NULL DEFAULT 0,
  recorded_by INT UNSIGNED NULL,
  recorded_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pcr (pm_work_order_id, checklist_item_id),
  CONSTRAINT fk_pcr_wo FOREIGN KEY (pm_work_order_id) REFERENCES pm_work_orders (id),
  CONSTRAINT fk_pcr_item FOREIGN KEY (checklist_item_id) REFERENCES pm_checklist_items (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
