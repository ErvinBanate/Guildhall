<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('energy_exemptions', function (Blueprint $table) {
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_bin';
            $table->uuid('id')->primary();
            $table->string('guild_id', 22);
            $table->string('subject_key', 64);
            $table->string('player_name', 64);
            $table->string('role', 20);
            $table->boolean('active');
            $table->unsignedInteger('revision');
            $table->string('note', 300);
            $table->timestamps();
            $table->unique(['guild_id', 'subject_key']);
        });
        Schema::create('energy_exemption_events', function (Blueprint $table) {
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_bin';
            $table->uuid('id')->primary();
            $table->foreignUuid('exemption_id')->constrained('energy_exemptions');
            $table->string('officer_id', 20);
            $table->string('role', 20);
            $table->boolean('active');
            $table->string('note', 300);
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('energy_exemption_events');
        Schema::dropIfExists('energy_exemptions');
    }
};
