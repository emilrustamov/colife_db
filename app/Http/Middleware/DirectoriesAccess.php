<?php

namespace App\Http\Middleware;

use App\Http\Controllers\DirectoryController;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class DirectoriesAccess
{
    /**
     * Allow access when the user has directories.view or any directory.{key} permission.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_if($user === null, 403);
        abort_unless(DirectoryController::canAccessDirs($user), 403);

        return $next($request);
    }
}
