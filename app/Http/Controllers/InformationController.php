<?php

namespace App\Http\Controllers;

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

    public function editClan()
    {
        return view('down');
    }

    public function editPlayer()
    {
        return view('down');
    }

}
