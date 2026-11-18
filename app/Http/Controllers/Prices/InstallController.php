<?php

namespace App\Http\Controllers\Prices;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

class InstallController extends Controller
{
    public function __invoke(): View
    {
        return view('prices.install');
    }
}
