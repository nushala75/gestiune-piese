<?php

namespace Tests\Feature;

use Database\Seeders\ProduseTestSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ProduseTestSeederTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('firme', function (Blueprint $table): void {
            $table->id();
            $table->string('denumire');
            $table->string('cod_fiscal')->unique();
            $table->timestamps();
        });
        Schema::create('gestiuni', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('firma_id');
            $table->string('cod');
            $table->string('denumire');
            $table->boolean('activa')->default(true);
            $table->timestamps();
            $table->unique(['firma_id', 'cod']);
        });
        Schema::create('categorii', function (Blueprint $table): void {
            $table->id();
            $table->string('denumire')->unique();
            $table->boolean('activa')->default(true);
        });
        Schema::create('unitati_masura', function (Blueprint $table): void {
            $table->id();
            $table->string('cod')->unique();
            $table->string('denumire');
            $table->boolean('activa')->default(true);
        });
        Schema::create('furnizori', function (Blueprint $table): void {
            $table->id();
            $table->string('denumire');
            $table->string('cod_fiscal')->nullable()->unique();
            $table->string('tara', 2)->nullable();
            $table->string('moneda_implicita', 3)->nullable();
            $table->json('configuratie_parser')->nullable();
            $table->boolean('activ')->default(true);
            $table->timestamps();
        });
        Schema::create('produse', function (Blueprint $table): void {
            $table->id();
            $table->char('cod_fgo', 8)->nullable()->unique();
            $table->string('cod_produs', 64)->unique();
            $table->string('denumire_engleza');
            $table->text('descriere_romana')->nullable();
            $table->unsignedBigInteger('categorie_id');
            $table->unsignedBigInteger('unitate_masura_id');
            $table->string('marca')->nullable();
            $table->decimal('stoc_minim', 18, 3)->default(1);
            $table->decimal('pret_vanzare_fara_tva', 18, 4)->nullable();
            $table->decimal('pret_vanzare_cu_tva', 18, 2)->nullable();
            $table->decimal('cota_tva', 5, 2)->default(21);
            $table->decimal('greutate_kg', 10, 3)->nullable();
            $table->boolean('voluminos')->default(false);
            $table->decimal('lungime_cm', 10, 2)->nullable();
            $table->decimal('latime_cm', 10, 2)->nullable();
            $table->decimal('inaltime_cm', 10, 2)->nullable();
            $table->boolean('activ')->default(true);
            $table->string('sursa', 32);
            $table->timestamps();
        });
        Schema::create('produse_furnizori', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('produs_id');
            $table->unsignedBigInteger('furnizor_id');
            $table->string('cod_furnizor');
            $table->string('denumire_furnizor')->nullable();
            $table->decimal('pret_achizitie_ultim', 18, 4)->nullable();
            $table->string('moneda', 3)->nullable();
            $table->date('data_ultimei_achizitii')->nullable();
            $table->boolean('confirmata_manual')->default(false);
            $table->timestamps();
            $table->unique(['furnizor_id', 'cod_furnizor']);
        });
        Schema::create('solduri_stoc', function (Blueprint $table): void {
            $table->unsignedBigInteger('gestiune_id');
            $table->unsignedBigInteger('produs_id');
            $table->decimal('cantitate_fizica', 18, 3)->default(0);
            $table->decimal('cantitate_rezervata', 18, 3)->default(0);
            $table->timestamp('updated_at')->nullable();
            $table->primary(['gestiune_id', 'produs_id']);
        });
    }

    protected function tearDown(): void
    {
        foreach (['solduri_stoc', 'produse_furnizori', 'produse', 'furnizori', 'unitati_masura', 'categorii', 'gestiuni', 'firme'] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    public function test_seeder_populates_seven_products_idempotently(): void
    {
        $seeder = app(ProduseTestSeeder::class);
        $seeder->run();
        $seeder->run();

        $this->assertDatabaseCount('produse', 7);
        $this->assertDatabaseCount('produse_furnizori', 7);
        $this->assertDatabaseCount('solduri_stoc', 7);
        $this->assertSame(7, DB::table('produse')->distinct()->count('cod_fgo'));
        $this->assertDatabaseHas('produse_furnizori', [
            'cod_furnizor' => '11102-1G87-004',
            'pret_achizitie_ultim' => 2.0633,
            'moneda' => 'EUR',
        ]);
    }

    public function test_products_page_displays_seeded_data(): void
    {
        app(ProduseTestSeeder::class)->run();

        $this->get('/produse')
            ->assertOk()
            ->assertSee('00445402')
            ->assertSee('11102-1G87-004 RUB BUSH ENG HANGER')
            ->assertSee('2,0633');
    }
}
