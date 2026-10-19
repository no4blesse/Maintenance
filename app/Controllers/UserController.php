<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\{Auth,Database,Permissions,Response};
use App\Core\Exceptions\{ForbiddenException,NotFoundException,BusinessRuleException,ValidationException};
use App\Services\Audit;

final class UserController extends ModuleController
{
    private function admin(): void
    {
        if (!Auth::gate()->hasGlobal('users.manage')) {
            throw new ForbiddenException('จัดการบัญชีส่วนกลางได้โดยผู้ดูแลระดับกลุ่ม');
        }
    }
    private function find(int $id): array
    {
        $this->admin();
        return Database::first('SELECT id,name,email,phone,locale,is_active,must_change_password FROM users WHERE id=? AND deleted_at IS NULL', [$id]) ?? throw new NotFoundException();
    }
    public function index(): Response
    {
        $this->admin();
        $q = '%'.$this->request->str('q').'%';
        return $this->listing('ผู้ใช้งาน', Database::select('SELECT id,name,email,is_active,last_login_at FROM users WHERE deleted_at IS NULL AND (name LIKE ? OR email LIKE ?) ORDER BY name LIMIT 1000', [$q,$q]), ['name' => 'ชื่อ','email' => 'อีเมล','is_active' => 'เปิดใช้','last_login_at' => 'เข้าล่าสุด UTC'], ['/users/create' => 'เพิ่มผู้ใช้','/users/roles' => 'บทบาทและสิทธิ์'], fn ($r) => self::link('/users/'.$r['id'].'/edit', 'จัดการ'));
    }
    private function fields(bool $create = false): array
    {
        $f = ['name' => ['ชื่อ','text',[],true],'email' => ['อีเมล','email',[],true],'phone' => ['โทรศัพท์','text'],'locale' => ['ภาษา','select',['th' => 'ไทย','en' => 'English'],true],'is_active' => ['เปิดใช้งาน','select',[1 => 'ใช่',0 => 'ไม่'],true]];
        if ($create) {
            $f['password'] = ['รหัสผ่าน (อย่างน้อย 10 ตัว พิมพ์ใหญ่ เล็ก และตัวเลข)','password',[],true];
        }return $f;
    }
    public function create(): Response
    {
        $this->admin();
        return $this->form('เพิ่มผู้ใช้งาน', '/users', $this->fields(true), ['locale' => 'th','is_active' => 1]);
    }
    private function data(): array
    {
        $d = $this->validate(['name' => 'required|string|min:2|max:150','email' => 'required|email|max:190','phone' => 'nullable|string|max:50','locale' => 'required|in:th,en','is_active' => 'required|in:0,1']);
        $d['email'] = mb_strtolower($d['email']);
        return $d;
    }
    public function store(): Response
    {
        $this->admin();
        $d = $this->data();
        $pass = $this->validate(['password' => 'required|string|password|max:128']);
        if (Database::value('SELECT id FROM users WHERE email=?', [$d['email']])) {
            throw new ValidationException(['email' => 'อีเมลนี้ถูกใช้แล้ว']);
        }$id = Database::insert('users', $d + ['password_hash' => password_hash($pass['password'], PASSWORD_DEFAULT),'must_change_password' => 1]);
        Audit::log('user.create', 'user', $id, null, null, $d);
        return $this->saved('/users/'.$id.'/edit', 'เพิ่มผู้ใช้แล้ว กรุณากำหนดสิทธิ์โรงแรม');
    }
    public function update(int $id): Response
    {
        $u = $this->find($id);
        $d = $this->data();
        if (Database::value('SELECT id FROM users WHERE email=? AND id<>?', [$d['email'],$id])) {
            throw new ValidationException(['email' => 'อีเมลซ้ำ']);
        }
        Database::transaction(function () use ($id, $u, $d) {
            self::lockAdmins();
            if ((int)$d['is_active'] === 0) {
                $this->protectLastAdmin($id);
                if ($id === $this->userId()) {
                    throw new BusinessRuleException('ไม่สามารถปิดบัญชีตนเอง');
                }
            }Database::update('users', $d, 'id=?', [$id]);
            Audit::log('user.update', 'user', $id, null, $u, $d);
        });
        return $this->saved('/users/'.$id.'/edit');
    }
    private static function lockAdmins(): void
    {
        Database::select("SELECT u.id FROM users u JOIN user_hotel_roles ur ON ur.user_id=u.id JOIN role_permissions rp ON rp.role_id=ur.role_id JOIN permissions p ON p.id=rp.permission_id WHERE ur.hotel_id IS NULL AND p.code='users.manage' ORDER BY u.id FOR UPDATE");
    }
    private function protectLastAdmin(int $id, ?int $excludeAssignment = null): void
    {
        $n = Database::value("SELECT COUNT(DISTINCT u.id) FROM users u JOIN user_hotel_roles ur ON ur.user_id=u.id JOIN role_permissions rp ON rp.role_id=ur.role_id JOIN permissions p ON p.id=rp.permission_id WHERE u.is_active=1 AND u.deleted_at IS NULL AND ur.hotel_id IS NULL AND p.code='users.manage' AND ".($excludeAssignment ? 'ur.id<>?' : 'u.id<>?'), [$excludeAssignment ?? $id]);
        if ((int)$n === 0) {
            throw new BusinessRuleException('ต้องมีผู้ดูแลระดับกลุ่มที่ใช้งานได้อย่างน้อยหนึ่งบัญชี');
        }
    }
    public function edit(int $id): Response
    {
        $u = $this->find($id);
        $roles = Database::select('SELECT ur.id,r.name_th,h.name_th AS hotel FROM user_hotel_roles ur JOIN roles r ON r.id=ur.role_id LEFT JOIN hotels h ON h.id=ur.hotel_id WHERE ur.user_id=?', [$id]);
        return $this->view('users/edit', ['title' => 'จัดการ '.$u['name'],'u' => $u,'fields' => $this->fields(),'assignments' => $roles,'roleOptions' => $this->options('SELECT id,name_th AS name FROM roles ORDER BY sort_order'),'hotelOptions' => [0 => 'ทุกโรงแรม (ระดับกลุ่ม)'] + $this->hotelOptions('users.manage'),'rates' => Database::select('SELECT r.*,h.code FROM technician_rates r JOIN hotels h ON h.id=r.hotel_id WHERE r.user_id=? ORDER BY effective_from DESC', [$id])]);
    }
    public function addRole(int $id): Response
    {
        $this->find($id);
        $d = $this->validate(['role_id' => 'required|int','hotel_id' => 'required|int|min:0']);
        $r = Database::first('SELECT * FROM roles WHERE id=?', [$d['role_id']]);
        $hotel = $d['hotel_id'] ?: null;
        if (!$r || (!$hotel && !$r['is_global_capable'])) {
            throw new ValidationException(['role_id' => 'บทบาทนี้ต้องกำหนดรายโรงแรม']);
        }if ($hotel) {
            $this->authorize('users.manage', $hotel);
        }
        Database::execute('INSERT IGNORE INTO user_hotel_roles (user_id,hotel_id,role_id,created_by) VALUES (?,?,?,?)', [$id,$hotel,$d['role_id'],$this->userId()]);
        Audit::log('user.role_add', 'user', $id, $hotel, null, ['role' => $r['code']]);
        return $this->saved('/users/'.$id.'/edit');
    }
    public function removeRole(int $id, int $assignmentId): Response
    {
        $this->find($id);
        Database::transaction(function () use ($id, $assignmentId) {
            self::lockAdmins();
            $a = Database::first('SELECT * FROM user_hotel_roles WHERE id=? AND user_id=? FOR UPDATE', [$assignmentId,$id]);
            if (!$a) {
                throw new NotFoundException();
            }if ($a['hotel_id'] === null) {
                $this->protectLastAdmin($id, $assignmentId);
            }Database::execute('DELETE FROM user_hotel_roles WHERE id=? AND user_id=?', [$assignmentId,$id]);
            Audit::log('user.role_remove', 'user', $id, $a['hotel_id'] ? (int)$a['hotel_id'] : null, null, ['assignment' => $assignmentId]);
        });
        return $this->saved('/users/'.$id.'/edit');
    }
    public function addRate(int $id): Response
    {
        $this->find($id);
        $hotel = $this->hotelForWrite('users.manage');
        $d = $this->validate(['hourly_rate' => 'required|decimal|min:0|max:99999999','effective_from' => 'required|date']);
        if (!(new Permissions($id))->can('tickets.work', $hotel)) {
            throw new ValidationException(['hotel_id' => 'ผู้ใช้ยังไม่มีสิทธิ์ช่างในโรงแรมนี้']);
        }Database::execute('INSERT INTO technician_rates (user_id,hotel_id,effective_from,hourly_rate,created_by) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE hourly_rate=VALUES(hourly_rate)', [$id,$hotel,$d['effective_from'],$d['hourly_rate'],$this->userId()]);
        Audit::log('user.rate', 'user', $id, $hotel, null, $d);
        return $this->saved('/users/'.$id.'/edit');
    }
    public function resetPassword(int $id): Response
    {
        $this->find($id);
        $d = $this->validate(['password' => 'required|string|password|max:128']);
        Database::update('users', ['password_hash' => password_hash($d['password'], PASSWORD_DEFAULT),'must_change_password' => 1], 'id=?', [$id]);
        Audit::log('user.password_reset', 'user', $id);
        return $this->saved('/users/'.$id.'/edit');
    }
    public function roles(): Response
    {
        $this->admin();
        return $this->listing('บทบาทและสิทธิ์', Database::select('SELECT r.name_th AS role,p.module,p.code,p.description_th FROM roles r JOIN role_permissions rp ON rp.role_id=r.id JOIN permissions p ON p.id=rp.permission_id ORDER BY r.sort_order,p.module,p.code'), ['role' => 'บทบาท','module' => 'โมดูล','code' => 'สิทธิ์','description_th' => 'คำอธิบาย']);
    }
    public function profile(): Response
    {
        return $this->view('users/profile', ['title' => 'โปรไฟล์ของฉัน','u' => $this->user(), 'canRecoverPassword' => \App\Services\LocalSuperAccess::canResetPassword($this->userId(), $this->request)]);
    }
    public function updateProfile(): Response
    {
        $d = $this->validate(['name' => 'required|string|min:2|max:150','phone' => 'nullable|string|max:50','locale' => 'required|in:th,en']);
        Database::update('users', $d, 'id=?', [$this->userId()]);
        return $this->saved('/profile');
    }
    public function changePassword(): Response
    {
        $recovery = \App\Services\LocalSuperAccess::canResetPassword($this->userId(), $this->request);
        $d = $this->validate(['current_password' => ($recovery ? 'nullable' : 'required').'|string','password' => 'required|string|password|confirmed|max:128']);
        $hash = Database::value('SELECT password_hash FROM users WHERE id=?', [$this->userId()]);
        if (!$recovery && !password_verify($d['current_password'], $hash)) {
            throw new ValidationException(['current_password' => 'รหัสผ่านปัจจุบันไม่ถูกต้อง']);
        }Database::update('users', ['password_hash' => password_hash($d['password'], PASSWORD_DEFAULT),'must_change_password' => 0], 'id=?', [$this->userId()]);
        \App\Core\Session::regenerate();
        \App\Core\Session::forget('_local_super_recovery');
        Audit::log($recovery ? 'auth.local_super_password_reset' : 'user.password_change', 'user', $this->userId());
        return $this->saved('/profile');
    }
}
