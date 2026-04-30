<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

class AdminAccessController extends Controller
{
    /**
     * Render the access management UI (users and roles).
     */
    public function index(): Response
    {
        return Inertia::render('Admin/Access');
    }
}
