<?php

namespace App\Http\Controllers;

use App\Models\Clan;
use App\Models\Player;
use App\Models\Server;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Request;

class InformationController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->middleware('auth');
    }

    /**
     * @param Request $request
     * @param $clan_name
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function editClan(Request $request, $clan_name)
    {
        $clan_name = urldecode($clan_name);

        $clan = Clan::where(['name' => $clan_name])->firstOrFail();

        return view('edit.clan')
            ->with('clan', $clan);
    }

    /**
     * @param Request $request
     * @param $player_name
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function editPlayer(Request $request, $player_name)
    {
        $player_name = urldecode($player_name);

        $player = Player::where(['name' => $player_name])->firstOrFail();

        return view('edit.player')
            ->with('player', $player);
    }

    /**
     * @param Request $request
     * @param $server_id
     * @param $server_name
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function editServer(Request $request, $server_id, $server_name)
    {
        $server_id = urldecode($server_id);
        $server_name = urldecode($server_name);

        $server = Server::where(
            [
                'id' => $server_id,
                'name' => $server_name
            ]
        )->firstOrFail();

        return view('edit.server')
            ->with('server', $server);
    }

    /**
     * @param Request $request
     * @param $user
     * @return \Illuminate\Http\RedirectResponse
     */
    protected function authenticated(Request $request, $user)
    {
        return Redirect::back();
    }

}
