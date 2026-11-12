<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('loot_logs', function (Blueprint $table) {
            $table->string('confirmed_by', 20)->nullable();
            $table->timestamp('confirmed_at')->nullable();
        });
    }
    public function down(): void
    {
        Schema::table('loot_logs', fn (Blueprint $table) => $table->dropColumn(['confirmed_by', 'confirmed_at']));
    }
};
