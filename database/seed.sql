-- =====================================================================
-- SEED: reference data (safe to re-run - idempotent)
-- Contains NO demo/operational data and NO user accounts.
-- Demo data: php scripts/seed_demo.php (development only)
-- Admin:     php scripts/create_admin.php
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------- Hotels ----------------
INSERT INTO hotels (code, name_th, name_en, accent_color, sort_order) VALUES
('NT', 'โรงแรม นิว แทรเวิลลอดจ์', 'New Travellodge Hotel', '#C63040', 1),
('CC', 'เจ้าหลาว คาบาน่า รีสอร์ท', 'Chaolao Cabana Resort', '#E98631', 2),
('LS', 'แหลมสิงห์ เนเชอรัล บีช รีสอร์ท', 'Laemsing Natural Beach Resort', '#3089C7', 3),
('SG', 'สอยดาวกู๊ดวิว รีสอร์ท', 'Soidao Goodview Resort', '#348B61', 4)
ON DUPLICATE KEY UPDATE name_th = VALUES(name_th), name_en = VALUES(name_en), accent_color = VALUES(accent_color), sort_order = VALUES(sort_order);

-- ---------------- Roles ----------------
INSERT INTO roles (code, name_th, name_en, description, is_global_capable, sort_order) VALUES
('SUPER_ADMIN', 'ผู้ดูแลระบบสูงสุด', 'Super Admin', 'จัดการระบบทั้งหมดทุกโรงแรม', 1, 1),
('GROUP_EXECUTIVE', 'ผู้บริหารกลุ่ม', 'Group Executive', 'ดูภาพรวมและรายงานข้ามโรงแรม', 1, 2),
('HOTEL_MANAGER', 'ผู้จัดการโรงแรม', 'Hotel Manager', 'บริหารและอนุมัติในโรงแรมที่ได้รับมอบหมาย', 1, 3),
('CHIEF_ENGINEER', 'หัวหน้าช่าง', 'Chief Engineer', 'ตรวจสอบ มอบหมาย และบริหารงานซ่อม', 1, 4),
('TECHNICIAN', 'ช่างซ่อมบำรุง', 'Technician', 'ปฏิบัติงานซ่อมที่ได้รับมอบหมาย', 0, 5),
('STOREKEEPER', 'เจ้าหน้าที่คลังอะไหล่', 'Storekeeper', 'จัดการคลังอะไหล่', 0, 6),
('FINANCE', 'การเงิน', 'Finance', 'ตรวจสอบต้นทุน งบประมาณ และรายงานการเงิน', 1, 7),
('STAFF', 'พนักงานทั่วไป', 'Staff', 'แจ้งซ่อมและติดตามงานของตนเอง', 0, 8),
('EXTERNAL_CONTRACTOR', 'ผู้รับเหมาภายนอก', 'External Contractor', 'เข้าถึงเฉพาะงานที่ได้รับมอบหมาย', 0, 9)
ON DUPLICATE KEY UPDATE name_th = VALUES(name_th), name_en = VALUES(name_en), description = VALUES(description), is_global_capable = VALUES(is_global_capable), sort_order = VALUES(sort_order);

-- ---------------- Permissions ----------------
INSERT INTO permissions (code, module, description_th) VALUES
('dashboard.view', 'dashboard', 'ดูแดชบอร์ด'),
('tickets.create', 'tickets', 'สร้างใบแจ้งซ่อม'),
('tickets.view', 'tickets', 'ดูใบแจ้งซ่อมทั้งหมดของโรงแรม'),
('tickets.view_own', 'tickets', 'ดูใบแจ้งซ่อมที่ตนเองแจ้ง'),
('tickets.update', 'tickets', 'แก้ไขรายละเอียดใบแจ้งซ่อม'),
('tickets.assign', 'tickets', 'ตรวจสอบและมอบหมายช่าง'),
('tickets.work', 'tickets', 'ปฏิบัติงานซ่อมที่ได้รับมอบหมาย'),
('tickets.verify', 'tickets', 'ตรวจรับ/ปิดงาน/เปิดงานใหม่'),
('tickets.cancel', 'tickets', 'ยกเลิกใบแจ้งซ่อม'),
('calendar.view', 'calendar', 'ดูปฏิทินงาน'),
('calendar.reschedule', 'calendar', 'เลื่อนนัดหมายงาน'),
('inventory.view', 'inventory', 'ดูคลังอะไหล่'),
('inventory.manage', 'inventory', 'จัดการรายการอะไหล่และผู้จำหน่าย'),
('inventory.receive', 'inventory', 'รับอะไหล่เข้าคลัง'),
('inventory.issue', 'inventory', 'เบิกอะไหล่'),
('inventory.adjust', 'inventory', 'ปรับปรุง/ตรวจนับสต็อก'),
('inventory.transfer', 'inventory', 'ขอโอน/รับโอนอะไหล่ระหว่างโรงแรม'),
('inventory.transfer_approve', 'inventory', 'อนุมัติการโอนอะไหล่'),
('purchase.request', 'inventory', 'สร้างใบขอซื้อ'),
('purchase.approve', 'inventory', 'อนุมัติใบขอซื้อ'),
('assets.view', 'assets', 'ดูทะเบียนอุปกรณ์'),
('assets.create', 'assets', 'เพิ่มอุปกรณ์'),
('assets.update', 'assets', 'แก้ไขอุปกรณ์'),
('pm.view', 'pm', 'ดูแผนบำรุงรักษา'),
('pm.manage', 'pm', 'จัดการแผนบำรุงรักษา'),
('reports.view', 'reports', 'ดูรายงาน KPI'),
('reports.export', 'reports', 'ส่งออกรายงาน'),
('costs.view', 'costs', 'ดูต้นทุน'),
('costs.create', 'costs', 'บันทึกค่าใช้จ่าย'),
('costs.approve', 'costs', 'อนุมัติค่าใช้จ่าย'),
('budgets.manage', 'costs', 'จัดการงบประมาณ'),
('users.manage', 'admin', 'จัดการผู้ใช้งานและสิทธิ์'),
('hotels.manage', 'admin', 'จัดการข้อมูลโรงแรม'),
('line.manage', 'admin', 'จัดการ LINE Bot'),
('settings.manage', 'admin', 'จัดการตั้งค่าระบบ'),
('audit.view', 'admin', 'ดูบันทึกการใช้งาน')
ON DUPLICATE KEY UPDATE module = VALUES(module), description_th = VALUES(description_th);

-- ---------------- Role -> Permission matrix ----------------
-- SUPER_ADMIN: everything
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p WHERE r.code = 'SUPER_ADMIN';

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p ON p.code IN (
 'dashboard.view','tickets.view','calendar.view','inventory.view','assets.view','pm.view',
 'reports.view','reports.export','costs.view','audit.view'
) WHERE r.code = 'GROUP_EXECUTIVE';

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p ON p.code IN (
 'dashboard.view','tickets.create','tickets.view','tickets.update','tickets.assign','tickets.verify','tickets.cancel',
 'calendar.view','calendar.reschedule','inventory.view','inventory.transfer_approve','purchase.approve',
 'assets.view','pm.view','reports.view','reports.export','costs.view','costs.create','costs.approve','budgets.manage','audit.view'
) WHERE r.code = 'HOTEL_MANAGER';

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p ON p.code IN (
 'dashboard.view','tickets.create','tickets.view','tickets.update','tickets.assign','tickets.work','tickets.verify','tickets.cancel',
 'calendar.view','calendar.reschedule','inventory.view','inventory.issue','inventory.transfer','purchase.request',
 'assets.view','assets.create','assets.update','pm.view','pm.manage','reports.view','reports.export','costs.view','costs.create'
) WHERE r.code = 'CHIEF_ENGINEER';

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p ON p.code IN (
 'dashboard.view','tickets.create','tickets.view','tickets.work','calendar.view','inventory.view','inventory.issue','assets.view','pm.view'
) WHERE r.code = 'TECHNICIAN';

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p ON p.code IN (
 'dashboard.view','tickets.create','tickets.view_own','inventory.view','inventory.manage','inventory.receive','inventory.issue',
 'inventory.adjust','inventory.transfer','purchase.request'
) WHERE r.code = 'STOREKEEPER';

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p ON p.code IN (
 'dashboard.view','inventory.view','reports.view','reports.export','costs.view','costs.create','costs.approve','budgets.manage','purchase.approve'
) WHERE r.code = 'FINANCE';

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p ON p.code IN ('tickets.create','tickets.view_own')
WHERE r.code = 'STAFF';

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p ON p.code IN ('tickets.work')
WHERE r.code = 'EXTERNAL_CONTRACTOR';

-- ---------------- Priorities & default SLA (minutes) ----------------
INSERT INTO priorities (code, name_th, name_en, color, level, response_minutes, resolution_minutes) VALUES
('LOW', 'ต่ำ', 'Low', '#64748B', 1, 480, 4320),
('NORMAL', 'ปกติ', 'Normal', '#2563EB', 2, 240, 1440),
('HIGH', 'สูง', 'High', '#F59E0B', 3, 60, 480),
('EMERGENCY', 'ฉุกเฉิน', 'Emergency', '#DC2626', 4, 15, 120)
ON DUPLICATE KEY UPDATE name_th = VALUES(name_th), name_en = VALUES(name_en), color = VALUES(color), level = VALUES(level);

-- ---------------- Maintenance categories ----------------
INSERT INTO maintenance_categories (code, name_th, name_en, icon, color, sort_order) VALUES
('AC', 'เครื่องปรับอากาศ', 'Air Conditioning', 'air-vent', '#0EA5E9', 1),
('ELEC', 'ระบบไฟฟ้า', 'Electrical', 'zap', '#F59E0B', 2),
('PLUMB', 'ระบบประปา', 'Plumbing', 'droplets', '#3B82F6', 3),
('CIVIL', 'อาคารและโครงสร้าง', 'Building & Civil', 'building-2', '#78716C', 4),
('FURN', 'เฟอร์นิเจอร์', 'Furniture', 'armchair', '#A16207', 5),
('APPL', 'เครื่องใช้ไฟฟ้า/TV', 'Appliances & TV', 'tv', '#8B5CF6', 6),
('POOL', 'สระว่ายน้ำ', 'Swimming Pool', 'waves', '#06B6D4', 7),
('KITCH', 'อุปกรณ์ครัว', 'Kitchen Equipment', 'chef-hat', '#EF4444', 8),
('SAFETY', 'ระบบความปลอดภัย/ดับเพลิง', 'Safety & Fire', 'shield-alert', '#DC2626', 9),
('GARDEN', 'ภูมิทัศน์', 'Landscape', 'trees', '#16A34A', 10),
('IT', 'ระบบไอที/เครือข่าย', 'IT & Network', 'wifi', '#6366F1', 11),
('OTHER', 'อื่น ๆ', 'Other', 'wrench', '#64748B', 99)
ON DUPLICATE KEY UPDATE name_th = VALUES(name_th), name_en = VALUES(name_en), icon = VALUES(icon), color = VALUES(color), sort_order = VALUES(sort_order);

-- ---------------- Asset categories (code is used in asset codes e.g. AC-NT-0305) ----------------
INSERT INTO asset_categories (code, name_th, name_en) VALUES
('AC', 'เครื่องปรับอากาศ', 'Air Conditioner'),
('PMP', 'ปั๊มน้ำ', 'Water Pump'),
('GEN', 'เครื่องกำเนิดไฟฟ้า', 'Generator'),
('POOL', 'อุปกรณ์สระว่ายน้ำ', 'Pool Equipment'),
('ELV', 'ลิฟต์', 'Elevator'),
('WH', 'เครื่องทำน้ำร้อน/บอยเลอร์', 'Water Heater / Boiler'),
('TV', 'โทรทัศน์', 'Television'),
('REF', 'ตู้เย็น/ห้องเย็น', 'Refrigeration'),
('FIRE', 'อุปกรณ์ดับเพลิง', 'Fire Equipment'),
('ELP', 'ตู้ควบคุมไฟฟ้า', 'Electrical Panel'),
('KIT', 'อุปกรณ์ครัว', 'Kitchen Equipment'),
('OTH', 'อื่น ๆ', 'Other')
ON DUPLICATE KEY UPDATE name_th = VALUES(name_th), name_en = VALUES(name_en);

-- ---------------- Cost categories ----------------
INSERT INTO cost_categories (code, name_th, name_en, is_system) VALUES
('PARTS', 'ค่าอะไหล่', 'Spare Parts', 1),
('LABOR', 'ค่าแรงช่าง', 'Technician Labor', 1),
('CONTRACTOR', 'ค่าผู้รับเหมาภายนอก', 'External Contractor', 0),
('REPLACEMENT', 'ค่าเปลี่ยนอุปกรณ์', 'Equipment Replacement', 0),
('OTHER', 'ค่าใช้จ่ายอื่น ๆ', 'Other Expenses', 0)
ON DUPLICATE KEY UPDATE name_th = VALUES(name_th), name_en = VALUES(name_en), is_system = VALUES(is_system);

-- ---------------- Part categories ----------------
INSERT INTO part_categories (name_th, name_en) VALUES
('ไฟฟ้าและแสงสว่าง', 'Electrical & Lighting'),
('ประปาและสุขภัณฑ์', 'Plumbing & Sanitary'),
('เครื่องปรับอากาศ', 'Air Conditioning'),
('สระว่ายน้ำ', 'Pool'),
('เคมีภัณฑ์', 'Chemicals'),
('อุปกรณ์ทั่วไป', 'General Hardware')
ON DUPLICATE KEY UPDATE name_en = VALUES(name_en);

-- ---------------- System settings ----------------
INSERT INTO settings (setting_key, setting_value, value_type) VALUES
('app_name', 'SUNGROUP Maintenance Center', 'string'),
('default_locale', 'th', 'string'),
('timezone', 'Asia/Bangkok', 'string'),
('date_format', 'd/m/Y', 'string'),
('use_buddhist_year', '0', 'bool'),
('currency', 'THB', 'string'),
('pagination_size', '20', 'int'),
('upload_max_mb', '10', 'int'),
('cost_auto_approve_below', '0', 'decimal'),
('kpi_repeat_window_days', '30', 'int'),
('kpi_mttr_exclude_waiting', '0', 'bool'),
('notify_daily_summary_hour', '8', 'int'),
('inventory_low_stock_cooldown_hours', '24', 'int'),
('pm_default_lead_days', '0', 'int'),
('pm_upcoming_notify_days', '1', 'int')
ON DUPLICATE KEY UPDATE setting_key = setting_key;

-- ---------------- Default notification rules (group-wide) ----------------
INSERT INTO notification_rules (hotel_id, event_type, is_enabled) VALUES
(NULL, 'TICKET_CREATED', 1),
(NULL, 'TICKET_EMERGENCY', 1),
(NULL, 'TICKET_ASSIGNED', 1),
(NULL, 'TICKET_OVERDUE', 1),
(NULL, 'TICKET_COMPLETED', 1),
(NULL, 'EXPENSE_APPROVAL', 1),
(NULL, 'STOCK_LOW', 1),
(NULL, 'PM_UPCOMING', 1),
(NULL, 'PM_OVERDUE', 1),
(NULL, 'DAILY_SUMMARY', 1)
ON DUPLICATE KEY UPDATE event_type = event_type;
