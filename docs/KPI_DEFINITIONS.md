# KPI definitions

แหล่งจริง: app/Services/KPIService.php และ ticket_status_logs ค่าเวลาภายในเป็น UTC ขอบเขตวันที่เป็น Asia/Bangkok เริ่ม 00:00 ของวันแรกจนก่อน 00:00 ของวันถัดจากวันสุดท้าย ช่วงเริ่มต้นเป็นเดือนปัจจุบันกับ 5 เดือนก่อนหน้า ค่าไม่มี denominator แสดง — ไม่สร้างตัวเลขแทน

| Metric | สูตร / cohort |
|---|---|
| Total requests | จำนวน tickets ที่ requested_at อยู่ในช่วง รวม cancelled ไว้แยกนับ |
| Qualifying | Total − cancelled |
| Completed | status=COMPLETED ปัจจุบันใน request cohort |
| Open | status ไม่ใช่ COMPLETED หรือ CANCELLED |
| Completion rate | Completed / qualifying × 100 |
| Overdue | Open ที่ sla_resolution_due < เวลาปัจจุบัน |
| MTTA | ค่าเฉลี่ย acknowledged_at − requested_at เฉพาะไม่ cancelled และมี timestamp |
| MTTR | ค่าเฉลี่ย technically_completed_at − requested_at ของ COMPLETED ที่มี timestamp |
| Resolution SLA | COMPLETED ที่ technically_completed_at <= sla_resolution_due / COMPLETED ที่มีทั้งสองเวลา ×100 |
| Response SLA | งานไม่ cancelled ที่ acknowledged_at <= sla_response_due / งานไม่ cancelled ที่มีทั้งสองเวลา |
| Repeat repairs | corrective tickets ที่อ้าง asset และไม่ cancelled ซึ่ง asset เดียวกันมี corrective ticket อื่น technically completed ภายใน N วันก่อน requested_at / corrective tickets ที่มี asset และไม่ cancelled |
| PM on-time rate | PM work orders ที่ due_date อยู่ในช่วง ไม่ cancelled และ technical completion เป็นวันเดียวกับหรือก่อน due_date / PM work orders ใน due cohort ×100 |
| Technician workload | จำนวนใน request cohort แยก assigned/completed/open/overdue ตาม assignee ปัจจุบัน; logged hours ใช้ช่วง started_at ของ work log |
| Costs | SUM(cost_entries.total_cost) เฉพาะ APPROVED และ expense_date อยู่ในช่วง แยก permission costs.view |

## Time and state rules

MTTR แยกเวลาซ่อมเสร็จทางเทคนิคจากเวลาตรวจรับ/ปิดงาน ถ้าถูก reject จะล้าง technical completion จนส่งตรวจใหม่ ถ้า reopen จะไม่นับเป็น COMPLETED จนปิดอีกครั้ง ระยะซ่อมสะท้อนเวลาจาก request เดิม ไม่ใช่เฉพาะรอบ reopen ล่าสุด

กฎ SLA snapshot ตอนสร้างงาน งานที่นัดล่วงหน้าใช้ max(request time, scheduled start) เป็นฐาน SLA การเลื่อนปฏิทินภายหลังไม่เลื่อน SLA อัตโนมัติ ป้องกันการย้ายเป้าหมายย้อนหลังเพื่อลด overdue

ไม่มีการหักเวลานอกกะ/วันหยุด เพราะยังไม่มี shift/calendar model เวลารอรวมใน SLA เสมอ ส่วน MTTR เลือกหัก waiting_seconds ได้ด้วย setting kpi_mttr_exclude_waiting (default ปิด)

รายงานเป็นสถานะปัจจุบันของ cohort ไม่ใช่รายงาน point-in-time ในอดีต การ reopen/cancel/อนุมัติค่าใช้จ่ายภายหลังอาจทำให้ผลของช่วงเก่าเปลี่ยนได้ monthlyTrend แยก created/request date และ completed/closed date จึงไม่ใช่ completion-rate denominator ของ request cohort โดยตรง

Costs ใช้ expense_date ต่างจาก ticket cohort; stock RECEIVE ไม่ใช่ maintenance expense และไม่บวกกับ PART_USAGE ซ้ำ

การกรอง department/technician/asset category/category/priority/status/type มีผลกับ ticket metrics และ PM cohort ค่าใช้จ่ายเปรียบเทียบโรงแรมแสดงตาม hotel + expense dates เท่านั้น ไม่ใช้ ticket filters ที่อาจตัดรายการค่าใช้จ่ายไม่มีใบงาน

## Budgets

งบแต่ละ record แยก hotel/year/month/category; month=NULL หมายถึงทั้งปี category=NULL หมายถึงทุกประเภท Actual ใช้ ledger APPROVED ในช่วงที่งบนั้นครอบคลุม

Variance = budget − actual; utilization = actual / budget ×100 (ถ้างบ=0 แสดง —) งบปี/เดือนและงบรวม/หมวดเป็นมุมมองซ้อนกัน จึงห้ามรวม amount ทุกแถวเพื่อหางบรวม
