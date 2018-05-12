<?php

namespace App\Http\Controllers;

use App\Models\Clan;
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

        return view('player')->with('player', $player);
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

        return view('clan')->with('clan', $clan);
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
     * @param Request $request
     * @param $server_name
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\Http\RedirectResponse|\Illuminate\View\View
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
        return view('server')->with('server', $server);
    }
}
