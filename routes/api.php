<?php

use App\Http\Controllers\ReadApiController;
use App\Http\Middleware\AuthenticateReadToken;
use Illuminate\Support\Facades\Route;

Route::get('v1/jobs', [ReadApiController::class, 'index'])->defaults('resource', 'jobs')->middleware(['throttle:60,1', AuthenticateReadToken::class.':jobs:read'])->name('api.jobs');
Route::get('v1/properties', [ReadApiController::class, 'index'])->defaults('resource', 'properties')->middleware(['throttle:60,1', AuthenticateReadToken::class.':properties:read'])->name('api.properties');

Route::get('v1/leads', [ReadApiController::class, 'index'])->defaults('resource', 'leads')->middleware(['throttle:60,1', AuthenticateReadToken::class.':leads:read'])->name('api.leads');
Route::get('v1/invoices', [ReadApiController::class, 'index'])->defaults('resource', 'invoices')->middleware(['throttle:60,1', AuthenticateReadToken::class.':invoices:read'])->name('api.invoices');
