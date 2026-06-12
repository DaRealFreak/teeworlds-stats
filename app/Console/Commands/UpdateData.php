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
use App\Service\SessionRecorder;
use App\TwStats\Discovery\DdnetHttpSource;
use App\TwStats\Model\DiscoveredServer;
use App\TwStats\Discovery\ServerMerger;
use App\TwStats\Discovery\Teeworlds06Source;
use App\TwStats\Discovery\Teeworlds07Source;
use App\TwStats\Persistence\ServerPersister;
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
    protected $description = 'Scrape the configured server sources and update the stats database';

    public function __construct(
        private readonly SessionRecorder $sessionRecorder,
        private readonly DdnetHttpSource $ddnetHttpSource = new DdnetHttpSource(),
        private readonly Teeworlds07Source $teeworldsSevenSource = new Teeworlds07Source(),
        private readonly Teeworlds06Source $teeworldsSixSource = new Teeworlds06Source(),
        private readonly ServerMerger $serverMerger = new ServerMerger(),
        private readonly ServerPersister $serverPersister = new ServerPersister(),
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        // DDNet first: its servers.json carries real limits, players and cosmetics, so it wins the
        // merge over a UDP sighting of the same server. The 0.7 and native 0.6 sources then add the
        // stock Teeworlds servers that register only to teeworlds.com's master — the 0.6 source is
        // the fallback that keeps the 0.6 population reachable if DDNet's HTTP master ever goes down.
        $discovered = array_merge(
            $this->ddnetHttpSource->fetch(),
            $this->teeworldsSevenSource->fetch(),
            $this->teeworldsSixSource->fetch(),
        );
        $servers = $this->serverMerger->merge($discovered);

        foreach ($servers as $server) {
            $serverModel = $this->serverPersister->persist($server);
            $this->updateServerMapAndMod($serverModel, $server);
            $this->updatePlayers($server, $serverModel);
        }

        // close sessions of players who dropped off every tracked server this run
        $this->sessionRecorder->closeStale();

        return self::SUCCESS;
    }

    /**
     * resolve the server's current map and mod, then bump its server history
     */
    private function updateServerMapAndMod(Server $serverModel, DiscoveredServer $server): void
    {
        /** @var Map $mapModel */
        $mapModel = Map::firstOrCreate(['name' => $server->map]);
        /** @var Mod $modModel */
        [$modModel, $originalModModel] = $this->retrieveOrCreateMod($serverModel, $server->gametype);

        $this->updateServerHistory($serverModel, $mapModel, $modModel, $originalModModel);
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
                    'mod_original_id' => $originalModModel ? $originalModModel->getAttribute('id') : null,
                ]
            );
        }

        // update the history and persist the changes
        $historyEntry->setAttribute('minutes', $historyEntry->getAttribute('minutes') + env('CRONTASK_INTERVAL'));
        $historyEntry->save();
    }

    /**
     * persist the deduped players of one logical server: identity, clan membership, country,
     * the last-seen cosmetic snapshot, play history, and the discrete session
     *
     * @param DiscoveredServer $server
     * @param Server $serverModel
     */
    private function updatePlayers(DiscoveredServer $server, Server $serverModel): void
    {
        /** @var Map $mapModel */
        $mapModel = Map::firstOrCreate(['name' => $server->map]);
        /** @var Mod $modModel */
        [$modModel, $originalModModel] = $this->retrieveOrCreateMod($serverModel, $server->gametype);

        foreach ($server->clients as $client) {
            // players not yet connected have a placeholder name; skip empty/placeholder entries
            if ($client->name === '' || $client->name === '(connecting)') {
                continue;
            }

            /** @var Player $playerModel */
            $playerModel = Player::firstOrCreate(['name' => $client->name]);

            // update player last seen stat
            $playerModel->setAttribute('last_seen', Carbon::now());

            // leave the current clan when the player now reports no tag
            if ($client->clan === '' && $playerModel->clan()) {
                $playerModel->currentClanRecord()->update(['left_at' => Carbon::now()]);
            }

            if ($client->clan !== '') {
                $clanModel = Clan::firstOrCreate(['name' => $client->clan]);

                // leave the current clan if the player is now reporting a different tag
                if ($playerModel->clan() && $playerModel->clan()->getAttribute('name') !== $clanModel->getAttribute('name')) {
                    $playerModel->currentClanRecord()->update(['left_at' => Carbon::now()]);
                }

                // if the player has no clan yet or changed clans, record the new membership
                if (!$playerModel->clan() || $playerModel->clan()->getAttribute('name') !== $clanModel->getAttribute('name')) {
                    PlayerClanHistory::create(
                        [
                            'player_id' => $playerModel->getAttribute('id'),
                            'clan_id' => $clanModel->getAttribute('id'),
                            'joined_at' => Carbon::now()
                        ]
                    );
                }
            }

            // update player country stat (stored as a name, see Countries::getCodeByName)
            $playerModel->setAttribute('country', Countries::getCountryName($client->country));

            // the DDNet feed is the only source of cosmetics; refresh the snapshot only when this
            // observation carries it (afk is non-null for DDNet, null for UDP sources), so a 0.7/0.6
            // sighting of the same player name never wipes a previously-recorded DDNet skin/colors
            if ($client->afk !== null) {
                $playerModel->setAttribute('skin', $client->skin);
                $playerModel->setAttribute('color_body', $client->colorBody);
                $playerModel->setAttribute('color_feet', $client->colorFeet);
                $playerModel->setAttribute('afk', $client->afk);
                $playerModel->setAttribute('skin_parts', $client->skinParts);
            }

            $this->updatePlayerHistory($playerModel, $serverModel, $mapModel, $modModel, $originalModModel);

            $playerModel->save();

            // extend or open the player's discrete session for the sessions timeline
            $this->sessionRecorder->record($playerModel, $serverModel, $mapModel, $modModel);
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
                    'mod_original_id' => $originalModModel ? $originalModModel->getAttribute('id') : null,
                ]
            );
        }

        // update the history and persist the changes
        $historyEntry->setAttribute('minutes', $historyEntry->getAttribute('minutes') + env('CRONTASK_INTERVAL'));
        $historyEntry->save();
    }
}
