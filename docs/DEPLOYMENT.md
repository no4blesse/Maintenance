# Deployment and recovery

เป้าหมาย PHP 8.4 + MySQL 8.0.16+ บน Apache/Hostinger หรือเทียบเท่า เอกสารนี้เป็นขั้นตอนเตรียมระบบ ยังไม่มีการ deploy จริง หน้าจอและ path ของ hosting อาจแตกต่าง ให้ยืนยัน PHP binary/document root/cron path กับแผงควบคุมของบัญชีจริง

## Build and configuration

Build บนเครื่องที่มี Node แล้วส่งเฉพาะ production assets ไม่ต้องเปิด Node service

```sh
composer install --no-dev --optimize-autoloader
npm ci
npm run build
```

เก็บ code + composer.lock + production vendor และ public assets ของ release เดียวกัน ไม่ส่ง node_modules, tests, tools/php84, local database หรือไฟล์ log/session/cache จากเครื่องพัฒนา

ตั้ง `.env`: APP_ENV=production, APP_DEBUG=false, APP_URL=https://your-domain, APP_KEY สุ่มที่เก็บเป็นความลับ, SESSION_SECURE_COOKIE=true, DB_* ของ production, LINE_TRANSPORT=mock จนพร้อมเปิดจริง ห้ามใช้บัญชี root ของฐานข้อมูล ตั้ง upload_max_filesize/post_max_size ของ PHP ให้สอดคล้องกับหลายไฟล์และเพดานในแอป

ตั้ง LOCAL_SUPER_ACCESS_ENABLED=false และ LOCAL_SUPER_TOKEN_HASH ว่างบน production เสมอ Local recovery route ปฏิเสธคำขอใน production อยู่แล้วและไม่ใช่ช่องทางกู้คืนบัญชีบน hosting

## Option A: configurable document root

วางโปรเจคใน private project directory แล้วชี้ document root ไปที่ `<project>/public` เปิด mod_rewrite ให้ `.htaccess` ทำงาน ทดสอบ static assets และ login

## Option B: public_html fixed

```text
home/
  maintenance-app/          app, bootstrap, config, vendor, database, cron, storage, .env
  public_html/              .htaccess, assets, index.php
```

คัดลอกเฉพาะเนื้อหา `public/` ไป public_html แล้วปรับ public_html/index.php เป็น:

```php
<?php
declare(strict_types=1);
define('BASE_PATH', dirname(__DIR__) . '/maintenance-app');
require BASE_PATH . '/bootstrap/app.php';
(new App\Core\Application())->run();
```

storage/uploads อยู่ใต้ maintenance-app แบบ private ดาวน์โหลดผ่าน Controller ห้าม symlink uploads ออก public_html ห้ามพึ่ง `.htaccess` เพียงอย่างเดียวเพื่อซ่อน `.env` ถ้าควบคุมโครงสร้าง directory ได้

## Database and account setup

สำรองก่อนเปลี่ยน schema และหยุดการเขียนขณะ migrate ใช้ migration user แยกจาก runtime user หาก hosting รองรับ runtime ใช้ SELECT/INSERT/UPDATE/DELETE เท่าที่จำเป็น migration ต้อง CREATE/ALTER/INDEX สิทธิ์ตามที่ migrations ใช้

```sh
php scripts/migrate.php --no-create-db
php scripts/create_admin.php
php scripts/doctor.php
```

สร้างฐานผ่าน hosting panel ก่อนเมื่อบัญชีไม่มี CREATE DATABASE ห้ามใช้ `--test` ชี้ฐาน production ทดสอบ staging ด้วย MySQL 8 โดยเฉพาะก่อนอนุมัติใช้งาน

## Cron examples

แทน `/path/to/php84` และ `/private/maintenance-app` ด้วยค่าจริง ใช้ CLI script ไม่เรียก URL เปิดเผย

```cron
* * * * * /path/to/php84 /private/maintenance-app/cron/process_notifications.php
*/10 * * * * /path/to/php84 /private/maintenance-app/cron/check_overdue.php
*/15 * * * * /path/to/php84 /private/maintenance-app/cron/check_inventory.php
*/15 * * * * /path/to/php84 /private/maintenance-app/cron/generate_pm.php
```

check_overdue รวม daily summary และ upcoming PM โดยใช้ dedupe key กันซ้ำ daily summary เริ่มหลังชั่วโมงที่กำหนดใน notify_daily_summary_hour ตามเวลาไทย หาก cron ของ hosting จำกัดความถี่ ปรับตามข้อจำกัดนั้น

## Permissions and HTTPS

storage/logs, cache, uploads และ sessions ต้องเขียนได้เฉพาะบัญชี PHP/เจ้าของระบบ ตัวอย่าง directory 0750/files 0640, .env 0600 ตาม hosting ที่รองรับ หลีกเลี่ยง 0777 เปิด HTTPS ก่อน secure-cookie=true และตรวจ trusted proxy โดยไม่เชื่อ X-Forwarded-Proto จากอินเทอร์เน็ตโดยตรง

ทดสอบ `/.env`, `/config`, `/storage`, `/vendor`, `/cron` ว่าเข้าถึงไม่ได้ ใช้ public root ให้ถูก ปิด display_errors; ตรวจ error logs แบบ private อย่าเผยแพร่ log ที่มีข้อมูลผู้ใช้งาน

## Backup / restore

สำรองฐานข้อมูล + private uploads + encrypted copy ของ .env/APP_KEY + release version พร้อมกัน เก็บนอก public directory และแยกจาก hosting ที่ใช้งาน กำหนด retention และทดสอบ restore เป็นประจำ

```sh
mysqldump --single-transaction --routines --triggers --default-character-set=utf8mb4 -u BACKUP_USER -p DATABASE > /private/backups/maintenance.sql
```

รหัสผ่านให้กรอกผ่าน prompt หรือ protected client option file ห้ามใส่ใน command arguments เก็บไฟล์ backup เป็นความลับ

Restore ไป staging ก่อน ตรวจ FK/count และ uploads ปิด cron ระหว่าง restore เพื่อไม่ส่ง notification เก่าซ้ำ หากต้อง restore production ให้มีผู้รับผิดชอบอนุมัติช่วง downtime สร้างฐานใหม่หรือ restore backup ตามแผน recovery แล้วทดสอบ login/โรงแรม/ใบงาน/สต็อก/ต้นทุน ก่อนเปิด writes และ cron

## Release gate

- [ ] MySQL 8 migrations และ integration suite ผ่านบน staging
- [ ] PHP 8.4 extensions และ doctor ผ่าน
- [ ] HTTPS/session/CSRF และ private paths ผ่าน
- [ ] ทดสอบ 2 บัญชีคนละโรงแรม และ export/download/API
- [ ] ทดสอบ workflow แยกผู้ทำงานกับผู้ตรวจรับ
- [ ] Cron ทำงาน PM ไม่ซ้ำ inventory ไม่ติดลบ
- [ ] LINE webhook/group routing/ส่งจริงผ่าน หรือปิด MOCK ชัดเจน
- [ ] ทดสอบมือถือจริงและ responsive forms
- [ ] ทดสอบ backup + restore
- [ ] เจ้าของระบบอนุมัติ deploy production
