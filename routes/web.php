<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Artisan;

Route::get('/', function () {
    return 'Home page works!';
});

Route::get('/clear-all', function () {
    Artisan::call('route:clear');
    Artisan::call('config:clear');
    Artisan::call('cache:clear');
    Artisan::call('view:clear');
    return 'All caches cleared!';
});

Route::get('/admin', function () {
    return 'Admin route works!';
});

Route::get('/test/{param}', function ($param) {
    return "Param: $param";
});