<?php

use App\Http\Controllers\AjaxSearchController;
use App\Http\Controllers\InformationController;
use App\Http\Controllers\MainController;
use App\Http\Controllers\SearchController;
use Illuminate\Support\Facades\Route;
use Spatie\ResponseCache\Middlewares\CacheResponse;

// Navigation general routes
Route::get('/', [MainController::class, 'home'])->name('home');
Route::get('/about', [MainController::class, 'about'])->name('about');
Route::get('/general', [MainController::class, 'general'])->name('general');
Route::get('/search', [SearchController::class, 'main'])->name('search');

// Edit routes (auth)
Route::get('/tee/edit/{tee_name}', [InformationController::class, 'editPlayer'])->name('editPlayer');
Route::get('/clan/edit/{clan_name}', [InformationController::class, 'editClan'])->name('editClan');
Route::get('/server/edit/{server_id}/{server_name}', [InformationController::class, 'editServer'])->name('editServer');

// Search + detail routes
Route::get('/tee', [SearchController::class, 'searchTee'])->name('tee');
Route::get('/tee/{tee_name}/', [SearchController::class, 'searchTeeByName'])->name('searchTeeByName');
Route::get('/clan', [SearchController::class, 'searchClan'])->name('clan');
Route::get('/clan/{clan_name}/', [SearchController::class, 'searchClanByName'])->name('searchClanByName');
Route::get('/server', [SearchController::class, 'searchServer'])->name('server');
Route::get('/server/{server_name}/', [SearchController::class, 'searchServerByName'])->name('searchServerByName');
Route::get('/server/{server_id}/{server_name}/', [SearchController::class, 'searchServerByIdAndName'])->name('searchServerByIdAndName');
Route::get('/mod', [SearchController::class, 'searchMod'])->name('mod');
Route::get('/mod/{mod_name}/', [SearchController::class, 'searchModByName'])->name('searchModByName');
Route::get('/map', [SearchController::class, 'searchMap'])->name('map');
Route::get('/map/{map_name}/', [SearchController::class, 'searchMapByName'])->name('searchMapByName');

// List routes
Route::get('/tees', [MainController::class, 'players'])->name('players');
Route::get('/clans', [MainController::class, 'clans'])->name('clans');
Route::get('/servers', [MainController::class, 'servers'])->name('servers');
// Live server browser (cached; invalidated by responsecache:clear after each scrape)
Route::get('/serverbrowser', [MainController::class, 'liveServers'])
    ->middleware(CacheResponse::class)
    ->name('serverbrowser');
Route::get('/mods', [MainController::class, 'mods'])->name('mods');
Route::get('/maps', [MainController::class, 'maps'])->name('maps');

// Ajax search routes
Route::get('/search/tee', [AjaxSearchController::class, 'searchTee'])->name('searchTeeAjax');
Route::get('/search/clan', [AjaxSearchController::class, 'searchClan'])->name('searchClanAjax');
Route::get('/search/server', [AjaxSearchController::class, 'searchServer'])->name('searchServerAjax');
Route::get('/search/mod', [AjaxSearchController::class, 'searchMod'])->name('searchModAjax');
Route::get('/search/map', [AjaxSearchController::class, 'searchMap'])->name('searchMapAjax');

// Authentication routes (laravel/ui) — registration is closed; this is an admin-only app.
// Admins are provisioned with `php artisan admin:create`.
Auth::routes(['register' => false]);
