<?php

namespace App\Http\Controllers;

class MainController extends Controller
{
    /**
     * Show the application dashboard.
     *
     * @return \Illuminate\Http\Response
     */
    public function general()
    {
        return view('main');
    }
}
