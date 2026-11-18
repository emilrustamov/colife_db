<?php

namespace App\Http\Controllers\Prices;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

class ReportController extends Controller
{
    public function __invoke(): View
    {
        return view('prices.report', [
            'currentYear' => (int) now()->format('Y'),
            'contractUrl' => (string) config('services.prices.contract_url'),
            'apiUrl' => '/api',
        ]);
    }
}
