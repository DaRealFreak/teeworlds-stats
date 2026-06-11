<?php

namespace App\Service;

use App\Models\Map;
use App\Models\Mod;
use App\Models\Player;
use App\Models\PlayerSession;
use App\Models\Server;
use Carbon\Carbon;

/**
 * Maintains the player_sessions log from the periodic UpdateData scrape.
 *
 * A player can only be on one server at a time, so each player has at most one open
 * session. While they keep being seen on the same server within the scrape window the
 * open session is extended; a server change or a gap longer than the window closes the
 * old session and opens a new one.
 */
class SessionRecorder
{
    /**
     * record one observation of a player on a server, returning the affected session
     */
    public function record(Player $player, Server $server, Map $map, Mod $mod): PlayerSession
    {
        $interval = $this->interval();

        $open = PlayerSession::where('player_id', $player->getAttribute('id'))
            ->whereNull('ended_at')
            ->orderByDesc('last_seen_at')
            ->first();

        // extend the running session when it is the same server and still fresh
        if ($open
            && (int)$open->getAttribute('server_id') === (int)$server->getAttribute('id')
            && $open->getAttribute('last_seen_at') >= $this->staleThreshold()
        ) {
            $open->setAttribute('minutes', $open->getAttribute('minutes') + $interval);
            $open->setAttribute('last_seen_at', Carbon::now());
            $open->setAttribute('map_id', $map->getAttribute('id'));
            $open->setAttribute('mod_id', $mod->getAttribute('id'));
            $open->save();

            return $open;
        }

        // a stale or different-server session is finished off before a fresh one starts
        if ($open) {
            $this->close($open);
        }

        return PlayerSession::create([
            'player_id' => $player->getAttribute('id'),
            'server_id' => $server->getAttribute('id'),
            'map_id' => $map->getAttribute('id'),
            'mod_id' => $mod->getAttribute('id'),
            'minutes' => $interval,
            'started_at' => Carbon::now(),
            'last_seen_at' => Carbon::now(),
        ]);
    }

    /**
     * close every open session that has not been refreshed within the scrape window;
     * these belong to players who have since left every tracked server
     *
     * @return int the number of sessions closed
     */
    public function closeStale(): int
    {
        $stale = PlayerSession::whereNull('ended_at')
            ->where('last_seen_at', '<', $this->staleThreshold())
            ->get();

        foreach ($stale as $session) {
            $this->close($session);
        }

        return $stale->count();
    }

    /**
     * end a session at the moment the player was last actually seen
     */
    private function close(PlayerSession $session): void
    {
        $session->setAttribute('ended_at', $session->getAttribute('last_seen_at'));
        $session->save();
    }

    /**
     * a session counts as live for 1.5 scrape intervals, matching Player::online()
     */
    private function staleThreshold(): Carbon
    {
        return Carbon::now()->subMinutes($this->interval() * 1.5);
    }

    private function interval(): int
    {
        return (int)env('CRONTASK_INTERVAL');
    }
}
