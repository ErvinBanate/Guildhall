<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

final class VerifyOrigin
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless($request->header('Origin') === rtrim(config('app.url'), '/'), 403);

        return $next($request);
    }
}
