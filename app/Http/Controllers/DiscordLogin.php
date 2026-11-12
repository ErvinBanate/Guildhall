<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\GuildServices;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

final class DiscordLogin
{
    public function start(Request $request)
    {
        abort_unless(config('guildhall.client_id') && config('guildhall.client_secret') && config('guildhall.guild'), 503);
        $state = bin2hex(random_bytes(32));
        $verifier = bin2hex(random_bytes(48));
        $request->session()->put('oauth', compact('state', 'verifier') + ['expires' => time() + 600]);

        return redirect()->away('https://discord.com/oauth2/authorize?'.http_build_query([
            'client_id' => config('guildhall.client_id'),
            'redirect_uri' => rtrim(config('app.url'), '/').'/api/auth/callback',
            'response_type' => 'code',
            'scope' => 'identify guilds.members.read',
            'state' => $state,
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
        ]));
    }

    public function callback(Request $request, GuildServices $services)
    {
        $oauth = $request->session()->pull('oauth');
        $state = $request->query('state');
        $code = $request->query('code');
        abort_unless($oauth && $oauth['expires'] >= time() && is_string($state)
            && hash_equals($oauth['state'], $state) && is_string($code) && $code !== '', 400, 'Start a fresh Discord sign-in.');

        try {
            $token = Http::asForm()->timeout(8)->withoutRedirecting()->post('https://discord.com/api/v10/oauth2/token', [
                'client_id' => config('guildhall.client_id'),
                'client_secret' => config('guildhall.client_secret'),
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => rtrim(config('app.url'), '/').'/api/auth/callback',
                'code_verifier' => $oauth['verifier'],
            ])->throw()->json('access_token');
            abort_unless(is_string($token) && $token !== '', 503);
            $user = $services->discord()->withToken($token)->get('/users/@me')->throw()->json();
            $guild = config('guildhall.guild');
            $response = $services->discord()->withToken($token)->get("/users/@me/guilds/{$guild}/member");
            abort_if(in_array($response->status(), [401, 403, 404], true), 403);
            $role = $services->role($response->throw()->json());
        } catch (\Illuminate\Http\Client\HttpClientException) {
            abort(503, 'Discord verification is temporarily unavailable.');
        }

        abort_unless($role && is_string($user['id'] ?? null) && preg_match('/^\d{17,20}$/D', $user['id'])
            && is_string($user['username'] ?? null), 403, 'A permitted Discord role is required.');
        $request->session()->regenerate(true);
        $request->session()->put('discord_user', [
            'id' => $user['id'],
            'name' => $user['username'],
            'role' => $role,
            'checked' => time(),
        ]);

        return redirect()->route('guildhall');
    }

    public function logout(Request $request)
    {
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
