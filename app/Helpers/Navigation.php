<?php

declare(strict_types=1);

namespace App\Helpers;

/** Sidebar navigation; visibility is permission-driven. */
final class Navigation
{
    /** @return list<array{group:string, items:list<array{label:string, icon:string, url:string, match:string, perms:list<string>}>}> */
    public static function menu(): array
    {
        $groups = [
            ['group' => 'ปฏิบัติการ', 'items' => [
                ['label' => 'แดชบอร์ด', 'icon' => 'layout-dashboard', 'url' => '/', 'match' => '#^/$#', 'perms' => ['dashboard.view']],
                ['label' => 'งานแจ้งซ่อม', 'icon' => 'clipboard-list', 'url' => '/tickets', 'match' => '#^/tickets#', 'perms' => ['tickets.view', 'tickets.view_own', 'tickets.work']],
                ['label' => 'ปฏิทินติดตามงาน', 'icon' => 'calendar-days', 'url' => '/calendar', 'match' => '#^/calendar#', 'perms' => ['calendar.view']],
            ]],
            ['group' => 'ทรัพย์สินและคลัง', 'items' => [
                ['label' => 'คลังอะไหล่', 'icon' => 'package', 'url' => '/inventory', 'match' => '#^/inventory#', 'perms' => ['inventory.view']],
                ['label' => 'ทะเบียนอุปกรณ์', 'icon' => 'cpu', 'url' => '/assets', 'match' => '#^/(assets|a/)#', 'perms' => ['assets.view']],
                ['label' => 'แผนบำรุงรักษา PM', 'icon' => 'calendar-check', 'url' => '/pm', 'match' => '#^/pm#', 'perms' => ['pm.view']],
            ]],
            ['group' => 'วิเคราะห์และการเงิน', 'items' => [
                ['label' => 'รายงาน KPI', 'icon' => 'trending-up', 'url' => '/reports', 'match' => '#^/reports#', 'perms' => ['reports.view']],
                ['label' => 'ต้นทุนและงบประมาณ', 'icon' => 'wallet', 'url' => '/costs', 'match' => '#^/costs#', 'perms' => ['costs.view']],
            ]],
            ['group' => 'ผู้ดูแลระบบ', 'items' => [
                ['label' => 'ผู้ใช้งานและสิทธิ์', 'icon' => 'users', 'url' => '/users', 'match' => '#^/users#', 'perms' => ['users.manage']],
                ['label' => 'LINE Bot แจ้งเตือน', 'icon' => 'message-circle', 'url' => '/line', 'match' => '#^/line#', 'perms' => ['line.manage']],
                ['label' => 'ตั้งค่าระบบ', 'icon' => 'settings', 'url' => '/settings', 'match' => '#^/settings#', 'perms' => ['settings.manage', 'hotels.manage', 'audit.view']],
            ]],
        ];
        if (\App\Services\DemoShowcaseSeeder::isTestWorkspace() && \App\Core\Auth::gate()->hasGlobal('users.manage') && \App\Services\DemoShowcaseSeeder::manifest()) {
            array_unshift($groups[0]['items'], ['label' => 'เริ่มสาธิตระบบ', 'icon' => 'presentation', 'url' => '/demo', 'match' => '#^/demo$#', 'perms' => ['users.manage']]);
        }
        $out = [];
        foreach ($groups as $g) {
            $items = array_values(array_filter($g['items'], static function (array $i): bool {
                foreach ($i['perms'] as $p) {
                    if (can($p)) {
                        return true;
                    }
                }
                return false;
            }));
            if ($items) {
                $out[] = ['group' => $g['group'], 'items' => $items];
            }
        }
        return $out;
    }
}
