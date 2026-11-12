<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('energy_accounts', function (Blueprint $table) {
            $table->string('player_id', 22)->nullable()->change();
            $table->string('unlinked_name_key', 64)->nullable();
            $table->unique(['guild_id', 'unlinked_name_key']);
        });
    }

    public function down(): void
    {
        // Keep player_id nullable so rolling back does not discard unlinked balances.
        Schema::table('energy_accounts', function (Blueprint $table) {
            $table->dropUnique(['guild_id', 'unlinked_name_key']);
            $table->dropColumn('unlinked_name_key');
        });
    }
};
