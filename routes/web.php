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
Route::get('/server/edit/{clan_name}', 'InformationController@editServer')->name('editServer');

# App specific routes
Route::get('/tee', 'SearchController@searchTee')->name('tee');
Route::get('/tee/{tee_name}/', ['as' => 'searchTeeByName', 'uses' => 'SearchController@searchTeeByName']);
Route::get('/clan', 'SearchController@searchClan')->name('clan');
Route::get('/clan/{clan_name}/', ['as' => 'searchClanByName', 'uses' => 'SearchController@searchClanByName']);
Route::get('/server', 'SearchController@searchServer')->name('server');
Route::get('/server/{server_name}/', ['as' => 'searchServerByName', 'uses' => 'SearchController@searchServerByName']);

# Authentication routes
Auth::routes();

# Test routes
Route::get('/test', function () {
    $server = [
        0 => '194.67.208.6',
        1 => 8319,
        2 => 6,
        'version' => '0.6.4, 11.1.5',
        'name' => 'DDNet RUS - DDmaX [DDraceNetwork] [17/64]',
        'map' => 'FWYS 4',
        'gametype' => 'DDraceNetwork',
        'password' => false,
        'num_players' => 15,
        'max_players' => 16,
        'num_players_ingame' => 15,
        'max_players_ingame' => 16,
        'ping' => 145,
        'players' =>
            [
                0 =>
                    [
                        'name' => 'brainless tee',
                        'clan' => '',
                        'country' => -1,
                        'score' => -9999,
                        'ingame' => true,
                    ],
                1 =>
                    [
                        'name' => 'GoldDigga',
                        'clan' => 'gold medal',
                        'country' => -1,
                        'score' => -9999,
                        'ingame' => true,
                    ],
                2 =>
                    [
                        'name' => 'RavQ',
                        'clan' => 'FNG',
                        'country' => 616,
                        'score' => -9999,
                        'ingame' => true,
                    ],
                3 =>
                    [
                        'name' => 'Orbit',
                        'clan' => '',
                        'country' => -1,
                        'score' => -9999,
                        'ingame' => true,
                    ],
                4 =>
                    [
                        'name' => 'Krasty',
                        'clan' => 'FIXI',
                        'country' => 643,
                        'score' => -9999,
                        'ingame' => true,
                    ],
                5 =>
                    [
                        'name' => 'PBAB',
                        'clan' => '',
                        'country' => -1,
                        'score' => -9999,
                        'ingame' => true,
                    ],
                6 =>
                    [
                        'name' => 'GoldMedal',
                        'clan' => 'afk 5-10min',
                        'country' => -1,
                        'score' => -9999,
                        'ingame' => true,
                    ],
                7 =>
                    [
                        'name' => 'Fiksiki',
                        'clan' => 'Ihaveautism',
                        'country' => -1,
                        'score' => -9999,
                        'ingame' => true,
                    ],
                8 =>
                    [
                        'name' => 'Eklektiko',
                        'clan' => 'Pr0',
                        'country' => -1,
                        'score' => -9999,
                        'ingame' => true,
                    ],
                9 =>
                    [
                        'name' => 'Thanos',
                        'clan' => 'Rock',
                        'country' => 50,
                        'score' => -9999,
                        'ingame' => true,
                    ],
                10 =>
                    [
                        'name' => ':0',
                        'clan' => 'Saint d\'Arc',
                        'country' => 520,
                        'score' => -9999,
                        'ingame' => true,
                    ],
                11 =>
                    [
                        'name' => 'The Direwolf',
                        'clan' => 'jAGOD',
                        'country' => 854,
                        'score' => -9999,
                        'ingame' => true,
                    ],
                12 =>
                    [
                        'name' => 'The Direfox',
                        'clan' => '[DUMMY]',
                        'country' => -1,
                        'score' => -9999,
                        'ingame' => true,
                    ],
                13 =>
                    [
                        'name' => 'tinky',
                        'clan' => '',
                        'country' => 643,
                        'score' => -3187,
                        'ingame' => true,
                    ],
                14 =>
                    [
                        'name' => 'pentagon!',
                        'clan' => '',
                        'country' => -1,
                        'score' => -9999,
                        'ingame' => true,
                    ],
                15 =>
                    [
                        'name' => 'syn',
                        'clan' => 'Saint d\'Arc',
                        'country' => 524,
                        'score' => -9999,
                        'ingame' => true,
                    ],
            ],
    ];

    /** @var \App\Models\Server $serverModel */
    $serverModel = \App\Models\Server::firstOrCreate(
        [
            'ip' => $server[0],
            'port' => $server[1]
        ]
    );
    $serverModel->setAttribute('name', $server['name']);
    $serverModel->setAttribute('version', $server['version']);
    $serverModel->setAttribute('mod', $server['gametype']);
    if (!$serverModel->stats()->first()) {
        $serverModel->stats()->create();
    }

    $serverMapModel = $serverModel->maps->where('map', $server['map'])->first();
    if (!$serverMapModel) {
        $serverModel->maps()->create([
            'map' => $server['map']
        ]);
    } else {
        /** @var \App\Models\ServerMap $serverMapModel */
        $serverMapModel->setAttribute('times', $serverMapModel->getAttribute('times') + 1);
        $serverMapModel->save();
    }

    foreach ($server['players'] as $player) {
        /** @var \App\Models\Player $playerModel */
        $playerModel = \App\Models\Player::firstOrCreate(
            [
                'name' => $player['name'],
            ]
        );

        // create stats if no stats set yet
        if (!$playerModel->stats()->first()) {
            $playerModel->stats()->create();
        }

        // update player online stats
        $currentHour = \Carbon\Carbon::now()->format('H');
        $currentDay = strtolower(\Carbon\Carbon::now()->format('l'));
        $playerModel->stats()->first()->update([
            'hour_' . $currentHour => $playerModel->stats()->first()->getAttribute('hour_' . $currentHour) + 1,
            $currentDay => $playerModel->stats()->first()->getAttribute($currentDay) + 1
        ]);

        // update player clan stat
        // if clan is set and player has no clan or different clan
        if ($player['clan'] && (!$playerModel->clan()->first() || $playerModel->clan()->first()->getAttribute('name') != $player['clan'])) {
            /** @var \App\Models\Clan $clanModel */
            $clanModel = \App\Models\Clan::firstOrCreate(
                [
                    'name' => $player['clan'],
                ]
            );
            $playerModel->clan()->associate($clanModel);
            $playerModel->setAttribute('clan_joined_at', \Carbon\Carbon::now());
        } elseif (!$player['clan'] && $playerModel->clan()->first()) {
            $playerModel->clan()->first()->delete();
        }

        // update player map stat
        $playerMapModel = $playerModel->maps->where('map', $server['map'])->first();
        if (!$playerMapModel) {
            $playerModel->maps()->create([
                'map' => $server['map']
            ]);
        } else {
            /** @var \App\Models\PlayerMap $playerMapModel */
            $playerMapModel->setAttribute('times', $playerMapModel->getAttribute('times') + 1);
            $playerMapModel->save();
        }

        // update player mod stat
        $playerModModel = $playerModel->mods->where('mod', $server['gametype'])->first();
        if (!$playerModModel) {
            $playerModel->mods()->create([
                'mod' => $server['gametype']
            ]);
        } else {
            /** @var \App\Models\PlayerMap $playerModModel */
            $playerModModel->setAttribute('times', $playerModModel->getAttribute('times') + 1);
            $playerModModel->save();
        }

        $playerModel->setAttribute('country', \App\TwRequest\TwRequest::getCountryName($player['country']));
        $playerModel->save();
    }

    $serverModel->save();
    return 'hello world';
});

