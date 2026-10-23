<?php

use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\API\CorrectionLinkController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Artisan;
use App\Sdks\Partner\IgniteClient;

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

Route::get('/run-storage-link', function () {
    Artisan::call('storage:link');
    return 'Storage link created successfully!';
});

Route::get('/test-ignite', function (IgniteClient $ignite) {
    $response = $ignite->memberDetails('AIA0938091-003');
    return response()->json($response);
});

Route::get('/correction/{token}',  [CorrectionLinkController::class, 'form'])->name('correction.form');
Route::post('/correction/{token}', [CorrectionLinkController::class, 'update'])->name('correction.update');
Route::get('/distributor/{id}', [AuthController::class, 'showHtml']);
