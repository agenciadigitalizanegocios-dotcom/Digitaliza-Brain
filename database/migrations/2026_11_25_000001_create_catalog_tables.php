<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Parte 2: módulo Catálogo (produtos) e vitrine pública.
 * - product_types: tipos de produto de cada cliente (Imóvel, Armação...), montados pela Digitaliza.
 * - products: os produtos, com os valores dos campos em JSON.
 * - product_images: galeria de fotos de cada produto.
 * - tenants.vitrine: logo, cor e contato da vitrine.
 * Também copia o tipo "Imóvel" do modelo Imobiliária para os clientes que já usam esse modelo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('key', 40);
            $table->string('name', 60);
            $table->string('plural', 60)->nullable();
            $table->string('icon', 8)->nullable();
            $table->string('intro', 500)->nullable();
            $table->json('fields');
            $table->string('title_field', 40)->nullable();
            $table->string('price_field', 40)->nullable();
            $table->string('price_suffix', 20)->nullable();
            $table->json('card_fields')->nullable();
            $table->json('filters')->nullable();
            $table->json('status_labels')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->boolean('archived')->default(false);
            $table->timestamps();

            $table->unique(['tenant_id', 'key']);
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_type_id')->constrained()->cascadeOnDelete();
            $table->json('data');
            $table->string('status', 20)->default('disponivel');
            $table->boolean('published')->default(true);
            $table->boolean('featured')->default(false);
            $table->string('external_id', 80)->nullable(); // id no sistema de origem (importação)
            $table->timestamps();

            $table->index(['tenant_id', 'product_type_id', 'published', 'status']);
            $table->index(['tenant_id', 'external_id']);
        });

        Schema::create('product_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['product_id', 'position']);
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->json('vitrine')->nullable();
        });

        // Modelo Imobiliária no banco ganha o tipo "Imóvel" (sem subir a versão: não gera pendência).
        $file = json_decode(file_get_contents(database_path('templates/imobiliaria.json')), true);
        $template = DB::table('brain_templates')->where('slug', 'imobiliaria')->first();
        if ($template) {
            $structure = json_decode($template->structure, true);
            if (empty($structure['catalog_types'])) {
                $structure['catalog_types'] = $file['catalog_types'] ?? [];
                DB::table('brain_templates')->where('id', $template->id)
                    ->update(['structure' => json_encode($structure, JSON_UNESCAPED_UNICODE)]);
            }

            // Clientes desse modelo ganham uma cópia do tipo.
            $now = now();
            foreach (DB::table('tenants')->where('template_id', $template->id)->pluck('id') as $tenantId) {
                foreach ($file['catalog_types'] ?? [] as $i => $type) {
                    if (DB::table('product_types')->where('tenant_id', $tenantId)->where('key', $type['key'])->exists()) {
                        continue;
                    }
                    DB::table('product_types')->insert(\App\Catalog\Catalog::typeRow($type, $tenantId, $i + 1) + [
                        'created_at' => $now, 'updated_at' => $now,
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('vitrine');
        });
        Schema::dropIfExists('product_images');
        Schema::dropIfExists('products');
        Schema::dropIfExists('product_types');
    }
};
