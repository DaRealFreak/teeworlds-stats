<?php

namespace App\Http\Controllers;

use App\Models\Clan;
use App\Models\Map;
use App\Models\Mod;
use App\Models\Player;
use App\Models\Server;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Input;
use Illuminate\Support\Str;

class AjaxSearchController extends Controller
{

    /**
     * @param Request $request
     * @return string
     */
    public function searchTee(Request $request)
    {
        $term = Str::lower(Input::get('term'));

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
        $term = Str::lower(Input::get('term'));

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
        $term = Str::lower(Input::get('term'));

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
        $term = Str::lower(Input::get('term'));

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
        $term = Str::lower(Input::get('term'));

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
}
