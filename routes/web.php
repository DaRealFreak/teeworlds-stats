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
Route::get('/', 'MainController@home')->name('general');
Route::get('/about', 'MainController@about')->name('about');
Route::get('/general', 'MainController@general')->name('general');
Route::get('/home', 'MainController@home')->name('home');
Route::get('/search', 'SearchController@main')->name('search');

# Navigation if logged in routes
Route::get('/tee/edit/{tee_name}', 'InformationController@editPlayer')->name('editPlayer');
Route::get('/clan/edit/{clan_name}', 'InformationController@editClan')->name('editClan');
Route::get('/server/edit/{server_id}/{server_name}', 'InformationController@editServer')->name('editServer');

# App specific routes
Route::get('/tee', 'SearchController@searchTee')->name('tee');
Route::get('/tee/{tee_name}/', ['as' => 'searchTeeByName', 'uses' => 'SearchController@searchTeeByName']);
Route::get('/clan', 'SearchController@searchClan')->name('clan');
Route::get('/clan/{clan_name}/', ['as' => 'searchClanByName', 'uses' => 'SearchController@searchClanByName']);
Route::get('/server', 'SearchController@searchServer')->name('server');
Route::get('/server/{server_name}/', ['as' => 'searchServerByName', 'uses' => 'SearchController@searchServerByName']);
Route::get('/server/{server_id}/{server_name}/', ['as' => 'searchServerByIdAndName', 'uses' => 'SearchController@searchServerByIdAndName']);

# Authentication routes
Auth::routes();

# Test routes
Route::get('/test', function () {
    $masterServers = \App\TwStats\Controller\MasterServerController::getServers();

    /** @var \App\TwStats\Models\GameServer[] $servers */
    $servers = [];
    foreach ($masterServers as $masterServer) {
        $servers = array_merge($servers, $masterServer->getAttribute('servers'));
    }

    \App\TwStats\Controller\GameServerController::fillServerInfo($servers);
    foreach ($servers as $server) {
        if ($server->getAttribute('players')) {
            $serverInfo = sprintf("%s, %s:%s [%d/%d] (%d)",
                $server->getAttribute('name'),
                $server->getAttribute('ip'),
                $server->getAttribute('port'),
                $server->getAttribute('numplayers'),
                $server->getAttribute('maxplayers'),
                count($server->getAttribute('players'))
            );
            var_dump($serverInfo);
            var_dump($server->getAttribute('players'));
        }
    }
    return "huge success";
});

# Create example rules for player and server mods
Route::get('/rules', function () {
    \App\Models\ModRule::firstOrCreate(
        [
            'decider' => 'mod',
            'rule' => '%fng%',
            'mod_id' => \App\Models\Mod::firstOrCreate(['mod' => 'FNG'])->getAttribute('id')
        ]
    );

    \App\Models\ModRule::firstOrCreate(
        [
            'decider' => 'server',
            'rule' => '%gores%',
            'mod_id' => \App\Models\Mod::firstOrCreate(['mod' => 'Gores'])->getAttribute('id')
        ]
    );
});

