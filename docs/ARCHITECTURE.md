# Architecture

## Request flow

`public/index.php` → bootstrap (Composer, env, config, locale) → secure session → Application → Router → middleware → Controller → Service → PDO → View/Response

Controller รับข้อมูลและตรวจ validation/permission; Service คุม workflow, transaction และ ledger; View escape ค่าด้วย `e()`; JSON ฝังในหน้าใช้ `UI::json()` เพื่อป้องกันการปิด script tag

`ModuleController` รวมการ render รายการ/ฟอร์ม ลดโค้ดซ้ำ แต่ SQL, allowlist และ business rules ยังอยู่ในแต่ละโมดูล ไม่รับชื่อตารางหรือคอลัมน์ SQL จาก browser โดยตรง

## Hotel isolation

Role assignments เป็น many-to-many ต่อ user/hotel ค่า hotel_id=NULL ใช้เฉพาะบทบาทระดับกลุ่ม `Permissions` คำนวณสิทธิ์จาก role_permissions ทุก request โรงแรมที่ปิดใช้ถูกตัดออก

`HotelContext::scope(permission)` intersect โรงแรมที่อนุญาตกับ hotel switcher; การเลือกโรงแรมทำได้เพียงลดขอบเขต ไม่เพิ่มสิทธิ์

`TicketPolicy` รวมสิทธิ์ tickets.view, tickets.view_own และ tickets.work (assigned jobs) พร้อมขอบเขตโรงแรม Lookup ที่ไม่มีสิทธิ์คืน 404 เพื่อไม่ยืนยันว่ามีใบงานนั้นจริง อุปกรณ์ เอกสาร API และธุรกรรมอื่นตรวจ permission ที่ hotel ของ record บน server อีกครั้ง Export intersect ทั้งสิทธิ์ข้อมูลและ reports.export

แค็ตตาล็อกอะไหล่และประเภทเป็นข้อมูลกลาง ยอดสต็อก ต้นทุน และ movement แยกรายโรงแรม ผู้ดูแลคลังรายโรงแรมแก้ยอดผ่าน transaction และตั้ง threshold ได้ การแก้ master catalog กลางสงวนให้ global inventory.manage

## Modules and integration

- Tickets: audit timeline, assignment, workflow, work logs, files, approvals, parts
- Assets: status/history, private documents/images, QR token ที่ยังต้อง login/authorize
- Inventory: weighted average valuation, receive/issue/return/count, transfers, purchase requests
- PM: anchor-date recurrence, work-order generation, frozen checklist definition/results, corrective defects
- Calendar: tickets ทั้ง corrective/preventive; schedule edits persist พร้อม log
- Reports: request cohorts, due-date PM cohort, expense-date ledger, CSV และ print/PDF ผ่าน browser
- Finance: approved cost ledger และ budget views; ไม่ใช้ยอดซื้อสินค้าซ้ำกับค่าใช้วัสดุ
- Administration: users/roles/labor rates, master data, hotel/SLA/settings, audit
- LINE: raw-body signature → group discovery → explicit binding → queue → worker → delivery log

## Transactions and concurrency

PDO native prepared statements; internal UTC; DECIMAL + bcmath สำหรับเงิน; nested transaction ใช้ SAVEPOINT การสร้างเลขใช้ atomic upsert + connection-local LAST_INSERT_ID

สต็อกใช้ exclusive upsert แล้ว SELECT FOR UPDATE เพื่อหลีกเลี่ยง shared-lock upgrade deadlock ที่พบในการทดสอบ การตัด/คืน/โอนและ ledger เกิดใน transaction เดียวกัน PM ล็อก plan และมี UNIQUE(plan,due_date) Worker ใช้ atomic claim, stable retry key และตรวจว่า destination ยังผูกโรงแรมเดิม

ไม่ retry transaction ที่เกี่ยวกับไฟล์อัตโนมัติ หากเกิด DB deadlock ที่ซับซ้อนจะ rollback และคืน error; ผู้ใช้ต้องลองใหม่ ธุรกรรมที่ rollback หลังย้ายไฟล์อาจเหลือไฟล์ไม่มี record อ้างอิง ต้องตรวจ orphan files เป็นงานบำรุงรักษา

## Background jobs

เรียก CLI เท่านั้น: cron/generate_pm.php, check_overdue.php, check_inventory.php, process_notifications.php ใช้ file lock กันงานชื่อเดียวกันบน host เดียว และ DB locks/unique constraints กัน duplication ระดับข้อมูล

ไม่มี Node backend, daemon หรือ queue broker ที่ต้องเปิดตลอดเวลา เหมาะกับ PHP shared hosting

## Limits

รายการทั่วไป cap 500–1,000 แถว และ export cap 10,000 แถว; ใบงานมี server-side pagination ต้องแบ่งช่วงวันที่/โรงแรมสำหรับชุดข้อมูลใหญ่ การแปลอังกฤษเป็นแบบ dictionary + Thai fallback ยังไม่ครบทุก label ใหม่ ยังไม่มี password-reset email flow หรือ distributed cron lock และยังไม่ทดสอบ physical mobile devices
