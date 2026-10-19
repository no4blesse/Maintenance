# Testing and verification

ตรวจล่าสุด: 19 ตุลาคม 2026 (Asia/Bangkok) รายละเอียดผลสุดท้ายอยู่ใน IMPLEMENTATION_PROGRESS.md

## Environment

- PHP 8.2.12 จาก XAMPP และ portable PHP 8.4.24 (PHP.net archive ตรวจ SHA-256)
- MariaDB 10.4.32 ในเครื่อง; ยังไม่ได้รัน MySQL 8 จริงในรอบนี้
- PHPUnit 11.5.57, Composer และ frontend dependency versions จาก lock files
- ฐาน DB_TEST_DATABASE ต้องแยกจาก DB_DATABASE และชื่อลงท้าย `_test` bootstrap จะปฏิเสธถ้าไม่ตรงเงื่อนไข
- LINE ถูกบังคับ MOCK ใน tests ไม่ใช้ credentials จริง ไม่ส่งข้อความออก

```sh
php scripts/migrate.php --test
php scripts/lint.php
php tools/composer.phar validate --no-check-publish
php tools/composer.phar check-platform-reqs
php tools/composer.phar audit
php vendor/phpunit/phpunit/phpunit --no-progress
npm run build
node --check public/assets/js/calendar.js
```

บนเครื่องนี้เรียก `C:\xampp\php\php.exe` หรือ `tools\php84\php.exe` แทน php

## Fixture isolation

ApplicationTest ใช้ outer transaction และ rollback ทุก test โดยไม่ truncate ตาราง ConcurrencyTest ต้อง commit fixture เพื่อให้ worker PHP processes เห็นข้อมูล จึงสร้าง hotel/user/part ชื่อ TEST แบบสุ่มและลบเฉพาะ record ของตนใน finally หาก process ถูก kill ก่อน cleanup อาจเหลือ TEST rows ในฐานทดสอบ ให้ตรวจเจ้าของ fixture ก่อน cleanup ห้ามนำ suite ไปชี้ฐานจริง

## Automated coverage

| Scenario | วิธีตรวจ |
|---|---|
| Authentication | password_verify, failed login attempts และ rate limit |
| Authentication middleware | CSRF 419, must-change-password redirect, permission 403 |
| Hotel isolation | ใบงาน/attachment 404, asset/QR 403, hotel-options API 403, calendar ไม่คืน CC ให้ NT |
| Pages | render หน้ารวม/ฟอร์ม/รายละเอียด/แก้ไขของทุกโมดูลและ master data หลายประเภท โดยไม่มี 4xx/5xx |
| Repair journey | staff สร้าง → chief review/assign → tech accept/start/log/parts/submit → manager verify |
| Segregation | self verification ถูกปฏิเสธ ผู้ไม่มี costs.approve อนุมัติไม่ได้ |
| Costs | 2 parts ×25 + 1.5h ×120 =230 บาท; expense approval ผ่านฟอร์มจริง |
| Stock transfer | ตัดต้นทางตอนอนุมัติ เพิ่มปลายทางตอนรับ รับซ้ำไม่ได้ |
| Purchase | ขอซื้อ → คนอื่นอนุมัติ → รับเข้า รับซ้ำ 422; ไม่สร้าง maintenance cost จากการซื้อ |
| Concurrency | 4 PHP worker processes สร้างใบงานพร้อมกัน เลขไม่ซ้ำ; 2 workers เบิก 7 จากยอด10 สำเร็จหนึ่ง ยอดเหลือ3 |
| PM | cron สร้างรอบเดียวเมื่อรันซ้ำ, month-end/leap-year anchor, save checklist, ต้องตรวจครบก่อน submit |
| PM history | แก้แผนแล้ว work-order checklist snapshot ยังเก็บ definition เดิม |
| Calendar | technician ที่ไม่มี reschedule ได้403, chief เปลี่ยนเวลาได้ และมี timeline/audit |
| KPI | cancellation denominator, MTTA, technical MTTR, completion rate, reopened handling |
| LINE | HMAC raw-body, duplicate webhook ID, hotel destination routing, queue dedupe, retry failure→MOCK success |
| LINE remapping | กลุ่มย้ายโรงแรมแล้วคิวเก่าเป็น SKIPPED ไม่มีข้อความถึงกลุ่มใหม่ |
| Alerts | overdue/low-stock job รันซ้ำไม่สร้างเหตุการณ์เดิมซ้ำ |
| Files | PHP ปลอมเป็น.jpg ถูกปฏิเสธ, valid PNG เก็บ private, owner อ่านได้/ต่างโรงแรมอ่านไม่ได้, traversal ถูกปฏิเสธ |
| CSV | streaming UTF-8 BOM, formula injection protection, unauthorized export ถูกปฏิเสธ |
| Local recovery | สร้างบัญชี SUPER_ADMIN ทุกโรงแรม, เข้า account เดิมซ้ำได้, reset grant ผูก user/หมดอายุ/ใช้แล้วล้าง; production/remote/foreign host/disabled/wrong token/rate limit ปฏิเสธ |

## Browser checks performed

ใช้ in-app browser กับ localhost:8087 + DB_TEST_DATABASE เท่านั้น:

1. เข้าสู่ระบบด้วยบัญชี DEMO ที่สร้างแบบ explicit
2. Dashboard มี 4 ใบงานจาก DEMO records จริง และ Chart.js render
3. รายงาน KPI แสดงข้อมูลและกราฟ ไม่มี console error ที่พบตอนตรวจ
4. FullCalendar แสดงใบงานของทั้งสี่โรงแรมจาก JSON endpoint
5. ฟอร์มอุปกรณ์เลือก NT แล้วโหลดห้อง NT และเก็บโรงแรมที่เลือกหลัง refresh
6. บันทึกอุปกรณ์ผ่าน browser form สำเร็จ ได้เลข AC-NT-0002 และ asset_history

หลักฐาน: screenshots/asset-browser-acceptance.png และ screenshot dashboard ที่บันทึกในรอบส่งมอบ

## Required external acceptance

- MySQL 8 staging: `.github/workflows/tests.yml` เตรียม MySQL8/PHP8.4 CI ไว้แล้ว แต่ยังไม่ได้ส่งขึ้น GitHub หรือรัน CI
- LINE public HTTPS webhook, live delivery, quota และ group mapping ขององค์กรจริง
- Physical phone/Android/iOS, camera uploads, 390px viewport และ tablet
- Drag/resize ปฏิทินผ่าน browser จริง; endpoint authorization/persistence ผ่าน automated test แล้ว
- Large production-like dataset, pagination/export caps, index/query plan review
- Hostinger Apache/HTTPS/cron permissions และ backup/restore rehearsal

ไม่ถือว่าการทดสอบตัวอย่างเหล่านี้แทน exhaustive security review หรือ user acceptance ทุกบทบาท/ทุกโรงแรม
