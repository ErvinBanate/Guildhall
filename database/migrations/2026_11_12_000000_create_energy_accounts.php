<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('energy_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('guild_id', 22);
            $table->string('player_id', 22);
            $table->string('player_name', 64);
            $table->bigInteger('balance');
            $table->unsignedBigInteger('deposits')->default(0);
            $table->boolean('qualified_before')->default(false);
            $table->boolean('permission_granted')->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
            $table->unique(['guild_id', 'player_id']);
        });
        Schema::create('energy_transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('account_id')->constrained('energy_accounts');
            $table->string('kind', 24);
            $table->bigInteger('amount');
            $table->boolean('qualified_before')->default(false);
            $table->string('note', 300);
            $table->string('officer_id', 20);
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('energy_transactions');
        Schema::dropIfExists('energy_accounts');
    }
};
