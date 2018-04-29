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

Route::get('/', function () {
    return view('welcome');
});

Auth::routes();

Route::get('/test', function () {
    $user = (new \App\Models\Player())->find(1);
    $clan = (new \App\Models\Clan())->find(4);
    $user->name = "test";
    $clan->players()->save($user);
    $user->save();
    return 'hello world';
});

Route::get('/home', 'HomeController@index')->name('home');
