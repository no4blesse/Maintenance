# Permissions

สิทธิ์อ้างอิงใน database/seed.sql เป็นแหล่งจริง หน้าผู้ใช้งาน → บทบาทและสิทธิ์ แสดง matrix จากฐานข้อมูลโดยตรง

| บทบาท | งานหลัก | ขอบเขต |
|---|---|---|
| SUPER_ADMIN | ทุกโมดูล ตั้งค่า บัญชี/สิทธิ์ | ระดับกลุ่มได้ |
| GROUP_EXECUTIVE | Dashboard, KPI, cost, audit แบบอ่าน | ระดับกลุ่มได้ |
| HOTEL_MANAGER | ตรวจรับ อนุมัติค่าใช้จ่าย/คำขอซื้อ/โอน ดูรายงาน งบประมาณ | โรงแรมที่กำหนด |
| CHIEF_ENGINEER | ตรวจงาน มอบหมาย ทำงาน เบิกอะไหล่ อุปกรณ์ PM | โรงแรมที่กำหนด |
| TECHNICIAN | ดูงานในโรงแรม ทำงานที่ได้รับมอบหมายและเบิกอะไหล่ | โรงแรมที่กำหนด |
| STOREKEEPER | คลัง รับ/เบิก/คืน/ปรับ/โอน ขอซื้อ ผู้จำหน่ายรายโรงแรม | โรงแรมที่กำหนด |
| FINANCE | ต้นทุน อนุมัติ งบประมาณ KPI ขอซื้อ | โรงแรมที่กำหนด |
| STAFF | แจ้งซ่อมและดูใบงานที่ตนแจ้ง | โรงแรมที่กำหนด |
| EXTERNAL_CONTRACTOR | ทำงานเฉพาะใบงานที่ถูกมอบหมาย | โรงแรมที่กำหนด |

ผู้ใช้มีหลายบทบาทและหลายโรงแรมได้ Hotel switcher เป็นตัวกรอง ไม่ใช่การให้สิทธิ์ `hotel_id` ใน URL/ฟอร์ม/API ต้องตรวจเทียบสิทธิ์ server เสมอ

ช่างมี tickets.view ตาม seed จึงดูงานโรงแรมเดียวกันได้ แต่ทำงาน/บันทึกเวลาในงานที่มอบหมายให้ตน หรือมี tickets.assign ผู้รับเหมาไม่มี tickets.view จึงเห็นเฉพาะงานของตน

## Segregation of duties

- ช่างผู้รับผิดชอบตรวจรับงานของตนเองไม่ได้
- ผู้ขอโอนอนุมัติคำขอของตนไม่ได้
- ผู้สร้างค่าใช้จ่ายพิจารณารายการของตนเองไม่ได้ (ยกเว้น auto-approve ที่กำหนด threshold โดยผู้ดูแล; default ปิด)
- ผู้ขอซื้อพิจารณาคำขอของตนเองไม่ได้
- รับโอนต้องมี inventory.transfer ที่โรงแรมปลายทาง; อนุมัติใช้ inventory.transfer_approve ที่ต้นทาง
- QR ไม่เป็น public access token ต้อง login + assets.view ที่โรงแรมของอุปกรณ์
- รายการ exports intersect สิทธิ์ reports.export กับสิทธิ์อ่านข้อมูลของแต่ละโมดูล

## Global administration

การแก้บัญชีส่วนกลาง กำหนด roles/password/rates ใช้ global users.manage เท่านั้น ไม่ให้ผู้จัดการรายโรงแรมขยายสิทธิ์ตนเองเป็นระดับกลุ่ม ป้องกันการปิดบัญชีตนเอง/ถอนผู้ดูแลคนสุดท้าย การแก้โรงแรมและ reference master ที่กระทบทุกโรงแรมต้องมี global permission ของโมดูลนั้น

master catalog อะไหล่แก้ได้ด้วย global inventory.manage ผู้ดูแลคลังรายโรงแรมยังจัดการ supplier ของตน ยอดสต็อก threshold movement transfer และคำขอซื้อได้

บัญชีสร้างโดยผู้ดูแลและ reset password จะบังคับเปลี่ยนรหัสผ่านก่อนเข้าโมดูลอื่น ผู้ใช้แก้โปรไฟล์และรหัสผ่านตนเองได้ หน้าจอเปลี่ยนรหัสผ่านต้องยืนยันรหัสปัจจุบัน

## Local recovery exception

ผู้ใช้ร้องขอทางเข้าลัด Super Admin บนเครื่องพัฒนา เพิ่ม `/login/Super` โดย default ปิด ต้องมี private token hash + APP_ENV=development + enabled + loopback REMOTE_ADDR + localhost Host ครบทั้งหมด ใช้บัญชีที่กำหนดใน LOCAL_SUPER_EMAIL และให้ role SUPER_ADMIN ระดับกลุ่ม ไม่มีการยกระดับผู้ใช้รายอื่นที่กำลัง login อยู่

หลังยืนยัน token มี session grant ตั้งรหัสผ่านของบัญชีกู้คืนเองโดยไม่ต้องกรอกรหัสเดิมได้ 10 นาที ผูก grant กับ user ID ใช้ครั้งเดียวหลังเปลี่ยนสำเร็จ และล้างเมื่อ login/logout ตรวจ production/remote/host/expiry/invalid token/rate limiting ด้วย automated tests
