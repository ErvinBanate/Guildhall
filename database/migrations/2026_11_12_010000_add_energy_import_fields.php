<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('energy_transactions', function (Blueprint $table) {
            $table->string('source_key', 64)->nullable()->unique();
            $table->string('occurred_at', 40)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('energy_transactions', function (Blueprint $table) {
            $table->dropUnique(['source_key']);
            $table->dropColumn(['source_key', 'occurred_at']);
        });
    }
};
