<?php

namespace App\Console\Commands;

use App\Models\Server;
use App\TwRequest\TwRequest;
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

    private function updateServer($server)
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
        $serverModel->setAttribute('mod', $server['gametype']);

        // if the server model only got created just now it doesn't have stats yet
        if (!$serverModel->stats()->first()) {
            $serverModel->stats()->create();
        }

        // check if the map already got tracked, create if not, else update the number of tracked times
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

        // save all changes to the model
        $serverModel->save();
    }

    private function updatePlayers($server)
    {
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

            $playerModel->setAttribute('country', $this->twRequest::getCountryName($player['country']));
            $playerModel->save();
        }
    }
}
