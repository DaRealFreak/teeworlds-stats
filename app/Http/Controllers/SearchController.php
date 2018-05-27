<?php

namespace App\Http\Controllers;

use App\Models\Clan;
use App\Models\Map;
use App\Models\Mod;
use App\Models\Player;
use App\Models\Server;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use TomLingham\Searchy\Facades\Searchy;

class SearchController extends Controller
{

    /**
     * Show the application dashboard.
     *
     * @return \Illuminate\Http\Response
     */
    public function main()
    {
        return view('search');
    }

    /**
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function searchTee(Request $request)
    {
        $name = $request->input('tee_name');
        if (!$name) {
            return Redirect::back()->withErrors([
                'tee' => 'The name field is required'
            ]);
        } else {
            return Redirect::to(url('tee', urlencode($name)));
        }
    }

    /**
     * @param Request $request
     * @param $tee_name
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\Http\RedirectResponse|\Illuminate\View\View
     */
    public function searchTeeByName(Request $request, $tee_name)
    {
        $tee_name = urldecode($tee_name);

        if (!$player = (new Player)->where('name', $tee_name)->first()) {
            $suggestedPlayers = Player::hydrate(
                Searchy::search('players')
                    ->fields('name')
                    ->query($tee_name)->getQuery()
                    ->having('relevance', '>', 20)
                    ->limit(10)
                    ->get()->toArray()
            );

            return Redirect::to("search")
                ->withErrors(['tee' => 'This player does not exist'])
                ->with('teeSuggestions', $suggestedPlayers);
        }

        return view('detail.player')->with('player', $player);
    }

    /**
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function searchClan(Request $request)
    {
        $name = $request->input('clan_name');
        if (!$name) {
            return Redirect::back()->withErrors([
                'clan' => 'The name field is required'
            ]);
        } else {
            return Redirect::to(url('clan', urlencode($name)));
        }
    }

    /**
     * @param Request $request
     * @param $clan_name
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\Http\RedirectResponse|\Illuminate\View\View
     */
    public function searchClanByName(Request $request, $clan_name)
    {
        $clan_name = urldecode($clan_name);

        if (!$clan = (new Clan)->where('name', $clan_name)->first()) {
            $clanSuggestions = Clan::hydrate(
                Searchy::search('clans')
                    ->fields('name')
                    ->query($clan_name)->getQuery()
                    ->having('relevance', '>', 20)
                    ->limit(10)
                    ->get()->toArray()
            );

            return Redirect::to("search")
                ->withErrors(['clan' => 'This clan does not exist'])
                ->with('clanSuggestions', $clanSuggestions);
        }

        return view('detail.clan')->with('clan', $clan);
    }

    /**
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function searchServer(Request $request)
    {
        $name = $request->input('server_name');
        if (!$name) {
            return Redirect::back()->withErrors([
                'server' => 'The name field is required'
            ]);
        } else {
            return Redirect::to(url('server', urlencode($name)));
        }
    }

    /**
     * search server by name, redirect on direct match to detail page, else suggest close matches
     *
     * @param Request $request
     * @param $server_name
     * @return \Illuminate\Http\RedirectResponse
     */
    public function searchServerByName(Request $request, $server_name)
    {
        $server_name = urldecode($server_name);

        if (!$server = Server::where('name', $server_name)->first()) {
            $serverSuggestions = Server::hydrate(
                Searchy::search('servers')
                    ->fields('name')
                    ->query($server_name)->getQuery()
                    ->having('relevance', '>', 20)
                    ->limit(10)
                    ->get()->toArray()
            );

            return Redirect::to("search")
                ->withErrors(['server' => 'This server does not exist'])
                ->with('serverSuggestions', $serverSuggestions);
        }
        return Redirect::to(url('server', [urlencode($server->id), urlencode($server->name)]));
    }

    /**
     * search server by passed id or redirect to search term if id does not exist
     *
     * @param Request $request
     * @param $server_id
     * @param $server_name
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\Http\RedirectResponse|\Illuminate\View\View
     */
    public function searchServerByIdAndName(Request $request, $server_id, $server_name)
    {
        $server_name = urldecode($server_name);
        $server_id = urldecode($server_id);

        if (!$server = Server::where(['id' => $server_id])->first()) {
            $serverSuggestions = Server::hydrate(
                Searchy::search('servers')
                    ->fields('name')
                    ->query($server_name)->getQuery()
                    ->having('relevance', '>', 20)
                    ->limit(10)
                    ->get()->toArray()
            );

            return Redirect::to("search")
                ->withErrors(['server' => 'This server does not exist'])
                ->with('serverSuggestions', $serverSuggestions);
        }
        return view('detail.server')->with('server', $server);
    }

    /**
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function searchMod(Request $request)
    {
        $name = $request->input('mod_name');
        if (!$name) {
            return Redirect::back()->withErrors([
                'mod' => 'The name field is required'
            ]);
        } else {
            return Redirect::to(url('mod', urlencode($name)));
        }
    }

    /**
     * @param Request $request
     * @param $mod_name
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\Http\RedirectResponse|\Illuminate\View\View
     */
    public function searchModByName(Request $request, $mod_name)
    {
        $mod_name = urldecode($mod_name);

        if (!$mod = (new Mod)->where('name', $mod_name)->first()) {
            $suggestedPlayers = Player::hydrate(
                Searchy::search('mods')
                    ->fields('name')
                    ->query($mod_name)->getQuery()
                    ->having('relevance', '>', 20)
                    ->limit(10)
                    ->get()->toArray()
            );

            return Redirect::to("search")
                ->withErrors(['mod' => 'This mod does not exist'])
                ->with('modSuggestions', $suggestedPlayers);
        }

        return view('detail.mod')->with('mod', $mod);
    }

    /**
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function searchMap(Request $request)
    {
        $name = $request->input('map_name');
        if (!$name) {
            return Redirect::back()->withErrors([
                'map' => 'The name field is required'
            ]);
        } else {
            return Redirect::to(url('map', urlencode($name)));
        }
    }

    /**
     * @param Request $request
     * @param $map_name
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\Http\RedirectResponse|\Illuminate\View\View
     */
    public function searchMapByName(Request $request, $map_name)
    {
        $map_name = urldecode($map_name);

        if (!$map = (new Map)->where('name', $map_name)->first()) {
            $suggestedPlayers = Player::hydrate(
                Searchy::search('maps')
                    ->fields('name')
                    ->query($map_name)->getQuery()
                    ->having('relevance', '>', 20)
                    ->limit(10)
                    ->get()->toArray()
            );

            return Redirect::to("search")
                ->withErrors(['map' => 'This map does not exist'])
                ->with('mapSuggestions', $suggestedPlayers);
        }

        return view('detail.map')->with('map', $map);
    }
}
