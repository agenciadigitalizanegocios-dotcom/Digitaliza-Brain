<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Parte 1: abas e campos por cliente.
 * Cria os modelos de nicho e guarda a estrutura de cada cliente ao lado do conteúdo.
 * NÃO mexe em brain_settings.data nem em brain_items: o conteúdo continua igual.
 * Roda sozinha no deploy (docker/start.sh), então é idempotente.
 */
return new class extends Migration
{
    private const TEMPLATES = [
        'imobiliaria' => ['Imobiliária', 'Aluguel e venda de imóveis: perguntas de qualificação, garantias, visitas.'],
        'escola-tecnica' => ['Escola técnica', 'Cursos, unidades, matrícula e regras de não prometer emprego nem renda.'],
        'em-branco' => ['Em branco', 'Só o núcleo (negócio, personalidade, objetivo, regras e FAQ), para começar um nicho novo.'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('brain_templates')) {
            Schema::create('brain_templates', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('slug')->unique();
                $table->string('description')->nullable();
                $table->unsignedInteger('version')->default(1);
                $table->json('structure');
                $table->timestamps();
            });
        }

        if (! Schema::hasColumn('brain_settings', 'structure')) {
            Schema::table('brain_settings', function (Blueprint $table) {
                $table->json('structure')->nullable();
                $table->json('template_base')->nullable();
            });
        }

        if (! Schema::hasColumn('tenants', 'template_id')) {
            Schema::table('tenants', function (Blueprint $table) {
                $table->foreignId('template_id')->nullable()->constrained('brain_templates')->nullOnDelete();
                $table->unsignedInteger('template_version')->nullable();
                $table->json('modules')->nullable();
            });
        }

        // Os três modelos (só se ainda não existirem).
        $now = now();
        foreach (self::TEMPLATES as $slug => [$name, $description]) {
            if (DB::table('brain_templates')->where('slug', $slug)->exists()) {
                continue;
            }
            $json = file_get_contents(database_path("templates/{$slug}.json"));
            DB::table('brain_templates')->insert([
                'name' => $name,
                'slug' => $slug,
                'description' => $description,
                'version' => 1,
                'structure' => $json,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // Liga todos os clientes que já existem à Imobiliária (o comportamento de antes).
        $imob = DB::table('brain_templates')->where('slug', 'imobiliaria')->first();
        $structure = $imob->structure;

        foreach (DB::table('tenants')->get() as $tenant) {
            $settings = DB::table('brain_settings')->where('tenant_id', $tenant->id)->first();

            if (! $settings) {
                // Cliente criado e nunca aberto: cria a linha igual à que o BrainSetting::current() criaria.
                DB::table('brain_settings')->insert([
                    'tenant_id' => $tenant->id,
                    'data' => json_encode([]),
                    'version' => 0,
                    'structure' => $structure,
                    'template_base' => $structure,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } elseif ($settings->structure === null) {
                DB::table('brain_settings')->where('id', $settings->id)->update([
                    'structure' => $structure,
                    'template_base' => $structure,
                ]);
            }

            if ($tenant->template_id === null) {
                DB::table('tenants')->where('id', $tenant->id)->update([
                    'template_id' => $imob->id,
                    'template_version' => 1,
                    'modules' => json_encode(['catalogo' => false, 'agenda' => false]),
                ]);
            }
        }

        Log::info('Parte 1: estrutura criada e clientes ligados ao modelo Imobiliária.');
    }

    public function down(): void
    {
        if (Schema::hasColumn('tenants', 'template_id')) {
            Schema::table('tenants', function (Blueprint $table) {
                $table->dropConstrainedForeignId('template_id');
                $table->dropColumn(['template_version', 'modules']);
            });
        }

        if (Schema::hasColumn('brain_settings', 'structure')) {
            Schema::table('brain_settings', function (Blueprint $table) {
                $table->dropColumn(['structure', 'template_base']);
            });
        }

        Schema::dropIfExists('brain_templates');
    }
};
