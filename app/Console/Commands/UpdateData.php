<?php

namespace App\Console\Commands;

use App\Models\Clan;
use App\Models\Map;
use App\Models\Mod;
use App\Models\Player;
use App\Models\PlayerMapRecord;
use App\Models\PlayerModRecord;
use App\Models\Server;
use App\Models\ServerMapRecord;
use App\Models\ServerModRecord;
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
            $this->updateServer($server);
            $this->updatePlayers($server);
        }

        $this->info('update server data');
        return True;
    }

    /**
     * update the server data of the server we reached
     *
     * @param array $server
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

        if (!$serverModel->stats()->first()) {
            $serverModel->stats()->create();
        }

        $map = Map::firstOrCreate(['map' => $server['map']]);
        $mapRecord = ServerMapRecord::firstOrCreate(
            [
                'server_id' => $serverModel->getAttribute('id'),
                'map_id' => $map->getAttribute('id')
            ]
        );
        $mapRecord->setAttribute('minutes', $mapRecord->getAttribute('minutes') + env('CRONTASK_INTERVAL'));
        $serverModel->mapRecords()->save($mapRecord);

        // update server mod stat
        $mod = Mod::firstOrCreate(['mod' => $server['gametype']]);
        $modRecord = ServerModRecord::firstOrCreate(
            [
                'server_id' => $serverModel->getAttribute('id'),
                'mod_id' => $mod->getAttribute('id')
            ]
        );
        $modRecord->setAttribute('minutes', $modRecord->getAttribute('minutes') + env('CRONTASK_INTERVAL'));
        $serverModel->modRecords()->save($modRecord);

        // persist our changes
        $serverModel->save();
    }

    /**
     * update the player data with the data extracted from the server
     *
     * @param array $server
     */
    private function updatePlayers(array $server)
    {
        foreach ($server['players'] as $player) {
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

            // update player clan stat
            // if clan is set and player has no clan or different clan
            if ($player['clan'] && (!$playerModel->clan()->first() || $playerModel->clan()->first()->getAttribute('name') != $player['clan'])) {
                /** @var Clan $clanModel */
                $clanModel = Clan::firstOrCreate(
                    [
                        'name' => $player['clan'],
                    ]
                );
                $playerModel->clan()->associate($clanModel);
                $playerModel->setAttribute('clan_joined_at', Carbon::now());
            } elseif (!$player['clan'] && $playerModel->clan()->first()) {
                $playerModel->clan()->first()->delete();
            }

            // update player map stat
            $map = Map::firstOrCreate(['map' => $server['map']]);
            $mapRecord = PlayerMapRecord::firstOrCreate(
                [
                    'player_id' => $playerModel->getAttribute('id'),
                    'map_id' => $map->getAttribute('id')
                ]
            );
            $mapRecord->setAttribute('minutes', $mapRecord->getAttribute('minutes') + env('CRONTASK_INTERVAL'));
            $playerModel->mapRecords()->save($mapRecord);

            // update player mod stat
            $mod = Mod::firstOrCreate(['mod' => $server['gametype']]);
            $modRecord = PlayerModRecord::firstOrCreate(
                [
                    'player_id' => $playerModel->getAttribute('id'),
                    'mod_id' => $mod->getAttribute('id')
                ]
            );
            $modRecord->setAttribute('minutes', $modRecord->getAttribute('minutes') + env('CRONTASK_INTERVAL'));
            $playerModel->modRecords()->save($modRecord);

            // update player country stat
            $playerModel->setAttribute('country', TwRequest::getCountryName($player['country']));
            $playerModel->save();
        }
    }
}
