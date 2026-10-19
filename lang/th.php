<?php

/*
 * Thai (default) strings.
 * Two styles are supported by __():
 *   1) Structured keys, e.g. __('validation.required')
 *   2) Thai source text as key, e.g. __('แจ้งซ่อมใหม่') – Thai shows the key
 *      itself; English translations live in lang/en.php.
 */

return [
    'validation.failed' => 'กรุณาตรวจสอบข้อมูลที่กรอกอีกครั้ง',
    'validation.required' => 'กรุณากรอก :field',
    'validation.string' => ':field ต้องเป็นข้อความ',
    'validation.int' => ':field ต้องเป็นจำนวนเต็ม',
    'validation.numeric' => ':field ต้องเป็นตัวเลข',
    'validation.decimal' => ':field ต้องเป็นตัวเลขทศนิยมไม่เกิน 4 ตำแหน่ง',
    'validation.email' => ':field ต้องเป็นอีเมลที่ถูกต้อง',
    'validation.bool' => ':field ไม่ถูกต้อง',
    'validation.array' => ':field ไม่ถูกต้อง',
    'validation.date' => ':field ต้องเป็นวันที่ (ปปปป-ดด-วว)',
    'validation.datetime' => ':field ต้องเป็นวันและเวลา',
    'validation.min_num' => ':field ต้องไม่น้อยกว่า :param',
    'validation.max_num' => ':field ต้องไม่เกิน :param',
    'validation.min_str' => ':field ต้องมีอย่างน้อย :param ตัวอักษร',
    'validation.max_str' => ':field ต้องไม่เกิน :param ตัวอักษร',
    'validation.in' => ':field ไม่อยู่ในตัวเลือกที่กำหนด',
    'validation.regex' => 'รูปแบบ :field ไม่ถูกต้อง',
    'validation.confirmed' => 'การยืนยัน :field ไม่ตรงกัน',
    'validation.gte_field' => ':field ต้องไม่น้อยกว่าค่าเริ่มต้น',
    'validation.password' => 'รหัสผ่านต้องมีอย่างน้อย 10 ตัว และมีตัวพิมพ์ใหญ่ ตัวพิมพ์เล็ก และตัวเลข',
    'validation.hotel_required' => 'กรุณาเลือกโรงแรม',

    'auth.failed' => 'อีเมลหรือรหัสผ่านไม่ถูกต้อง',
    'auth.inactive' => 'บัญชีนี้ถูกระงับการใช้งาน',
    'auth.throttled' => 'พยายามเข้าสู่ระบบหลายครั้งเกินไป กรุณาลองใหม่ใน :minutes นาที',

    'error.server' => 'เกิดข้อผิดพลาดภายในระบบ (รหัสอ้างอิง :ref) กรุณาแจ้งผู้ดูแลระบบ',
    'error.csrf' => 'เซสชันหมดอายุหรือคำขอไม่ถูกต้อง กรุณารีเฟรชหน้าแล้วลองใหม่',

    'time.just_now' => 'เมื่อสักครู่',
    'time.minutes_ago' => ':n นาทีที่แล้ว',
    'time.hours_ago' => ':n ชั่วโมงที่แล้ว',
    'time.days_ago' => ':n วันที่แล้ว',
    'time.min' => 'นาที',
    'time.hr' => 'ชม.',
    'time.day' => 'วัน',

    'field.email' => 'อีเมล',
    'field.password' => 'รหัสผ่าน',
    'field.name' => 'ชื่อ',
    'field.title' => 'หัวข้อปัญหา',
    'field.description' => 'รายละเอียด',
    'field.category_id' => 'หมวดงานซ่อม',
    'field.priority_id' => 'ความเร่งด่วน',
    'field.hotel_id' => 'โรงแรม',
    'field.reporter_name' => 'ชื่อผู้แจ้ง',
    'field.quantity' => 'จำนวน',
    'field.unit_cost' => 'ราคาต่อหน่วย',
    'field.part_id' => 'อะไหล่',
    'field.reason' => 'เหตุผล',
    'field.note' => 'หมายเหตุ',
    'field.technician_id' => 'ช่างผู้รับผิดชอบ',
    'field.started_at' => 'เวลาเริ่ม',
    'field.ended_at' => 'เวลาสิ้นสุด',
    'field.amount' => 'จำนวนเงิน',
    'field.task_name' => 'ชื่องาน',
    'field.frequency' => 'ความถี่',
    'field.start_date' => 'วันเริ่มต้น',
    'field.expense_date' => 'วันที่ค่าใช้จ่าย',
    'field.sku' => 'รหัสอะไหล่',
    'field.unit' => 'หน่วยนับ',
    'field.code' => 'รหัส',
    'field.year' => 'ปี',
];
