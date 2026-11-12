<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('verifications', function (Blueprint $table) {
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_bin';
            $table->string('discord_user_id', 20)->primary();
            $table->string('player_id', 22);
            $table->string('player_name', 64);
            $table->string('channel_id', 20)->nullable()->unique();
            $table->string('code', 10);
            $table->string('status', 16);
            $table->string('approved_player_id', 22)->nullable()->unique();
            $table->string('reviewer_id', 20)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('verifications');
    }
};
