<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\{Auth, Config, Database};
use App\Helpers\{Dec, Time};
use App\Services\Line\{MockTransport, WebhookService};
use DateTimeImmutable;
use RuntimeException;

/** A repeatable demonstration dataset, restricted to the explicitly selected test database. */
final class DemoShowcaseSeeder
{
    public const KEY = 'demo_showcase_v1';
    private array $manifest = ['version' => 1, 'hotels' => [], 'ticket_ids' => [], 'cost_ids' => [], 'transfers' => [], 'purchases' => [], 'parts' => []];
    private array $people = [];
    private array $categories;
    private array $priorities;
    private array $assetCategories;
    private array $parts = [];
    private array $resources = [];
    private string $passwordHash;
    private DateTimeImmutable $now;

    public static function isTestWorkspace(): bool
    {
        return Config::get('app.env') === 'development'
            && Config::get('database.database') === Config::get('database.test_database')
            && str_ends_with((string) Config::get('database.database'), '_test');
    }

    public static function manifest(): ?array
    {
        $value = json_decode((string) SettingsService::get(self::KEY, ''), true);
        return is_array($value) && ($value['version'] ?? null) === 1 ? $value : null;
    }

    public function run(): array
    {
        if (!self::isTestWorkspace()) {
            throw new RuntimeException('Showcase requires development mode and the separate _test database.');
        }
        SettingsService::flush();
        if ($existing = self::manifest()) {
            return $existing + ['reused' => true];
        }
        $this->now = Time::nowImmutable();
        $previousUser = Auth::user();
        $previousTransport = Config::get('line.transport');
        Config::set('line.transport', 'mock');
        $this->passwordHash = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
        try {
            return Database::transaction(function (): array {
                // A unique settings row serializes first-use creation; failures roll back all records.
                Database::insert('settings', ['setting_key' => self::KEY, 'setting_value' => 'BUILDING', 'value_type' => 'string']);
                $this->categories = array_column(Database::select('SELECT id,code FROM maintenance_categories'), 'id', 'code');
                $this->priorities = array_column(Database::select('SELECT id,code FROM priorities'), 'id', 'code');
                $this->assetCategories = array_column(Database::select('SELECT id,code FROM asset_categories'), 'id', 'code');
                $admin = $this->person('admin', 'DEMO ผู้ดูแลการสาธิต', 'SUPER_ADMIN', null);
                $this->person('store', 'DEMO คุณป่าน (คลังอะไหล่)', null, null);
                $this->person('finance', 'DEMO คุณฟ้า (การเงิน)', null, null);
                $hotels = Database::select("SELECT * FROM hotels WHERE code IN ('NT','CC','LS','SG') AND is_active=1 ORDER BY sort_order,id");
                if (count($hotels) !== 4) {
                    throw new RuntimeException('The four reference hotels must be active before building the showcase.');
                }
                $this->as('admin');
                $this->createParts();
                foreach ($hotels as $i => $hotel) {
                    $this->createHotel($hotel, $i);
                }
                $this->clock($this->now->modify('-1 hour'));
                $this->createPurchases($hotels);
                $this->createTransfers($hotels);
                $this->createLowStock($hotels);
                $this->as('admin');
                $this->clock($this->now);
                $this->manifest['generated_at'] = Time::now();
                $this->manifest['from'] = $this->now->setTimezone(Time::tz())->modify('first day of this month')->modify('-5 months')->format('Y-m-d');
                $this->manifest['to'] = Time::today();
                $this->manifest['admin_id'] = $admin;
                $this->manifest['part_ids'] = array_values($this->parts);
                $this->manifest['people'] = array_map(static fn ($u) => ['id' => $u['id'], 'name' => $u['name'], 'email' => $u['email']], $this->people);
                $this->manifest['parts'] = $this->parts;
                // Only process this dataset's jobs, through the mock transport.
                $destinations = array_column($this->resources, 'destination');
                $queueIds = Database::column('SELECT id FROM notification_queue WHERE destination_id IN ('.Database::placeholders($destinations).') AND status=\'PENDING\' ORDER BY id LIMIT 6', $destinations);
                foreach ($queueIds as $i => $queueId) {
                    if ($i === 5) {
                        MockTransport::$failNext = true;
                    }
                    NotificationService::process(1, (int) $queueId);
                }
                $this->manifest['mock_queue_ids'] = array_map('intval', $queueIds);
                SettingsService::set(self::KEY, json_encode($this->manifest, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $admin);
                Audit::log('demo.showcase_created', 'demo', null, null, null, ['tickets' => count($this->manifest['ticket_ids']), 'hotels' => 4]);
                return $this->manifest;
            });
        } finally {
            Time::freeze(null);
            Auth::actingAs($previousUser);
            Config::set('line.transport', $previousTransport);
            MockTransport::$failNext = false;
            SettingsService::flush();
        }
    }

    private function clock(DateTimeImmutable $time): void
    {
        Time::freeze($time->format('Y-m-d H:i:s'));
    }
    private function as(string $key): void
    {
        Auth::actingAs($this->people[$key]);
    }
    private function role(int $userId, string $code, ?int $hotel): void
    {
        Database::execute('INSERT IGNORE INTO user_hotel_roles (user_id,hotel_id,role_id) VALUES (?,?,?)', [$userId, $hotel, Database::value('SELECT id FROM roles WHERE code=?', [$code])]);
    }
    private function person(string $key, string $name, ?string $role, ?int $hotel): int
    {
        $email = 'showcase.'.strtolower(str_replace('_', '.', $key)).'@example.test';
        if (Database::value('SELECT id FROM users WHERE email=?', [$email])) {
            throw new RuntimeException('A showcase account email already exists; existing accounts will not be overwritten.');
        }
        $id = Database::insert('users', ['name' => $name, 'email' => $email, 'password_hash' => $this->passwordHash, 'is_demo' => 1]);
        if ($role) {
            $this->role($id, $role, $hotel);
        }
        $this->people[$key] = Database::first('SELECT * FROM users WHERE id=?', [$id]);
        return $id;
    }
    private function createParts(): void
    {
        foreach ([
            'LED' => ['หลอด LED 12W', 'หลอด', '65.00', '10'],
            'CAP' => ['คาปาซิเตอร์แอร์ 35µF', 'ตัว', '180.00', '5'],
            'PVC' => ['ข้องอ PVC 1/2 นิ้ว', 'ชิ้น', '25.00', '10'],
            'SEAL' => ['แมคคานิคอลซีลปั๊มน้ำ', 'ชุด', '350.00', '3'],
            'CONTACTOR' => ['คอนแทคเตอร์ 20A', 'ตัว', '420.00', '3'],
            'FILTER' => ['ไส้กรองสระว่ายน้ำ', 'ชิ้น', '680.00', '2'],
        ] as $key => [$name, $unit, $cost, $minimum]) {
            $this->parts[$key] = Database::insert('spare_parts', ['sku' => 'DEMO-SHOW-'.$key, 'name' => 'DEMO '.$name, 'unit' => $unit, 'cost_price' => $cost, 'min_stock' => $minimum, 'reorder_point' => $minimum, 'description' => 'ข้อมูลสมมติชุดสาธิตระบบ ไม่ใช่ยอดสินค้าจริง', 'is_demo' => 1]);
        }
    }

    private function createHotel(array $hotel, int $index): void
    {
        $id = (int) $hotel['id'];
        $code = $hotel['code'];
        $this->clock($this->now->modify('-6 months'));
        $this->person($code.'_chief', 'DEMO หัวหน้าช่างสมชาย · '.$code, 'CHIEF_ENGINEER', $id);
        $tech = $this->person($code.'_tech', 'DEMO ช่างเอก (แอร์/ประปา) · '.$code, 'TECHNICIAN', $id);
        $tech2 = $this->person($code.'_tech2', 'DEMO ช่างบี (ไฟฟ้า/PM) · '.$code, 'TECHNICIAN', $id);
        $this->person($code.'_staff', 'DEMO คุณมะลิ (แม่บ้าน) · '.$code, 'STAFF', $id);
        $this->person($code.'_manager', 'DEMO ผู้จัดการโรงแรม · '.$code, 'HOTEL_MANAGER', $id);
        $this->role((int) $this->people['store']['id'], 'STOREKEEPER', $id);
        $this->role((int) $this->people['finance']['id'], 'FINANCE', $id);
        foreach ([$tech => 160 + $index * 20, $tech2 => 180 + $index * 20] as $uid => $rate) {
            Database::insert('technician_rates', ['user_id' => $uid, 'hotel_id' => $id, 'hourly_rate' => (string) $rate, 'effective_from' => Time::today()]);
        }
        $this->as('admin');
        $dept = Database::insert('departments', ['hotel_id' => $id, 'code' => 'DEMO-HK', 'name_th' => 'DEMO แม่บ้าน', 'name_en' => 'DEMO Housekeeping']);
        foreach (['ENG' => ['ช่าง', 'Engineering'], 'FO' => ['ต้อนรับ', 'Front Office']] as $k => [$th, $en]) {
            Database::insert('departments', ['hotel_id' => $id, 'code' => 'DEMO-'.$k, 'name_th' => 'DEMO '.$th, 'name_en' => 'DEMO '.$en]);
        }
        $building = Database::insert('buildings', ['hotel_id' => $id, 'code' => 'DEMO-SHOW', 'name' => 'DEMO อาคารสาธิต '.$code]);
        $floor = Database::insert('floors', ['hotel_id' => $id, 'building_id' => $building, 'name' => 'DEMO ชั้น 3', 'level_no' => 3]);
        $locations = [];
        foreach (['305' => ['ห้อง 305', 'ROOM'], '201' => ['ห้อง 201', 'ROOM'], 'PUMP' => ['ห้องเครื่องปั๊มน้ำ', 'BACK_OF_HOUSE'], 'POOL' => ['สระว่ายน้ำ', 'PUBLIC']] as $k => [$name, $type]) {
            $locations[$k] = Database::insert('locations', ['hotel_id' => $id, 'building_id' => $building, 'floor_id' => $type === 'ROOM' ? $floor : null, 'code' => 'DEMO-SHOW-'.$k, 'name' => 'DEMO '.$name, 'location_type' => $type]);
        }
        $supplier = Database::insert('suppliers', ['hotel_id' => $id, 'name' => 'DEMO บริษัทบริการช่าง '.$code.' (สมมติ)', 'contact_name' => 'DEMO ผู้ประสานงาน', 'is_contractor' => 1]);
        $assets = [];
        foreach (['AC' => ['เครื่องปรับอากาศ ห้อง 305', 'AC', '305'], 'PUMP' => ['ปั๊มน้ำอาคารหลัก', 'PMP', 'PUMP'], 'POOL' => ['ปั๊มกรองสระว่ายน้ำ', 'POOL', 'POOL'], 'ELEC' => ['ตู้ควบคุมไฟฟ้า ชั้น 3', 'ELP', '201']] as $k => [$name, $category, $location]) {
            $assets[$k] = AssetService::create($id, ['name' => 'DEMO '.$name.' · '.$code, 'category_id' => $this->assetCategories[$category], 'location_id' => $locations[$location], 'brand' => 'DEMO Hotel Equipment', 'model' => 'DEMO-2025', 'serial_no' => 'DEMO-'.$code.'-'.$k, 'supplier_id' => $supplier, 'purchase_date' => $this->now->modify('-1 year')->format('Y-m-d'), 'purchase_cost' => (string) (18000 + 3500 * $index), 'warranty_end' => $this->now->modify('+8 months')->format('Y-m-d'), 'notes' => 'อุปกรณ์สมมติสำหรับอธิบายประวัติการซ่อมและต้นทุน'], null, ['is_demo' => true]);
        }
        $groupId = 'C'.substr(hash('sha256', 'sungroup-showcase-v1-'.$code), 0, 32);
        WebhookService::handle(['events' => [['webhookEventId' => 'DEMO-SHOW-GROUP-'.$code, 'type' => 'join', 'source' => ['type' => 'group', 'groupId' => $groupId]]]]);
        $destination = (int) Database::value('SELECT id FROM line_destinations WHERE group_id=?', [$groupId]);
        Database::update('line_destinations', ['hotel_id' => $id, 'display_name' => 'DEMO กลุ่มจำลอง '.$code.' · MOCK เท่านั้น', 'status' => 'BOUND', 'bound_by' => $this->people['admin']['id'], 'bound_at' => Time::now()], 'id=?', [$destination]);
        $this->resources[$code] = compact('id', 'dept', 'locations', 'assets', 'supplier', 'destination');
        $this->manifest['hotels'][$code] = ['id' => $id, 'name' => $hotel['name_th'], 'accent' => $hotel['accent_color'], 'scenarios' => [], 'history' => [], 'pm' => [], 'assets' => $assets, 'destination' => $destination];
        foreach ($this->parts as $key => $part) {
            $price = (string) Database::value('SELECT cost_price FROM spare_parts WHERE id=?', [$part]);
            InventoryService::receive($id, $part, in_array($key, ['LED', 'PVC']) ? '50' : '15', $price, $supplier, 'DEMO สต็อกตั้งต้นชุดสาธิต');
        }
        $month = $this->now->setTimezone(Time::tz())->modify('first day of this month')->setTime(8, 0);
        $historyNames = ['ไฟทางเดินชั้น 3 ดับ เปลี่ยนหลอด LED', 'แอร์ห้อง 305 ไม่เย็น เปลี่ยนคาปาซิเตอร์', 'ท่อน้ำปั๊มรั่ว เปลี่ยนข้อต่อ PVC', 'ปั๊มกรองสระมีน้ำรั่ว เปลี่ยนซีล', 'คอนแทคเตอร์ตู้ไฟมีเสียงดัง', 'แรงดันกรองสระลดลง เปลี่ยนไส้กรอง'];
        foreach (range(0, 5 + $index % 3) as $n) {
            $when = $month->modify('-'.($n % 6).' months')->modify('+'.(4 + $index + intdiv($n, 6) * 5).' days')->setTimezone(new \DateTimeZone('UTC'));
            if ($when > $this->now->modify('-1 day')) {
                $when = $this->now->modify('-1 day')->setTime(1, 0);
            }
            $kind = $n % 6;
            $assetKey = ['ELEC', 'AC', 'PUMP', 'POOL', 'ELEC', 'POOL'][$kind];
            $category = ['ELEC', 'AC', 'PLUMB', 'POOL', 'ELEC', 'POOL'][$kind];
            $ticket = $this->newTicket($code, $when, $historyNames[$kind], $category, $assetKey, $kind === 2 ? 'HIGH' : 'NORMAL');
            $this->work($code, $ticket, $when, $kind % 2 === 1, $kind === 2 ? 600 : 60 + $index * 15, array_values($this->parts)[$kind], $kind === 1 ? '2' : '1');
            if ($kind === 2 || $kind === 5) {
                $this->manualCost($code, $ticket, 'DEMO ค่าบริการช่างภายนอกและตรวจสอบระบบ', (string) (900 + 250 * $index), true);
            }
            $this->submitAndVerify($code, $ticket, $when->modify('+'.($kind === 2 ? 680 : 160 + $index * 15).' minutes'), $kind % 2 === 1);
            $this->manifest['hotels'][$code]['history'][] = $ticket;
        }
        $this->createActiveScenarios($code);
        $this->createPm($code);
        $this->clock($this->now->modify('-30 minutes'));
        $this->manualCost($code, $this->manifest['hotels'][$code]['scenarios']['approval'], 'DEMO ค่าซ่อมมอเตอร์รอฝ่ายการเงินอนุมัติ', (string) (4500 + $index * 750), false);
        $this->as('admin');
        $actual = (string) Database::value("SELECT COALESCE(SUM(total_cost),0) FROM cost_entries WHERE hotel_id=? AND status='APPROVED' AND expense_date BETWEEN ? AND ?", [$id, $month->format('Y-m-d'), $this->now->setTimezone(Time::tz())->format('Y-m-d')]);
        $amount = $code === 'SG' ? Dec::mul($actual, '0.75', 2) : Dec::add($actual, (string) (4000 + $index * 1000), 2);
        foreach ([null, (int) $month->format('n')] as $budgetMonth) {
            Database::execute('INSERT INTO budgets (hotel_id,year,month,amount,note,created_by) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE note=note', [$id, (int) $month->format('Y'), $budgetMonth, $budgetMonth ? $amount : Dec::mul($amount, '12', 2), 'DEMO งบประมาณชุดสาธิต '.($code === 'SG' ? '(ตัวอย่างใช้เกินงบ)' : '(ตัวอย่างเหลืองบ)'), $this->people['admin']['id']]);
        }
    }

    private function newTicket(string $code, DateTimeImmutable $when, string $title, string $category = 'AC', string $assetKey = 'AC', string $priority = 'NORMAL'): int
    {
        $r = $this->resources[$code];
        $this->clock($when);
        $this->as($code.'_staff');
        $id = TicketService::create($r['id'], ['title' => 'DEMO '.$title.' · '.$code, 'description' => 'ข้อมูลสมมติชุดสาธิต: แม่บ้านพบปัญหาระหว่างตรวจห้อง จึงแจ้งให้ฝ่ายช่างตรวจสอบและบันทึกผลการแก้ไข', 'category_id' => $this->categories[$category], 'priority_id' => $this->priorities[$priority], 'asset_id' => $r['assets'][$assetKey], 'reporter_department_id' => $r['dept'], 'guest_impact' => $priority === 'EMERGENCY' ? 'HIGH' : 'LOW', 'notes' => 'DEMO Showcase v1 · ข้อมูลสมมติสำหรับสาธิตระบบ', 'estimated_cost' => '2500'], [], ['is_demo' => true]);
        $this->manifest['ticket_ids'][] = $id;
        TicketService::addComment($id, 'DEMO รบกวนตรวจสอบก่อนแขกเข้าพัก ขอบคุณค่ะ');
        return $id;
    }
    private function assign(string $code, int $ticket, DateTimeImmutable $when, bool $second = false): void
    {
        $this->clock($when->modify('+5 minutes'));
        $this->as($code.'_chief');
        TicketService::transition($ticket, 'review');
        $this->clock($when->modify('+10 minutes'));
        TicketService::transition($ticket, 'assign', ['technician_id' => $this->people[$code.($second ? '_tech2' : '_tech')]['id'], 'scheduled_start' => Time::toLocal($when->modify('+30 minutes')->format('Y-m-d H:i:s')), 'scheduled_end' => Time::toLocal($when->modify('+150 minutes')->format('Y-m-d H:i:s')), 'note' => 'DEMO มอบหมายตามทักษะและภาระงาน']);
    }
    private function work(string $code, int $ticket, DateTimeImmutable $when, bool $second, int $minutes, ?int $part = null, string $quantity = '1'): void
    {
        $this->assign($code, $ticket, $when, $second);
        $this->clock($when->modify('+15 minutes'));
        $this->as($code.($second ? '_tech2' : '_tech'));
        TicketService::transition($ticket, 'accept');
        $this->clock($when->modify('+30 minutes'));
        TicketService::transition($ticket, 'start');
        if ($part) {
            InventoryService::issueToTicket($ticket, $part, $quantity, 'DEMO เบิกวัสดุซ่อมตามใบงาน ต้นทุนเชื่อมอัตโนมัติ');
        }
        $end = $when->modify('+'.(30 + $minutes).' minutes');
        $this->clock($end);
        TicketService::addWorkLog($ticket, (string) Time::toLocal($when->modify('+30 minutes')->format('Y-m-d H:i:s')), (string) Time::toLocal($end->format('Y-m-d H:i:s')), 'DEMO ตรวจหาสาเหตุ เปลี่ยนอะไหล่ ทดสอบระบบ และทำความสะอาดพื้นที่');
    }
    private function submitAndVerify(string $code, int $ticket, DateTimeImmutable $at, bool $second = false, bool $verify = true): void
    {
        $this->clock($at);
        $this->as($code.($second ? '_tech2' : '_tech'));
        TicketService::transition($ticket, 'submit', ['note' => 'DEMO ซ่อมเสร็จและทดสอบระบบแล้ว ส่งให้ผู้จัดการตรวจรับ']);
        if ($verify) {
            $this->clock($at->modify('+20 minutes'));
            $this->as($code.'_manager');
            TicketService::transition($ticket, 'verify', ['note' => 'DEMO ตรวจหน้างานแล้วใช้งานได้ปกติ อนุมัติปิดงาน']);
        }
    }
    private function manualCost(string $code, int $ticket, string $description, string $amount, bool $approve): int
    {
        $r = $this->resources[$code];
        $t = TicketService::load($ticket);
        $this->as($code.'_chief');
        $id = CostService::createManual($r['id'], ['category' => 'CONTRACTOR', 'description' => $description, 'quantity' => '1', 'unit_cost' => $amount, 'expense_date' => Time::today(), 'ticket_id' => $ticket, 'asset_id' => $t['asset_id'], 'supplier_id' => $r['supplier']], null, (int) Auth::id());
        Database::update('cost_entries', ['is_demo' => 1], 'id=?', [$id]);
        if ($approve) {
            $this->as($code.'_manager');
            CostService::decide($id, 'approve', null, (int) Auth::id());
        }
        $this->manifest['cost_ids'][] = $id;
        return $id;
    }

    private function createActiveScenarios(string $code): void
    {
        $definitions = [
            'new' => ['หลอดไฟห้อง 305 ไม่ติด รอหัวหน้ารับเรื่อง', '-45 minutes', 'ELEC', 'ELEC', 'NORMAL'],
            'assigned' => ['ก๊อกน้ำห้อง 201 หยด มอบหมายช่างแล้ว', '-4 hours', 'PLUMB', 'PUMP', 'NORMAL'],
            'working' => ['แอร์ห้อง 305 ไม่เย็น ช่างกำลังซ่อม', '-3 hours', 'AC', 'AC', 'HIGH'],
            'parts' => ['ปั๊มน้ำรั่ว รอซีลอะไหล่จากคลัง', '-2 days', 'PLUMB', 'PUMP', 'HIGH'],
            'contractor' => ['ตู้ไฟมีความร้อนสูง รอผู้รับเหมามาตรวจ', '-1 day', 'ELEC', 'ELEC', 'NORMAL'],
            'approval' => ['มอเตอร์ปั๊มชำรุด รออนุมัติค่าซ่อม', '-6 hours', 'PLUMB', 'PUMP', 'HIGH'],
            'verification' => ['ไฟทางเดินซ่อมเสร็จ รอผู้จัดการตรวจรับ', '-5 hours', 'ELEC', 'ELEC', 'NORMAL'],
            'overdue' => ['ปั๊มกรองสระหยุดทำงาน งานฉุกเฉินเกิน SLA', '-3 days', 'POOL', 'POOL', 'EMERGENCY'],
        ];
        foreach ($definitions as $key => [$title, $relative, $category, $asset, $priority]) {
            $at = $this->now->modify($relative);
            $ticket = $this->newTicket($code, $at, $title, $category, $asset, $priority);
            $this->manifest['hotels'][$code]['scenarios'][$key] = $ticket;
            if ($key === 'new') {
                continue;
            }
            if ($key === 'assigned') {
                $this->assign($code, $ticket, $at, true);
                continue;
            }
            $this->work($code, $ticket, $at, $key === 'verification', 60, $key === 'verification' ? $this->parts['LED'] : ($key === 'working' ? $this->parts['CAP'] : null), $key === 'verification' ? '2' : '1');
            if ($key === 'verification') {
                $this->submitAndVerify($code, $ticket, $at->modify('+110 minutes'), true, false);
            } elseif ($key === 'parts') {
                TicketService::transition($ticket, 'wait_parts', ['note' => 'DEMO อะไหล่ต่ำกว่าจุดสั่งซื้อ เปิดคำขอซื้อหรือขอโอนจากอีกโรงแรม']);
            } elseif ($key === 'contractor') {
                TicketService::transition($ticket, 'wait_contractor', ['note' => 'DEMO นัดผู้รับเหมาเข้าตรวจตู้ไฟในวันพรุ่งนี้']);
            } elseif ($key === 'approval') {
                TicketService::transition($ticket, 'request_approval', ['note' => 'DEMO ขออนุมัติเปลี่ยนมอเตอร์และค่าบริการภายนอก', 'amount' => '4500']);
            }
        }
    }

    private function createPm(string $code): void
    {
        $r = $this->resources[$code];
        foreach ([false, true] as $future) {
            $due = $this->now->setTimezone(Time::tz())->modify($future ? '+2 days' : '-7 days')->format('Y-m-d');
            $at = new DateTimeImmutable((string) Time::toUtc($due.' 08:00'), new \DateTimeZone('UTC'));
            $this->clock($future ? $this->now : $at);
            $this->as($code.'_chief');
            $plan = PMService::savePlan(null, $r['id'], ['task_name' => 'DEMO '.($future ? 'ตรวจปั๊มและระบบกรองสระล่วงหน้า' : 'ล้างแผ่นกรองและตรวจประสิทธิภาพแอร์').' · '.$code, 'description' => 'DEMO งานบำรุงรักษาตามแผน พร้อม checklist และประวัติผลตรวจ', 'category_id' => $this->categories[$future ? 'POOL' : 'AC'], 'priority_id' => $this->priorities['NORMAL'], 'asset_id' => $r['assets'][$future ? 'POOL' : 'AC'], 'frequency' => $future ? 'WEEKLY' : 'MONTHLY', 'start_date' => $due, 'lead_days' => $future ? 3 : 0, 'assigned_to' => $this->people[$code.'_tech2']['id'], 'planned_minutes' => 60], [['label' => 'ตรวจสภาพและทำความสะอาด', 'value_type' => 'CHECK'], ['label' => $future ? 'แรงดันระบบกรอง' : 'อุณหภูมิลมจ่าย', 'value_type' => 'NUMBER', 'unit' => $future ? 'bar' : '°C'], ['label' => 'บันทึกสภาพหลังตรวจ', 'value_type' => 'TEXT']], ['is_demo' => true]);
            PMService::generateDue($plan, $future ? $this->now->setTimezone(Time::tz())->format('Y-m-d') : $due);
            $wo = Database::first('SELECT * FROM pm_work_orders WHERE pm_plan_id=?', [$plan]);
            $ticket = (int) $wo['ticket_id'];
            $this->manifest['ticket_ids'][] = $ticket;
            $this->manifest['hotels'][$code]['pm'][$future ? 'upcoming' : 'completed'] = ['plan_id' => $plan, 'ticket_id' => $ticket, 'work_order_id' => (int) $wo['id']];
            if (!$future) {
                $this->clock($at->modify('+5 minutes'));
                $this->as($code.'_tech2');
                TicketService::transition($ticket, 'start');
                $this->clock($at->modify('+65 minutes'));
                TicketService::addWorkLog($ticket, (string) Time::toLocal($at->modify('+5 minutes')->format('Y-m-d H:i:s')), (string) Time::toLocal($at->modify('+65 minutes')->format('Y-m-d H:i:s')), 'DEMO ล้างแผ่นกรอง ตรวจท่อน้ำทิ้ง วัดอุณหภูมิและบันทึกผลตรวจ');
                $results = [];
                foreach (Database::select('SELECT checklist_item_id,value_type FROM pm_work_order_checklist WHERE pm_work_order_id=?', [$wo['id']]) as $item) {
                    $results[$item['checklist_item_id']] = ['done' => 1, 'value' => $item['value_type'] === 'NUMBER' ? '13.5' : 'DEMO สภาพปกติ ไม่มีการรั่วซึม', 'comment' => 'DEMO ตรวจครบตามแผน'];
                }
                PMService::saveChecklist((int) $wo['id'], $results);
                $this->submitAndVerify($code, $ticket, $at->modify('+70 minutes'), true);
            }
        }
    }

    private function createPurchases(array $hotels): void
    {
        foreach ($hotels as $i => $hotel) {
            $code = $hotel['code'];
            $this->as('store');
            $part = $this->parts[$i % 2 ? 'SEAL' : 'CAP'];
            $id = PurchaseService::create((int) $hotel['id'], ['part_id' => $part, 'quantity' => '5', 'unit_cost' => (string) Database::value('SELECT cost_price FROM spare_parts WHERE id=?', [$part]), 'supplier_id' => $this->resources[$code]['supplier'], 'note' => 'DEMO ขอซื้อเติมสต็อกหลังใช้ซ่อม']);
            if ($i > 0) {
                $this->as($code.'_manager');
                PurchaseService::action($id, $i === 3 ? 'reject' : 'approve', $i === 3 ? 'DEMO มีวัสดุทดแทนในคลัง ขอปรับรายการก่อน' : 'DEMO อนุมัติตามความจำเป็น');
            }
            if ($i === 2) {
                $this->as('store');
                PurchaseService::action($id, 'receive', 'DEMO ตรวจนับและรับเข้าคลังครบ');
            }
            $this->manifest['purchases'][$code] = $id;
        }
    }
    private function createTransfers(array $hotels): void
    {
        for ($i = 0; $i < 3; $i++) {
            $from = $hotels[$i];
            $to = $hotels[$i + 1];
            $this->as('store');
            $id = InventoryService::requestTransfer((int) $from['id'], (int) $to['id'], [['part_id' => $this->parts[$i === 2 ? 'PVC' : 'LED'], 'quantity' => (string) (5 - $i)]], 'DEMO โอนวัสดุระหว่างโรงแรมเพื่อให้งานซ่อมเดินต่อได้');
            if ($i > 0) {
                $this->as($from['code'].'_manager');
                InventoryService::transferAction($id, 'approve', 'DEMO อนุมัติและส่งวัสดุ');
            }
            if ($i === 2) {
                $this->as('store');
                InventoryService::transferAction($id, 'receive', 'DEMO ปลายทางรับวัสดุครบ');
            }
            $this->manifest['transfers'][] = $id;
        }
    }
    private function createLowStock(array $hotels): void
    {
        foreach ($hotels as $i => $hotel) {
            $this->as('store');
            $part = $this->parts[['CAP', 'SEAL', 'FILTER', 'LED'][$i]];
            InventoryService::adjustTo((int) $hotel['id'], $part, ['2', '1', '0', '4'][$i], 'DEMO ตรวจนับตัวอย่างเพื่อแสดงสัญญาณอะไหล่ใกล้หมด');
        }
    }
}
