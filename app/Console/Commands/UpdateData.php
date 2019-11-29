<?php

namespace App\Console\Commands;

use App\Models\Clan;
use App\Models\Map;
use App\Models\Mod;
use App\Models\ModRule;
use App\Models\Player;
use App\Models\PlayerClanHistory;
use App\Models\PlayerHistory;
use App\Models\Server;
use App\Models\ServerHistory;
use App\TwStats\Controller\GameServerController;
use App\TwStats\Controller\MasterServerController;
use App\TwStats\Controller\NetworkController;
use App\TwStats\Models\GameServer;
use App\TwStats\Utility\Countries;
use Carbon\Carbon;
use Illuminate\Console\Command;

class UpdateData extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'data:update';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        // get the master servers with the game server information loaded
        $masterServers = MasterServerController::getServers();

        $servers = [];
        foreach ($masterServers as $masterServer) {
            $servers = array_merge($servers, $masterServer->getAttribute('servers'));
        }
        $servers = array_unique($servers);

        GameServerController::fillServerInfo($servers);

        /** @var GameServer $server */
        foreach ($servers as $index => $server) {
            if (!$server->getAttribute('response')) {
                $failedServers[] = $server;
                unset($servers[$index]);
            }
        }

        if (isset($failedServers)) {
            usleep(NetworkController::CONNECTION_SLEEP_DURATION * 1000);
            GameServerController::fillServerInfo($failedServers);
            $servers = array_merge($servers, $failedServers);
        }

        foreach ($servers as $server) {
            if ($server->getAttribute('response')) {
                $serverModel = $this->updateServer($server);
                $this->updatePlayers($server, $serverModel);
            } else {
                $failedServers[] = $server;
                $this->info("could not receive a response of: " . $server->getAttribute('ip') . ":" . $server->getAttribute('port'));
            }
        }
        return True;
    }

    /**
     * update the server data of the server we reached
     *
     * @param GameServer $server
     * @return Server
     */
    private function updateServer(GameServer $server)
    {
        /** @var Server $serverModel */
        $serverModel = Server::firstOrCreate(
            [
                'ip' => $server->getAttribute('ip'),
                'port' => $server->getAttribute('port')
            ]
        );
        $serverModel->setAttribute('name', $server->getAttribute('name'));
        $serverModel->setAttribute('version', $server->getAttribute('version'));
        $serverModel->setAttribute('last_seen', Carbon::now());

        /** @var Map $mapModel */
        $mapModel = Map::firstOrCreate(['name' => $server->getAttribute('map')]);
        /** @var Mod $modModel */
        list($modModel, $originalModModel) = $this->retrieveOrCreateMod($serverModel, $server->getAttribute('gametype'));

        $this->updateServerHistory($serverModel, $mapModel, $modModel, $originalModModel);

        // persist our changes
        $serverModel->save();

        return $serverModel;
    }

    /**
     * update or create the server history with the data extracted from the server
     *
     * @param Server $serverModel
     * @param Map $mapModel
     * @param Mod $modModel
     * @param Mod|null $originalModModel
     */
    private function updateServerHistory(Server $serverModel, Map $mapModel, Mod $modModel, ?Mod $originalModModel)
    {
        // retrieve the latest history for the server
        /** @var ServerHistory $latestHistoryEntry */
        $latestHistoryEntry = ServerHistory::where(
            [
                'server_id' => $serverModel->getAttribute('id'),
            ]
        )->orderByDesc('updated_at')->first();

        // retrieve the latest history for the server for map and mod
        /** @var ServerHistory $historyEntry */
        $historyEntry = ServerHistory::orderByDesc('updated_at')->where(
            [
                'server_id' => $serverModel->getAttribute('id'),
                'map_id' => $mapModel->getAttribute('id'),
                'mod_id' => $modModel->getAttribute('id')
            ]
        )->first();

        // if no history for this server for this map and mod is set
        // or it's not the latest history entry or more than 1.5 times the cron interval ago create a new one
        if (!$historyEntry
            || ($latestHistoryEntry && $latestHistoryEntry->isNot($historyEntry))
            || $latestHistoryEntry->getAttribute('updated_at') < Carbon::now()->subMinutes(env('CRONTASK_INTERVAL') * 1.5)
            || $latestHistoryEntry->getAttribute('hour') !== Carbon::now()->hour
            || $latestHistoryEntry->getAttribute('weekday') !== Carbon::now()->dayOfWeekIso - 1
        ) {
            if (!$latestHistoryEntry
                || $latestHistoryEntry->map->isNot($mapModel)
                || $latestHistoryEntry->mod->isNot($modModel)
                || $latestHistoryEntry->server->isNot($serverModel)) {
                $continuous = False;
            } else {
                $continuous = $latestHistoryEntry->getAttribute('updated_at') >= Carbon::now()->subMinutes(env('CRONTASK_INTERVAL') * 1.5) &&
                    ($latestHistoryEntry->getAttribute('hour') !== Carbon::now()->hour
                        || $latestHistoryEntry->getAttribute('weekday') !== Carbon::now()->dayOfWeekIso - 1);
            }

            $historyEntry = ServerHistory::create(
                [
                    'weekday' => Carbon::now()->dayOfWeekIso - 1,
                    'hour' => Carbon::now()->hour,
                    'continuous' => $continuous,
                    'server_id' => $serverModel->getAttribute('id'),
                    'map_id' => $mapModel->getAttribute('id'),
                    'mod_id' => $modModel->getAttribute('id'),
                    'mod_original_id' => $originalModModel ? $originalModModel->getAttribute('id') : null
                ]
            );
        }

        // update the history and persist the changes
        $historyEntry->setAttribute('minutes', $historyEntry->getAttribute('minutes') + env('CRONTASK_INTERVAL'));
        $historyEntry->save();
    }

    /**
     * update the player data with the data extracted from the server
     *
     * @param GameServer $server
     * @param Server $serverModel
     * @return void
     */
    private function updatePlayers(GameServer $server, Server $serverModel)
    {
        /** @var \App\TwStats\Models\Player $player */
        foreach ($server->getAttribute('players') as $player) {
            // players not yet connected to the server have a unique entry, skip these
            if ($player->getAttribute('name') == '(connecting)') {
                continue;
            }

            if (!$player->getAttribute('name')) {
                continue;
            }

            /** @var Player $playerModel */
            $playerModel = Player::firstOrCreate(
                [
                    'name' => $player->getAttribute('name'),
                ]
            );

            // update player last seen stat
            $playerModel->setAttribute('last_seen', Carbon::now());

            if ($player->getAttribute('clan') == '' && $playerModel->clan()) {
                $playerModel->currentClanRecord()->update(['left_at' => Carbon::now()]);
            }

            // if clan name is not empty and player has no clan associated
            // or player has a clan associated but the clan name differs from the current one
            if ($player->getAttribute('clan') != '') {
                $clanModel = Clan::firstOrCreate(
                    [
                        'name' => $player->getAttribute('clan')
                    ]
                );

                // leave the current clan if the player has a clan already and is having currently a different clan tag
                if ($playerModel->clan() && $playerModel->clan()->getAttribute('name') !== $clanModel->getAttribute('name')) {
                    $playerModel->currentClanRecord()->update(['left_at' => Carbon::now()]);
                }

                // if the player doesn't have a clan yet or changed clans create a new history entry
                if (!$playerModel->clan() || ($playerModel->clan() && $playerModel->clan()->getAttribute('name') !== $clanModel->getAttribute('name'))) {
                    PlayerClanHistory::create(
                        [
                            'player_id' => $playerModel->getAttribute('id'),
                            'clan_id' => $clanModel->getAttribute('id'),
                            'joined_at' => Carbon::now()
                        ]
                    );
                }
            }

            // update player country stat
            $playerModel->setAttribute('country', Countries::getCountryName($player->getAttribute('country')));

            /** @var Map $mapModel */
            $mapModel = Map::firstOrCreate(['name' => $server->getAttribute('map')]);
            /** @var Mod $modModel */
            list($modModel, $originalModModel) = $this->retrieveOrCreateMod($serverModel, $server->getAttribute('gametype'));

            // $player->getAttribute('ingame') is false if the player is spectating and not playing
            // maybe don't update play history if not set?
            $this->updatePlayerHistory($playerModel, $serverModel, $mapModel, $modModel, $originalModModel);

            $playerModel->save();
        }
    }

    /**
     * @param Server $serverModel
     * @param string $gameType
     * @return array
     */
    private function retrieveOrCreateMod(Server $serverModel, string $gameType)
    {
        $mod = Mod::firstOrCreate(['name' => $gameType]);

        /** @var ModRule $modRule */
        foreach (ModRule::orderBy('priority')->get() as $modRule) {
            if ($modRule->getAttribute('decider') == 'server') {
                if ($modRule->servers()->contains($serverModel)) {
                    $originalMod = $mod;
                    $mod = $modRule->mod;
                    break;
                }
            } else {
                if ($modRule->mods()->contains($mod)) {
                    $originalMod = $mod;
                    $mod = $modRule->mod;
                    break;
                }
            }
        }
        if (!isset($originalMod)) {
            $originalMod = null;
        }

        return [$mod, $originalMod];
    }

    /**
     * update or create the player history with the data extracted from the server
     *
     * @param Player $playerModel
     * @param Server $serverModel
     * @param Map $mapModel
     * @param Mod $modModel
     * @param Mod|null $originalModModel
     */
    private function updatePlayerHistory(Player $playerModel, Server $serverModel, Map $mapModel, Mod $modModel, ?Mod $originalModModel)
    {
        // retrieve the latest history for the player
        /** @var PlayerHistory $latestHistoryEntry */
        $latestHistoryEntry = PlayerHistory::where(
            [
                'player_id' => $playerModel->getAttribute('id'),
            ]
        )->orderByDesc('updated_at')->first();

        // retrieve the latest history for the player for the server and map
        /** @var PlayerHistory $historyEntry */
        $historyEntry = PlayerHistory::orderByDesc('updated_at')->where(
            [
                'player_id' => $playerModel->getAttribute('id'),
                'server_id' => $serverModel->getAttribute('id'),
                'map_id' => $mapModel->getAttribute('id'),
                'mod_id' => $modModel->getAttribute('id')
            ]
        )->first();

        // if no history for this server and map is set or it's not the latest history in general create a new one
        if (!$historyEntry
            || $latestHistoryEntry->isNot($historyEntry)
            || $latestHistoryEntry->getAttribute('updated_at') < Carbon::now()->subMinutes(env('CRONTASK_INTERVAL') * 1.5)
            || $latestHistoryEntry->getAttribute('hour') !== Carbon::now()->hour
            || $latestHistoryEntry->getAttribute('weekday') !== Carbon::now()->dayOfWeekIso - 1
        ) {
            if (!$latestHistoryEntry
                || $latestHistoryEntry->map->isNot($mapModel)
                || $latestHistoryEntry->mod->isNot($modModel)
                || $latestHistoryEntry->server->isNot($serverModel)) {
                $continuous = False;
            } else {
                $continuous = $latestHistoryEntry->getAttribute('updated_at') >= Carbon::now()->subMinutes(env('CRONTASK_INTERVAL') * 1.5) &&
                    ($latestHistoryEntry->getAttribute('hour') !== Carbon::now()->hour
                        || $latestHistoryEntry->getAttribute('weekday') !== Carbon::now()->dayOfWeekIso - 1);
            }

            $historyEntry = PlayerHistory::create(
                [
                    'weekday' => Carbon::now()->dayOfWeekIso - 1,
                    'hour' => Carbon::now()->hour,
                    'continuous' => $continuous,
                    'player_id' => $playerModel->getAttribute('id'),
                    'server_id' => $serverModel->getAttribute('id'),
                    'map_id' => $mapModel->getAttribute('id'),
                    'mod_id' => $modModel->getAttribute('id'),
                    'mod_original_id' => $originalModModel ? $originalModModel->getAttribute('id') : null
                ]
            );
        }

        // update the history and persist the changes
        $historyEntry->setAttribute('minutes', $historyEntry->getAttribute('minutes') + env('CRONTASK_INTERVAL'));
        $historyEntry->save();
    }
}
