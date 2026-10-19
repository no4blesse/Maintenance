<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Response;
use App\Core\Session;
use App\Services\Audit;

final class AuthController extends Controller
{
    public function localSuper(): Response
    {
        \App\Services\LocalSuperAccess::enter($this->request);
        $this->flash('success', 'เข้าสู่ระบบด้วย Super Admin แล้ว สามารถตั้งรหัสผ่านใหม่ที่หน้าโปรไฟล์ภายใน 10 นาที');
        return $this->redirect($this->request->str('reset') === '1' ? '/profile' : '/');
    }

    public function showLogin(): Response
    {
        $hotels = Database::select('SELECT code, name_en, accent_color FROM hotels WHERE is_active = 1 ORDER BY sort_order');
        return $this->view('auth/login', ['title' => 'เข้าสู่ระบบ', 'hotels' => $hotels, 'email' => '', 'error' => null], 'layouts/guest');
    }

    public function login(): Response
    {
        $email = $this->request->str('email');
        $password = (string) ($this->request->post['password'] ?? '');
        if ($email === '' || $password === '') {
            return $this->loginError($email, __('auth.failed'));
        }
        $result = Auth::attempt($email, $password, $this->request->ip());
        if (!$result['ok']) {
            return $this->loginError($email, $result['error'] ?? __('auth.failed'));
        }
        Auth::login($result['user']);
        Audit::log('auth.login', 'user', (int) $result['user']['id']);
        $intended = Session::get('intended');
        Session::forget('intended');
        $target = (is_string($intended) && str_starts_with($intended, '/') && !str_starts_with($intended, '//')) ? $intended : '/';
        return $this->redirect($target);
    }

    private function loginError(string $email, string $error): Response
    {
        $hotels = Database::select('SELECT code, name_en, accent_color FROM hotels WHERE is_active = 1 ORDER BY sort_order');
        $r = $this->view('auth/login', ['title' => 'เข้าสู่ระบบ', 'hotels' => $hotels, 'email' => $email, 'error' => $error], 'layouts/guest');
        return new Response($r->body(), 422);
    }

    public function logout(): Response
    {
        Audit::log('auth.logout', 'user', Auth::id());
        Auth::logout();
        return $this->redirect('/login');
    }
}
