<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\{Controller, Database, HotelContext, Response};
use App\Core\Exceptions\NotFoundException;

/** Shared rendering for operational lists and validated forms. SQL and fields stay in each module. */
abstract class ModuleController extends Controller
{
    protected function scope(string $permission, string $column = 'hotel_id'): array
    {
        $ids = HotelContext::scope($permission);
        return [$column . ' IN (' . Database::placeholders($ids) . ')', $ids];
    }

    protected function record(string $table, int $id, string $permission): array
    {
        $row = Database::first("SELECT * FROM `$table` WHERE id = ?", [$id]);
        if (!$row) {
            throw new NotFoundException();
        }
        $this->authorize($permission, (int) $row['hotel_id']);
        return $row;
    }

    protected function listing(string $title, array $rows, array $columns, array $links = [], ?\Closure $actions = null, array $forms = [], string $notice = ''): Response
    {
        $query = $this->request->only(['q','from','to','status','year']);
        $filteredLinks = [];
        foreach ($links as $path => $caption) {
            $filteredLinks[$path.(str_contains($path, '/export') && $query ? ('?'.http_build_query($query)) : '')] = $caption;
        }
        $links = $filteredLinks;
        $search = mb_substr($this->request->str('q'), 0, 100);
        if ($search !== '') {
            $rows = array_values(array_filter($rows, function ($row) use ($columns, $search) {
                foreach (array_keys($columns) as $key) {
                    if (mb_stripos((string)($row[$key] ?? ''), $search) !== false) {
                        return true;
                    }
                }return false;
            }));
        }
        foreach ($forms as &$f) {
            if (isset($f['fields']['hotel_id'])) {
                $f['values']['hotel_id'] ??= $this->request->int('hotel_id') ?? HotelContext::selectedId();
            }
        }unset($f);
        $searchable = true;
        $dateFilters = str_starts_with($this->request->path, '/costs') && $this->request->path !== '/costs/budgets';
        return $this->view('shared/list', compact('title', 'rows', 'columns', 'links', 'actions', 'forms', 'notice', 'query', 'search', 'searchable', 'dateFilters'));
    }

    protected function form(string $title, string $action, array $fields, array $values = [], string $notice = ''): Response
    {
        if (isset($fields['hotel_id'])) {
            $values['hotel_id'] ??= $this->request->int('hotel_id') ?? HotelContext::selectedId();
        }
        return $this->view('shared/form', compact('title', 'action', 'fields', 'values', 'notice'));
    }

    protected function options(string $sql, array $params = [], string $label = 'name'): array
    {
        $out = [];
        foreach (Database::select($sql, $params) as $r) {
            $out[$r['id']] = $r[$label];
        }
        return $out;
    }

    protected function hotelOptions(string $permission): array
    {
        return array_column(HotelContext::hotelsFor($permission), 'name_th', 'id');
    }

    protected function saved(string $path, string $message = 'บันทึกเรียบร้อยแล้ว'): Response
    {
        $this->flash('success', $message);
        return $this->redirect($path);
    }

    /** Forms are server-defined; action URLs are always generated with url(). */
    public static function button(string $path, string $label, array $hidden = [], bool $reason = false): string
    {
        $confirmation = preg_match('#/(reverse|void|reject|unbind|delete|cancel|approve)$#', $path) ? ' data-confirm="'.e('ยืนยัน: '.$label.'?').'"' : '';
        $html = '<form method="post" action="' . e(url($path)) . '" class="d-inline-flex gap-2 my-1"'.$confirmation.'>' . csrf_field();
        foreach ($hidden as $k => $v) {
            $html .= '<input type="hidden" name="' . e($k) . '" value="' . e($v) . '">';
        }
        if ($reason) {
            $html .= '<input name="reason" class="form-control form-control-sm" placeholder="เหตุผล" minlength="3" maxlength="500" required aria-label="เหตุผล">';
        }
        return $html . '<button class="btn btn-sm btn-outline-primary">' . e($label) . '</button></form> ';
    }

    public static function link(string $path, string $label): string
    {
        return '<a class="btn btn-sm btn-light" href="' . e(url($path)) . '">' . e($label) . '</a> ';
    }
}
