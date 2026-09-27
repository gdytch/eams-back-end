<?php

return [
    'quality' => 82,
    'max_upload_bytes' => 10 * 1024 * 1024,
    'max_width' => 8192,
    'max_height' => 8192,
    // Bounds one true-color decoded raster to about 46 MiB.
    'max_pixels' => 12000000,

    'presets' => [
        'profile_photo' => [
            'sm' => 64,
            'md' => 256,
            'lg' => 512,
            'original' => 2000,
        ],
        'event_banner' => [
            'sm' => 480,
            'md' => 1024,
            'lg' => 1920,
            'original' => 3000,
        ],
    ],
];
