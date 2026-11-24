<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Campos únicos do cérebro (negócio, personalidade, atendimento).
        Schema::create('brain_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->unique()->constrained()->cascadeOnDelete();
            $table->json('data')->nullable();
            $table->unsignedInteger('version')->default(0);
            $table->timestamps();
        });

        // Listas do cérebro (perguntas, regras, FAQ, serviços, horários, políticas, passos).
        Schema::create('brain_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('section', 40);
            $table->json('data');
            $table->unsignedInteger('position')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'section', 'position']);
        });

        // Histórico: uma foto completa a cada alteração.
        Schema::create('brain_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('description');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->json('snapshot');
            $table->longText('prompt');
            $table->timestamps();

            $table->unique(['tenant_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brain_versions');
        Schema::dropIfExists('brain_items');
        Schema::dropIfExists('brain_settings');
    }
};
