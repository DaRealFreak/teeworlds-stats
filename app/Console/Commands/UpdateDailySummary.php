<?php

namespace App\Console\Commands;

use App\Models\Clan;
use App\Models\Player;
use App\Models\Server;
use Carbon\Carbon;
use Illuminate\Console\Command;

class UpdateDailySummary extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'data:create-summary';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {

        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $usedDate = Carbon::today();
        $limitDate = $usedDate->copy()->addDay()->subSecond();

        /** @var \App\Models\DailySummary $dailySummary */
        $dailySummary = \App\Models\DailySummary::firstOrCreate(
            [
                'date' => $usedDate
            ]
        );

        $onlinePlayers = Player::whereBetween('last_seen', [$usedDate, $limitDate])->get()->count();
        $onlinePlayersPeak = Player::where('last_seen', '>=', Carbon::now()->subMinutes(env('CRONTASK_INTERVAL') + 1))->count();

        $onlineClans = Clan::with('players')->whereHas('players', function ($query) use ($usedDate, $limitDate) {
            /** @var Player $query */
            $query->whereBetween('last_seen', [$usedDate, $limitDate]);
        })->get()->count();
        $onlineClansPeak = Clan::with('players')->whereHas('players', function ($query) use ($usedDate, $limitDate) {
            /** @var Player $query */
            $query->whereBetween('last_seen', [Carbon::now()->subMinutes(env('CRONTASK_INTERVAL') + 1), Carbon::now()]);
        })->get()->count();

        $onlineServers = Server::whereBetween('last_seen', [$usedDate, $limitDate])->get()->count();
        $onlineServersPeak = Server::where('last_seen', '>=', Carbon::now()->subMinutes(env('CRONTASK_INTERVAL') + 1))->count();

        $dailySummary->setAttribute('players_online', $onlinePlayers);
        $dailySummary->setAttribute('clans_online', $onlineClans);
        $dailySummary->setAttribute('servers_online', $onlineServers);

        if ($onlinePlayersPeak > $dailySummary->getAttribute('players_online_peak')) {
            $dailySummary->setAttribute('players_online_peak', $onlinePlayersPeak);
        }

        if ($onlineClansPeak > $dailySummary->getAttribute('clans_online_peak')) {
            $dailySummary->setAttribute('clans_online_peak', $onlineClansPeak);
        }

        if ($onlineServersPeak > $dailySummary->getAttribute('servers_online_peak')) {
            $dailySummary->setAttribute('servers_online_peak', $onlineServersPeak);
        }

        $dailySummary->save();
        return true;
    }
}
