<?php

return [
    'client_id' => env('DISCORD_CLIENT_ID'),
    'client_secret' => env('DISCORD_CLIENT_SECRET'),
    'guild' => env('DISCORD_GUILD_ID'),
    'bot' => env('DISCORD_BOT_TOKEN'),
    'public_key' => env('DISCORD_PUBLIC_KEY'),
    'category' => env('DISCORD_TICKET_CATEGORY_ID'),
    'roles' => [
        'admin' => env('DISCORD_ADMIN_ROLE_IDS', ''),
        'officer' => env('DISCORD_OFFICER_ROLE_IDS', ''),
        'member' => env('DISCORD_MEMBER_ROLE_IDS', ''),
    ],
    'albion_guild' => env('ALBION_GUILD_ID', '2up23a2ER3y_RUGEpAtNHQ'),
];
