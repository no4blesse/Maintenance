<?php

use App\Core\Env;

return [
    // Private storage root (never inside public/)
    'root' => BASE_PATH . '/storage/uploads',
    'max_mb' => (int) Env::get('UPLOAD_MAX_MB', 10),
    // Allowed types detected by finfo (content sniffing), mapped to safe extensions
    'images' => [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ],
    'documents' => [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ],
];
