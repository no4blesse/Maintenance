# Implementation progress

วันที่: 19 ตุลาคม 2026 (Asia/Bangkok)

## สรุปสถานะ

โค้ดโมดูลหลักเชื่อมต่อกันและทดสอบในเครื่องได้แล้ว เพิ่มจากงานเดิมโดยไม่เริ่มเขียนระบบใหม่ ไม่มีการ deploy production หรือส่งข้อความ LINE จริง การตรวจรับบน MySQL 8, hosting และมือถือจริงยังไม่เสร็จ จึงยังไม่ประกาศว่าผ่าน strict production acceptance ทั้งหมด

## Phase 1 — Foundation

- คง MVC/Router/PDO/Auth/RBAC/HotelContext เดิม ตรวจ migration และ reference seed
- เพิ่ม forced password change, private local preview router, environment doctor, PHPUnit และ MySQL8 CI definition
- ปรับ nested transactions ใช้ SAVEPOINT และแยก SET NAMES/SET time_zone ให้ชัดเจน
- โครงสร้างโรงแรม/สิทธิ์/ข้อมูลอ้างอิงครบ ไม่มี universal default password
- Validation: syntax, Composer validation/platform checks และ migration บน MariaDB ผ่าน
- อัปเดตตามคำขอภายหลัง: สร้างบัญชี super@sungroup.local ระดับ SUPER_ADMIN ในฐาน operational แล้ว พร้อม local recovery route; ยังต้องยืนยัน MySQL8 staging

## Phase 2 — Dashboard and work orders

- เพิ่ม app/Views/tickets/show.php เชื่อม workflow actions, comments, before/after files, work logs, part issue/reversal, approvals, PM inspection และ status history
- เพิ่มการสร้างนัดจากวันที่เลือกในปฏิทินสำหรับผู้มีสิทธิ์; export intersect reports.export ต่อโรงแรม
- คง Dashboard/query/charts เดิม ใช้ข้อมูลฐานจริง ไม่มีการเติมสถิติ production ปลอม
- Tests: full repair journey, self-verification denial, hotel isolation, cost ledger และ exports ผ่าน
- งานเหลือ: ตรวจ physical mobile/กล้องอัปโหลด และ operational user acceptance

## Phase 3 — Assets, inventory, PM, calendar

- เพิ่ม AssetController, InventoryController, PMController, CalendarController และ View/JS ที่เกี่ยวข้อง
- Asset register, status/history, private files/image, QR labels พร้อม login/authorization
- Per-hotel stock, receive/issue/return/count, movements/CSV, threshold/bin, suppliers, transfer approval/receipt, purchase request approval/receipt
- แก้ deadlock จาก INSERT IGNORE+FOR UPDATE เป็น exclusive upsert ใน InventoryService
- PM recurrence/generation/checklists/defects ใช้ Service เดิม เพิ่ม checklist completion gate และ snapshot
- ตารางใหม่: **pm_work_order_checklist** ใน migration 007 ไม่มีการ drop ตารางเดิม
- FullCalendar อ่าน tickets/PM, filter, edit schedule + audit, persist drop/resize ผ่าน API
- Tests: concurrent ticket creation และ stock issues, transfer replay, purchase receipt replay, PM idempotency/history, calendar permissions ผ่าน
- งานเหลือ: multi-line purchase/transfer UI (ปัจจุบันหนึ่ง item/เอกสาร); drag/resize browser acceptance จริง

## Phase 4 — KPI, costs, budgets, LINE

- เพิ่ม KPIController/CostController/LineBotController และ reports/settings views
- Real KPI cohort, hotel/technician comparison, charts, CSV และ print/Save-as-PDF
- Manual expenses, private supporting documents, approve/reject/void, budget vs approved ledger
- LINE signed webhook/group discovery/binding/rules/queue/retry/logs/test UI
- ตรวจ destination hotel ตอน worker ส่ง ป้องกันคิวเก่ารั่วหลัง remap กลุ่ม; webhook event processing เป็น transaction
- เพิ่ม AlertService และ cron ทั้ง 4 งานสำหรับ overdue, upcoming PM, daily summary, low stock, generation และ queue worker
- Tests: KPI formulas, manual approval, signature/dedupe/routing/retry/remap และ alert idempotency ผ่านด้วย MOCK
- งานเหลือ: LINE credentials/public HTTPS และการส่งจริงยังไม่ยืนยัน

## Phase 5 — Hardening and documentation

- เพิ่ม UserController/SettingsController, profile/password, per-hotel roles, rates, global-admin safeguards, hotel/master/SLA/audit UI
- ป้องกันแก้ master catalog ส่วนกลางด้วยสิทธิ์ property-only, CSV formula injection, malformed validation fields, oversize cost totals
- เพิ่ม scripts/doctor.php, demo.php (explicit only), dev_router.php, build-schema.php
- เอกสาร README, ARCHITECTURE, DATABASE, PERMISSIONS, KPI_DEFINITIONS, LINE_INTEGRATION, DEPLOYMENT, TESTING, USER_GUIDE และ progress นี้
- Composer lock เพิ่ม PHP CS Fixer สำหรับจัดรูปแบบ development; production ใช้ composer install --no-dev
- งานเหลือ: target-host security/performance/backup recovery review และการแปลอังกฤษให้ครบทุก label ใหม่

## Verified checks

| Check | ผลที่ยืนยันแล้ว |
|---|---|
| Original code syntax | 83 files / 0 problems ก่อนพัฒนา |
| Current PHP syntax | 121 files / 0 problems หลังเพิ่ม local recovery |
| Migrations | 7 ชุดบน MariaDB 10.4.32; migration007 เพิ่มทั้งฐาน local operational และฐาน test |
| PHPUnit PHP8.4 | 27 tests / 168 assertions ผ่าน หลังเพิ่ม local recovery |
| PHPUnit PHP8.2 | 25 tests / 144 assertions ผ่าน |
| Composer validate | ผ่าน |
| Composer platform requirements PHP8.4 | ผ่าน |
| Composer audit | ไม่พบ advisory ณ เวลาตรวจ |
| Frontend build | npm run build ผ่าน และคัดลอก assets local ครบ |
| JavaScript syntax | calendar/labels/modules/reports ผ่าน node --check |
| Browser | login, dashboard, KPI, calendar, hotel-dependent form และ asset create ผ่าน |
| Browser console | ไม่พบ error/warn ใน Dashboard/KPI/Calendar ที่ตรวจ |
| Operational DB | extensions/assets/writable paths/DB/4hotels ผ่าน; สร้าง active Super Admin ตามคำขอภายหลังแล้ว |

## Strict acceptance status

| เกณฑ์ | สถานะ |
|---|---|
| PHP project/schema/migrations/DB connection | ผ่านใน local MariaDB |
| Authentication/permissions/4 hotels/isolation | implemented + automated checks ผ่าน |
| Dashboard/hotel switcher | implemented; dashboard browser ผ่าน; switcher enforcement automated |
| Request/assignment/status/verification | integrated journey ผ่าน |
| Calendar + tickets/PM | implemented + API tests/browser read ผ่าน; drag UI pending |
| Assets/QR/private access | implemented; form browser + isolation tests ผ่าน |
| Inventory/movements/ticket parts/transfers | implemented + transaction/concurrency/replay checks ผ่าน |
| PM scheduling/history | implemented + idempotency/snapshot tests ผ่าน |
| KPI/costs/budgets | implemented + formula/ledger/approval checks ผ่าน |
| LINE API/group mapping/queue | implemented + MOCK tests ผ่าน; live acceptance pending |
| Responsive/mobile | responsive CSS/forms implemented; physical mobile pending |
| Validation/security | implemented + focused tests ผ่าน; external review pending |
| Automated tests/install/deploy docs | มีครบและรัน local แล้ว; hosting deployment pending |
| No silent fake production data | migrate seed ไม่มี operational demo; demo ต้อง --demo โดย explicit |
| All production criteria complete | **ยังไม่ครบ** ตาม external acceptance ที่ระบุ |

## Notes for continuation

1. อ่าน TESTING.md และ DEPLOYMENT.md ก่อนตั้ง staging
2. บัญชี super@sungroup.local พร้อมสิทธิ์สูงสุดในฐาน local operational แล้ว ตั้งรหัสผ่านของตนจาก local recovery/profile หรือใช้ scripts/create_admin.php เมื่อตั้งระบบใหม่
3. รัน MySQL8/PHP8.4 suite บน staging/CI และทดสอบมือถือจริง
4. ให้เจ้าของ LINE OA ใส่ credentials ใน private .env แล้วตรวจ public webhook + กลุ่มโรงแรมจริง
5. ทดสอบ backup/restore และรับ user acceptance ก่อนขออนุมัติ deploy production

Known limitations: English fallback ยังไม่ครบ, รายการ/exports มี caps, one-item purchase/transfer forms, browser Save-as-PDF ไม่มี server PDF engine, อาจต้อง cleanup orphan uploads หลัง DB rollback, MySQL DDL migration ไม่มี automatic rollback

## Local recovery requested after initial delivery

เพิ่ม `/login/Super?token=<configured-local-token>` และ `&reset=1` ใช้บัญชี super@sungroup.local ระดับ SUPER_ADMIN ทุกโรงแรม เปิดเฉพาะ private .env ของเครื่องนี้และโหมด development ตรวจ loopback IP + localhost Host และปิดใช้งานใน production โดยอัตโนมัติ Token hash อยู่ใน private config ไม่อยู่ใน source หลังเข้ามี password-reset session grant 10 นาทีและใช้ครั้งเดียวเมื่อเปลี่ยนสำเร็จ มี audit และ rate limit

ไฟล์หลัก: config/local_access.php, app/Services/LocalSuperAccess.php, AuthController/UserController/Auth, routes/web.php และ users/profile.php ทดสอบ recovery creation/role/idempotency/password reset/expiry รวมถึง production/remote/host/disabled/wrong token/rate limit ปฏิเสธได้ตามคาด Full suite PHP8.4 ผ่าน 27 tests /168 assertions; PHP8.2 ทดสอบ recovery เพิ่ม 2 tests /24 assertions ผ่าน HTTP preview เข้าบัญชีจริงและเปิดฟอร์มตั้งรหัสใหม่โดยไม่กรอกรหัสเก่าได้ ไม่ได้เปลี่ยนรหัสผ่านให้ผู้ใช้แทน
# ชุดสาธิตเพิ่มเติม · 19 ตุลาคม 2026

เพิ่มหน้า `/demo` พร้อมคำอธิบายและลิงก์ไปยังตัวอย่างจริง สร้างชุดข้อมูลในฐานทดสอบ 4 โรงแรม 67 ใบงาน 8 แผน PM 6 อะไหล่ 4 คำขอซื้อ 3 การโอน และประวัติต้นทุนย้อนหลัง 6 เดือน ข้อมูลเดิมยังอยู่ รันซ้ำไม่สร้างซ้ำ ดูลำดับพาชมใน [DEMO_GUIDE.md](DEMO_GUIDE.md)

ตรวจ 88 URL ผ่านทั้งหมด ยอดต้นทุนใบงานตรงกับ ledger ทุกใบ ตรวจหน้าเว็บและใบงานครบวงจรผ่านเบราว์เซอร์ ผล PHPUnit PHP 8.4.24 ล่าสุด: **29 tests, 183 assertions ผ่านทั้งหมด** เพิ่มทดสอบขอบเขตโรงแรม สิทธิ์ และการปิดหน้าสาธิตนอกฐานทดสอบ แก้ assertion เดิมให้ทดสอบปลายทาง LINE และงานแจ้งเตือนที่สร้างเองโดยรองรับข้อมูลในฐานที่มีอยู่แล้ว

# หน้าแจ้งซ่อมสาธารณะ · 19 ตุลาคม 2026

เพิ่ม `/repair` สำหรับลูกค้าและพนักงานไม่มีบัญชี แยกหน้าจากหลังบ้าน เลือกโรงแรม ระบุสถานที่และช่องทางติดต่อ แนบรูปได้ 3 รูป และรับเลขอ้างอิงหลังส่ง สร้างใบงาน NEW ผ่าน workflow และการแจ้งเตือนเดิม ผู้ดูแลเห็นแหล่งที่มาและช่องทางติดต่อเฉพาะในใบงานที่มีสิทธิ์ดู

เพิ่ม migration 008 และใช้แล้วทั้งฐาน operational และฐานทดสอบ (เพิ่มตาราง ไม่มีการล้างข้อมูล) มี CSRF, nonce ป้องกันรายการซ้ำ, honeypot, การจำกัดจำนวนต่อ IP และ private attachment ไม่มี public endpoint สำหรับเปิดใบงานของคนอื่น อ่านการใช้งานและการติดตั้งใน [PUBLIC_REPAIRS.md](PUBLIC_REPAIRS.md)

ผล PHPUnit ทั้งชุด PHP 8.4.24: **33 tests, 217 assertions ผ่าน** ทดสอบส่วน public เพิ่มบน PHP 8.2.12: **4 tests, 34 assertions ผ่าน** ส่งแบบฟอร์มผ่าน browser สำเร็จเป็น `NT-2026-000018` ในฐานทดสอบ ตรวจยืนยัน reporter_id และ created_by เป็น NULL, สถานะ NEW, is_demo=1 และเปิดจากหลังบ้านได้ HTTP 200

# แยกหน้าแจ้งซ่อมตามโรงแรม · 19 ตุลาคม 2026

ผลทดสอบรวมล่าสุด PHP 8.4.24: **34 tests, 238 assertions ผ่านทั้งหมด**

เพิ่มลิงก์เฉพาะ `/repair/NT`, `/repair/CC`, `/repair/LS`, `/repair/SG` แสดงชื่อโรงแรมชัดเจนและตัดตัวเลือกโรงแรมออก กำหนด hotel_id จาก URL ฝั่งเซิร์ฟเวอร์เท่านั้น แยก nonce/receipt ต่อโรงแรมเพื่อเปิดหลายแท็บได้ ปุ่มแจ้งเพิ่มและโลโก้คงโรงแรมเดิม ลิงก์หลังบ้านเมื่อเลือก workspace โรงแรมเดียวจะเปิดตรงโรงแรมนั้น หน้า `/repair` เป็นสารบัญลิงก์ และลิงก์เดิมแบบ query hotel_id redirect ไปหน้ารายโรงแรม

ตรวจ HTTP ทั้ง 4 หน้าได้ 200 ไม่มีช่อง hotel_id ทดสอบส่งค่าโรงแรมอื่นและใช้ nonce ข้ามโรงแรม รวมถึงโรงแรมที่ปิดใช้งาน ผ่านบน PHP 8.2.12 (5 tests, 55 assertions) ไม่เปลี่ยนข้อมูลใบงานเดิมหรือ schema

# ช่างรับงานด้วยบัญชีตนเองและเวลาอัตโนมัติ · 19 ตุลาคม 2026

หน้าใบงานใช้ปุ่ม “รับเรื่องและเริ่มงาน” แทนรับเรื่อง/มอบหมายช่าง/เลือกนัดหมาย ผู้มี tickets.work ในโรงแรมรับงานว่างได้ ระบบผูกผู้รับงานกับ Auth::id และบันทึก assignment, accepted_at, started_at และ scheduled_start จากเวลาฝั่งเซิร์ฟเวอร์ เปลี่ยนสถานะเป็น IN_PROGRESS ใน transaction ที่ล็อกใบงาน ป้องกันรับซ้ำหรือรับแทนผู้รับผิดชอบเดิม

เมื่อแจ้งซ่อมเสร็จบันทึก technically_completed_at และ scheduled_end เวลาเดียวกัน เวลาปิดงานเป็นอีกช่องสำหรับผู้ตรวจรับคนละคน ปฏิเสธตรวจรับล้างเวลาซ่อมเสร็จ เปิดใหม่เริ่มรอบเวลาใหม่พร้อม assignment history และไม่ให้ย้ายเวลาจริงผ่านปฏิทิน งานและ service เดิมที่ใช้มอบหมายยังรองรับเพื่อรักษาข้อมูลย้อนหลัง แต่ไม่แสดง action มอบหมายในหน้าปฏิบัติงาน และไม่ให้มอบหมายทับงานที่ช่างรับเองแล้ว ไม่มีการคิดค่าแรงจากเวลา elapsed ซ้ำกับ work log

ตรวจหน้าจอใบงาน 248 เห็นปุ่มรับเรื่องใหม่และไม่มีฟอร์มเลือกช่าง ผลทดสอบรวม PHP 8.4.24 **36 tests, 268 assertions ผ่าน** และทดสอบ workflow ใหม่บน PHP 8.2.12 **2 tests, 30 assertions ผ่าน** ไม่เปลี่ยน schema หรือย้อนแก้ใบงานเดิม

