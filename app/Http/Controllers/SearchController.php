<?php

namespace App\Http\Controllers;

use App\Models\Player;
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
            return Redirect::to('tee/' . $name);
        }
    }

    /**
     * @param Request $request
     * @param $tee_name
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\Http\RedirectResponse|\Illuminate\View\View
     */
    public function searchTeeByName(Request $request, $tee_name)
    {
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
            return Redirect::to('clan/' . $name);
        }
    }

    /**
     * @param Request $request
     * @param $tee_name
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function searchClanByName(Request $request, $tee_name)
    {
        dd($tee_name);
        return view('down');
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
            return Redirect::to('server/' . $name);
        }
    }

    /**
     * @param Request $request
     * @param $tee_name
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function searchServerByName(Request $request, $tee_name)
    {
        dd($tee_name);
        return view('down');
    }
}
