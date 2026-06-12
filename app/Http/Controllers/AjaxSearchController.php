<?php

namespace App\Http\Controllers;

use App\Models\Clan;
use App\Models\Map;
use App\Models\Mod;
use App\Models\Player;
use App\Models\Server;
use App\Service\FuzzySearch;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AjaxSearchController extends Controller
{

    /**
     * @param Request $request
     * @return string
     */
    public function searchTee(Request $request)
    {
        $term = Str::lower($request->input('term'));

        $playerSuggestions = Player::where('name', 'like', '%' . $term . '%')
            ->orderByRaw('`name` LIKE ? DESC', $term . '%')
            ->orderBy('name')
            ->limit(10)
            ->get();

        $suggestions = new Collection();
        foreach ($playerSuggestions as $suggestion) {
            $suggestions->add($suggestion->name);
        }

        return $suggestions->toJson();
    }

    /**
     * @param Request $request
     * @return string
     */
    public function searchClan(Request $request)
    {
        $term = Str::lower($request->input('term'));

        $clanSuggestions = Clan::where('name', 'like', '%' . $term . '%')
            ->orderByRaw('`name` LIKE ? DESC', $term . '%')
            ->orderBy('name')
            ->limit(10)
            ->get();

        $suggestions = new Collection();
        foreach ($clanSuggestions as $suggestion) {
            $suggestions->add($suggestion->name);
        }

        return $suggestions->toJson();
    }

    /**
     * @param Request $request
     * @return string
     */
    public function searchServer(Request $request)
    {
        $term = Str::lower($request->input('term'));

        $serverSuggestions = Server::where('name', 'like', '%' . $term . '%')
            ->orderByRaw('`name` LIKE ? DESC', $term . '%')
            ->orderBy('name')
            ->groupBy('name')
            ->limit(10)
            ->get();

        $suggestions = new Collection();
        foreach ($serverSuggestions as $suggestion) {
            $suggestions->add($suggestion->name);
        }

        return $suggestions->toJson();
    }

    /**
     * @param Request $request
     * @return string
     */
    public function searchMod(Request $request)
    {
        $term = Str::lower($request->input('term'));

        $modSuggestions = Mod::where('name', 'like', '%' . $term . '%')
            ->orderByRaw('`name` LIKE ? DESC', $term . '%')
            ->orderBy('name')
            ->limit(10)
            ->get();

        $suggestions = new Collection();
        foreach ($modSuggestions as $suggestion) {
            $suggestions->add($suggestion->name);
        }

        return $suggestions->toJson();
    }

    /**
     * @param Request $request
     * @return string
     */
    public function searchMap(Request $request)
    {
        $term = Str::lower($request->input('term'));

        $mapSuggestions = Map::where('name', 'like', '%' . $term . '%')
            ->orderByRaw('`name` LIKE ? DESC', $term . '%')
            ->orderBy('name')
            ->limit(10)
            ->get();

        $suggestions = new Collection();
        foreach ($mapSuggestions as $suggestion) {
            $suggestions->add($suggestion->name);
        }

        return $suggestions->toJson();
    }

    /**
     * Unified search across every entity type for the navbar global-search box.
     * Returns up to 5 relevance-ranked matches per type (via FuzzySearch), each with a
     * detail URL built here — the server owns the route patterns and the name encoding.
     */
    public function searchGlobal(Request $request)
    {
        $term = trim((string) $request->input('term'));

        $empty = [
            'players' => [], 'clans' => [], 'servers' => [], 'maps' => [], 'mods' => [],
        ];

        // Mirror the navbar JS guard: too-short terms do no work.
        if (mb_strlen($term) < 2) {
            return response()->json($empty);
        }

        $limit = 5;

        $players = FuzzySearch::on(Player::query(), 'name', $term)
            ->having('relevance', '>', 20)->limit($limit)->get()
            ->map(fn (Player $p) => [
                'name' => $p->name,
                'url' => url('tee', urlencode($p->name)),
            ])->values();

        $clans = FuzzySearch::on(Clan::query(), 'name', $term)
            ->having('relevance', '>', 20)->limit($limit)->get()
            ->map(fn (Clan $c) => [
                'name' => $c->name,
                'url' => url('clan', urlencode($c->name)),
            ])->values();

        $servers = FuzzySearch::on(Server::query(), 'name', $term)
            ->having('relevance', '>', 20)->limit($limit)->get()
            ->map(fn (Server $s) => [
                'name' => $s->name,
                'id' => $s->id,
                'url' => url('server', [urlencode($s->id), urlencode($s->name)]),
            ])->values();

        $maps = FuzzySearch::on(Map::query(), 'name', $term)
            ->having('relevance', '>', 20)->limit($limit)->get()
            ->map(fn (Map $m) => [
                'name' => $m->name,
                'url' => url('map', urlencode($m->name)),
            ])->values();

        $mods = FuzzySearch::on(Mod::query(), 'name', $term)
            ->having('relevance', '>', 20)->limit($limit)->get()
            ->map(fn (Mod $m) => [
                'name' => $m->name,
                'url' => url('mod', urlencode($m->name)),
            ])->values();

        return response()->json([
            'players' => $players,
            'clans' => $clans,
            'servers' => $servers,
            'maps' => $maps,
            'mods' => $mods,
        ]);
    }
}
