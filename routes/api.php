<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;

Route::prefix('v1')->group(base_path('routes/api/v1.php'));

Route::get('debug/request', function (Request $request) {
    return [
        'ip' => $request->ip(),
        'scheme' => $request->getScheme(),
        'secure' => $request->isSecure(),
        'url' => url('/'),
        'host' => $request->getHost(),
    ];
});
