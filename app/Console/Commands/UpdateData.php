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
use App\TwRequest\TwRequest;
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
     * @var TwRequest
     */
    protected $twRequest;

    /**
     * Create a new command instance.
     *
     * @param TwRequest $twRequest
     */
    public function __construct(TwRequest $twRequest)
    {
        $this->twRequest = $twRequest;
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->twRequest->loadServersFromMasterservers();
        $this->twRequest->loadServerInfo();
        $this->twRequest->reloadFailedServerInfo();

        $servers = $this->twRequest->getServers();

        foreach ($servers as $server) {
            $serverModel = $this->updateServer($server);
            $this->updatePlayers($server, $serverModel);
        }

        $this->info('update server data');
        return True;
    }

    /**
     * update the server data of the server we reached
     *
     * @param array $server
     * @return Server
     */
    private function updateServer(array $server)
    {
        /** @var Server $serverModel */
        $serverModel = Server::firstOrCreate(
            [
                'ip' => $server[0],
                'port' => $server[1]
            ]
        );
        $serverModel->setAttribute('name', $server['name']);
        $serverModel->setAttribute('version', $server['version']);
        $serverModel->setAttribute('last_seen', Carbon::now());

        if (!$serverModel->stats()->first()) {
            $serverModel->stats()->create();
        }

        /** @var Map $mapModel */
        $mapModel = Map::firstOrCreate(['map' => $server['map']]);
        /** @var Mod $modModel */
        list($modModel, $originalModModel) = $this->retrieveOrCreateMod($serverModel, $server['gametype']);

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
            || $latestHistoryEntry->getAttribute('updated_at') < Carbon::now()->subMinutes(env('CRONTASK_INTERVAL') * 1.5)) {
            $historyEntry = ServerHistory::create(
                [
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
     * @param array $server
     * @param Server $serverModel
     * @return void
     */
    private function updatePlayers(array $server, Server $serverModel)
    {
        foreach ($server['players'] as $player) {
            // players not yet connected to the server have a unique entry, skip these
            if ($player['name'] == '(connecting)' && $player['country'] === -1 && $player['clan'] == '' && $player['score'] === 0) {
                continue;
            }

            /** @var Player $playerModel */
            $playerModel = Player::firstOrCreate(
                [
                    'name' => $player['name'],
                ]
            );

            // create stats if no stats set yet
            if (!$playerModel->stats()->first()) {
                $playerModel->stats()->create();
            }

            // update player online stats
            $currentHour = (int)Carbon::now()->format('H');
            $currentDay = strtolower(Carbon::now()->format('l'));
            $playerModel->stats()->first()->update([
                'hour_' . $currentHour => $playerModel->stats()->first()->getAttribute('hour_' . $currentHour) + env('CRONTASK_INTERVAL'),
                $currentDay => $playerModel->stats()->first()->getAttribute($currentDay) + env('CRONTASK_INTERVAL')
            ]);

            // update player last seen stat
            $playerModel->setAttribute('last_seen', Carbon::now());

            // if clan name is not empty and player has no clan associated
            // or player has a clan associated but the clan name differs from the current one
            if (($player['clan'] && !$playerModel->clan()) || ($playerModel->clan() && $playerModel->clan()->getAttribute('name') !== $player['clan'])) {
                $clanModel = Clan::firstOrCreate(
                    [
                        'name' => $player['clan'],
                    ]
                );

                // remove clan if player has a current clan record
                if ($playerModel->clan()) {
                    $playerModel->currentClanRecord()->update(['left_at' => Carbon::now()]);
                }

                PlayerClanHistory::create(
                    [
                        'player_id' => $playerModel->getAttribute('id'),
                        'clan_id' => $clanModel->getAttribute('id'),
                    ]
                );
            }

            // update player country stat
            $playerModel->setAttribute('country', TwRequest::getCountryName($player['country']));

            /** @var Map $mapModel */
            $mapModel = Map::firstOrCreate(['map' => $server['map']]);
            /** @var Mod $modModel */
            list($modModel, $originalModModel) = $this->retrieveOrCreateMod($serverModel, $server['gametype']);

            // $player['ingame'] is false if the player is spectating and not playing
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
        $mod = Mod::firstOrCreate(['mod' => $gameType]);

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
            || ($latestHistoryEntry && $latestHistoryEntry->isNot($historyEntry))
            || $latestHistoryEntry->getAttribute('updated_at') < Carbon::now()->subMinutes(env('CRONTASK_INTERVAL') * 1.5)) {
            $historyEntry = PlayerHistory::create(
                [
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
