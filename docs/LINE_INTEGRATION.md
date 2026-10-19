# LINE Messaging API

ใช้ LINE Official Account + Messaging API เท่านั้น ไม่มี LINE Notify เอกสารอ้างอิงของผู้ให้บริการ: [Webhook signature](https://developers.line.biz/en/docs/messaging-api/verify-webhook-signature/), [Push messages](https://developers.line.biz/en/reference/messaging-api/#send-push-message), [Retry keys](https://developers.line.biz/en/docs/messaging-api/retrying-api-request/)

## ตั้งค่า

1. สร้าง/เลือก LINE Official Account และ Messaging API channel ในบัญชีขององค์กร ตั้งให้ Bot เข้ากลุ่มได้
2. เก็บ Channel Secret และ Channel Access Token ใน `.env` ส่วน private เท่านั้น ไม่กรอกลง URL หรือ Git
3. ตั้ง APP_URL เป็น public HTTPS URL ของระบบจริง และ webhook เป็น `https://your-domain/webhook/line`
4. เปิด webhook ใน LINE Developers แล้ว verify endpoint ต้องเข้าถึง HTTPS ได้จากอินเทอร์เน็ต
5. เชิญ Bot เข้ากลุ่มที่ต้องการ เมื่อได้รับ signed join/message event ระบบจะค้นพบ source.groupId จริง
6. เข้าเมนู LINE Bot ด้วยสิทธิ์ line.manage เลือกกลุ่มที่ค้นพบ ตรวจชื่อกับเจ้าของกลุ่ม แล้วผูกกับโรงแรมที่ถูกต้อง
7. เลือกประเภทแจ้งเตือนที่เปิดใช้เฉพาะโรงแรม กฎรายโรงแรม override ค่า default
8. ตั้ง LINE_TRANSPORT=line เมื่อจะทดสอบส่งจริง ตั้ง cron/process_notifications.php แล้วกดทดสอบกลุ่ม ตรวจ notification_logs ว่า transport=LINE และตรวจข้อความในกลุ่มปลายทางด้วย

หากไม่มี credentials หรือ LINE_TRANSPORT=mock ระบบแสดง NOT CONNECTED และใช้ MockTransport แม้คิวมี status=SENT และ HTTP 200 ก็เป็นเพียงการจำลองเมื่อ transport=MOCK ไม่ใช่หลักฐานส่งข้อความจริง

## Security and delivery

Webhook ตรวจ base64 HMAC-SHA256 บน raw request bytes ก่อน JSON parse ด้วย hash_equals ไม่แก้ body ก่อนตรวจ ลายเซ็นผิดคืน 403 รูปแบบ body ผิดคืน 400 ไม่เก็บข้อความแชต เก็บเพียง event metadata และ group discovery

webhookEventId มี UNIQUE กันส่งซ้ำ destination ต้องผูกกับโรงแรมก่อนส่ง คิว dedupe_key ทำให้ business event เดิมไม่สร้างซ้ำ process ใช้ atomic claim และ X-Line-Retry-Key คงเดิมต่อ queue ID การย้ายกลุ่มไปโรงแรมใหม่ทำให้คิวเก่าถูก SKIPPED ไม่ส่ง payload ของโรงแรมเก่าไปยังกลุ่มใหม่

5xx/429/network failures retry แบบ exponential backoff รายการเกิน max_attempts หรือ error ถาวรเป็น DEAD ผู้ดูแลกดลองใหม่ได้เฉพาะคิวของโรงแรมที่มีสิทธิ์ Worker timeout 10 นาทีจะถูกนำกลับมาประมวลผล ใช้ cron ทีละไม่เกิน 50 รายการตาม config

Events: TICKET_CREATED, TICKET_EMERGENCY, TICKET_ASSIGNED, TICKET_OVERDUE, TICKET_COMPLETED, EXPENSE_APPROVAL, STOCK_LOW, PM_UPCOMING, PM_OVERDUE, DAILY_SUMMARY, TEST

## Troubleshooting

| อาการ | ตรวจ |
|---|---|
| Webhook 403 | Secret ตรง channel หรือไม่ reverse proxy เปลี่ยน raw body หรือไม่ |
| กลุ่มไม่ปรากฏ | Bot อยู่ในกลุ่มหรือไม่ เปิด webhook และอนุญาตเข้ากลุ่มหรือไม่ |
| SKIPPED | ยังไม่ผูกกลุ่ม/ออกจากกลุ่ม/กลุ่มถูก remap หรือไม่ |
| 401/403 จาก LINE | Token ถูกต้อง หมดอายุ หรือถูก revoke หรือไม่ |
| 429 | quota/อัตราการส่งของบัญชี ตรวจแผนและ backoff |
| คิวไม่ถูกส่ง | Cron ใช้ PHP และ path ถูกหรือไม่ next_attempt_at ถึงเวลาหรือยัง |
| กดส่งแล้วไม่มีข้อความ | ดู transport; MOCK ไม่ส่งจริง ตรวจ line.transport และ credentials |
| เปิดลิงก์แล้วให้ login | เป็นพฤติกรรมที่ตั้งใจ ลิงก์ไม่ข้าม authorization |

การส่งจริง การยืนยัน quota และ webhook ผ่าน public HTTPS ยังไม่ได้ทดสอบในรอบพัฒนานี้
