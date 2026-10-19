<?php

use App\Core\Env;

return [
    'channel_secret' => (string) Env::get('LINE_CHANNEL_SECRET', ''),
    'channel_access_token' => (string) Env::get('LINE_CHANNEL_ACCESS_TOKEN', ''),
    // 'line' sends real messages; 'mock' only records attempts locally
    'transport' => (string) Env::get('LINE_TRANSPORT', 'mock'),
    'push_endpoint' => 'https://api.line.me/v2/bot/message/push',
    'group_summary_endpoint' => 'https://api.line.me/v2/bot/group/%s/summary',
    'timeout_seconds' => 10,
    // simple pacing to respect LINE API rate limits
    'max_per_run' => 50,
];
