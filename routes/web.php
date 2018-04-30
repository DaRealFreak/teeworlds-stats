<?php

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

# Navigation general routes
Route::get('/', 'MainController@general')->name('general');
Route::get('/search', 'SearchController@main')->name('search');
Route::get('/home', 'HomeController@index')->name('home');

# App specific routes
Route::get('/tee', 'SearchController@searchTee')->name('tee');
Route::get('/tee/{tee_id}/', ['as' => 'searchTeeByName', 'uses' => 'SearchController@searchTeeByName']);
Route::get('/clan', 'SearchController@searchClan')->name('clan');
Route::get('/clan/{clan_id}/', ['as' => 'searchClanByName', 'uses' => 'SearchController@searchClanByName']);
Route::get('/server', 'SearchController@searchServer')->name('server');
Route::get('/server/{server_id}/', ['as' => 'searchServerByName', 'uses' => 'SearchController@searchServerByName']);

# Authentication routes
Auth::routes();

# Test routes
Route::get('/test', function () {
    $user = (new \App\Models\Player)->find(15);
    $newUser = (new App\Models\Player)->where('name', '=', $user->name)->firstOrFail();
    dd($newUser->getAttributes());
    $clan = (new \App\Models\Clan())->find(4);
    $user->name = "test";
    $clan->players()->save($user);
    $user->save();
    return 'hello world';
});

