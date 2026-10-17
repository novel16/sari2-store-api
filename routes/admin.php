<?php

use App\Http\Controllers\Admin\AccountController;
use App\Http\Controllers\Admin\AccountSubscriptionController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\PlanController;
use App\Http\Controllers\Admin\StoreController;
use App\Http\Controllers\CurrentUserController;
use Illuminate\Support\Facades\Route;

// Inherits /api/v1/admin and the authenticated, active platform admin middleware.
Route::get('/me', CurrentUserController::class);
Route::get('/dashboard', DashboardController::class);
Route::get('/accounts', [AccountController::class, 'index']);
Route::get('/accounts/{account}', [AccountController::class, 'show'])->whereNumber('account');
Route::get('/accounts/{account}/subscription', [AccountSubscriptionController::class, 'show'])->whereNumber('account');
Route::get('/accounts/{account}/subscription/history', [AccountSubscriptionController::class, 'history'])->whereNumber('account');
Route::post('/accounts/{account}/subscription/preview', [AccountSubscriptionController::class, 'preview'])->whereNumber('account')->middleware('throttle:30,1');
Route::post('/accounts/{account}/subscription/activations', [AccountSubscriptionController::class, 'store'])->whereNumber('account')->middleware('throttle:30,1');
Route::get('/stores', [StoreController::class, 'index']);
Route::get('/stores/{store}', [StoreController::class, 'show'])->whereNumber('store');
Route::get('/plans', [PlanController::class, 'index']);
Route::post('/plans', [PlanController::class, 'store'])->middleware('throttle:30,1');
Route::get('/plans/{plan}', [PlanController::class, 'show'])->whereNumber('plan');
Route::patch('/plans/{plan}', [PlanController::class, 'update'])->whereNumber('plan')->middleware('throttle:30,1');
Route::get('/plans/{plan}/versions', [PlanController::class, 'versions'])->whereNumber('plan');
