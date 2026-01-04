<?php

namespace ManagerCore\Http\Controllers;

use Seat\Web\Http\Controllers\Controller;

class HelpController extends Controller
{
    /**
     * Display help and documentation page
     *
     * @return \Illuminate\View\View
     */
    public function index()
    {
        return view('manager-core::help.index');
    }
}
