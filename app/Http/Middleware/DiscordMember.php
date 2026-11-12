<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\GuildServices;
use Closure;
use Illuminate\Http\Request;

final class DiscordMember
{
    public function __construct(private readonly GuildServices $services) {}

    public function handle(Request $request, Closure $next)
    {
        $user = $request->session()->get('discord_user');
        if (!$user) {
            return redirect()->route('login');
        }

        if (!$request->isMethod('GET') || $request->is('officer/*') || time() - $user['checked'] >= 60) {
            $guild = config('guildhall.guild');
            try {
                $response = $this->services->bot()->get("/guilds/{$guild}/members/{$user['id']}");
                $role = $response->status() === 404 ? null : $this->services->role($response->throw()->json());
            } catch (\Illuminate\Http\Client\HttpClientException) {
                abort(503, 'Discord verification is temporarily unavailable.');
            }
            if (!$role) {
                $request->session()->invalidate();
                abort(403, 'A permitted Discord role is required.');
            }
            $user['role'] = $role;
            $user['checked'] = time();
            $request->session()->put('discord_user', $user);
        }

        return $next($request);
    }
}
