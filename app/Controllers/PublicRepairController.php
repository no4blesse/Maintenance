<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\{Controller, Database, Response, Session};
use App\Core\Exceptions\{BusinessRuleException, HttpException, NotFoundException, ValidationException};
use App\Services\PublicRepairService;

final class PublicRepairController extends Controller
{
    public function index(): Response
    {
        $hotels = Database::select('SELECT id,code,name_th,name_en,accent_color FROM hotels WHERE is_active=1 ORDER BY sort_order,id');
        $legacy = $this->request->int('hotel_id');
        foreach ($hotels as $hotel) {
            if ((int) $hotel['id'] === $legacy) {
                return $this->redirect('/repair/'.rawurlencode($hotel['code']));
            }
        }
        return $this->view('public/hotels', ['title' => 'ช่องทางแจ้งซ่อมแต่ละโรงแรม', 'hotels' => $hotels], 'layouts/public');
    }

    private function hotel(string $code): array
    {
        return Database::first('SELECT id,code,name_th,name_en,accent_color FROM hotels WHERE code=? AND is_active=1', [strtoupper($code)]) ?? throw new NotFoundException();
    }

    private function key(array $hotel, string $name): string
    {
        return 'public_repair_'.$name.'_'.(int) $hotel['id'];
    }
    private function path(array $hotel): string
    {
        return '/repair/'.rawurlencode($hotel['code']);
    }

    public function create(string $hotelCode): Response
    {
        return $this->form($this->hotel($hotelCode));
    }

    private function form(array $hotel, array $old = [], array $errors = [], int $status = 200): Response
    {
        $key = $this->key($hotel, 'nonce');
        $nonce = Session::get($key);
        if (!is_string($nonce)) {
            $nonce = bin2hex(random_bytes(32));
            Session::set($key, $nonce);
        }
        $categories = Database::select('SELECT id,code,name_th FROM maintenance_categories WHERE is_active=1 ORDER BY sort_order,id');
        $old += ['category_id' => Database::value("SELECT id FROM maintenance_categories WHERE code='OTHER' AND is_active=1")];
        $photoMb = round(PublicRepairService::photoLimitBytes() / 1048576, 1);
        $repairPath = $this->path($hotel);
        $response = $this->view('public/repair', compact('old', 'errors', 'nonce', 'hotel', 'categories', 'photoMb', 'repairPath') + ['title' => 'แจ้งซ่อม · '.$hotel['name_th']], 'layouts/public');
        return Response::html($response->body(), $status);
    }

    public function store(string $hotelCode): Response
    {
        $hotel = $this->hotel($hotelCode);
        $nonce = $this->request->str('form_token');
        $current = Session::get($this->key($hotel, 'nonce'));
        $last = Session::get($this->key($hotel, 'last_nonce'));
        if ((!is_string($current) || !hash_equals($current, $nonce)) && (!is_string($last) || !hash_equals($last, $nonce))) {
            return $this->form($hotel, [], ['form' => 'แบบฟอร์มหมดอายุหรือไม่ใช่ของโรงแรมนี้ กรุณากรอกใหม่แล้วส่งอีกครั้ง'], 419);
        }
        $old = $this->request->only(['category_id','reporter_kind','reporter_name','contact','location_text','title','description']);
        try {
            if ($this->request->str('website') !== '') {
                throw new ValidationException(['form' => 'ไม่สามารถส่งข้อมูลนี้ได้ กรุณาลองใหม่']);
            }
            // Tenant comes from the active hotel in the URL, never from form/query data.
            $input = array_replace($this->request->all(), ['hotel_id' => (int) $hotel['id']]);
            $receipt = PublicRepairService::submit($input, $this->request->fileList('photos'), $this->request->ip(), $nonce);
        } catch (ValidationException $e) {
            return $this->form($hotel, $old, $e->errors, 422);
        } catch (BusinessRuleException $e) {
            return $this->form($hotel, $old, ['form' => $e->getMessage()], 422);
        } catch (HttpException $e) {
            if ($e->status !== 429) {
                throw $e;
            }
            return $this->form($hotel, $old, ['form' => $e->getMessage()], 429)->header('Retry-After', '3600');
        }
        Session::set($this->key($hotel, 'receipt'), $receipt);
        Session::set($this->key($hotel, 'last_nonce'), $nonce);
        Session::forget($this->key($hotel, 'nonce'));
        return $this->redirect($this->path($hotel).'/received');
    }

    public function received(string $hotelCode): Response
    {
        $hotel = $this->hotel($hotelCode);
        $receipt = Session::get($this->key($hotel, 'receipt'));
        if (!is_array($receipt)) {
            return $this->redirect($this->path($hotel));
        }
        return $this->view('public/received', ['title' => 'รับแจ้งซ่อมแล้ว · '.$hotel['name_th'], 'receipt' => $receipt, 'hotel' => $hotel, 'repairPath' => $this->path($hotel)], 'layouts/public');
    }
}
