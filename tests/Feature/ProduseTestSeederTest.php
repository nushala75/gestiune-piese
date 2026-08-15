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
            $table->bigInteger('stoc_minim')->default(1);
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
            $table->bigInteger('cantitate_fizica')->default(0);
            $table->bigInteger('cantitate_rezervata')->default(0);
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
            ->assertSee('2.0633')
            ->assertSee('name="stoc"', false)
            ->assertSee('name="pret_vanzare_cu_tva"', false)
            ->assertDontSee('name="denumire_engleza"', false)
            ->assertDontSee('name="descriere_romana"', false)
            ->assertDontSee('name="pret_intrare"', false)
            ->assertDontSee('Vânzare fără TVA');
    }

    public function test_product_can_be_edited_directly_and_net_price_is_recalculated(): void
    {
        app(ProduseTestSeeder::class)->run();
        $produsId = DB::table('produse')->where('cod_fgo', '00445402')->value('id');

        $this->patch("/produse/{$produsId}/editare-rapida", [
            'stoc' => 17,
            'pret_vanzare_cu_tva' => '121.00',
        ])->assertRedirect();

        $this->assertDatabaseHas('produse', [
            'id' => $produsId,
            'denumire_engleza' => 'RUB BUSH ENG HANGER',
            'descriere_romana' => 'Bucsa motor',
            'pret_vanzare_fara_tva' => 100.0000,
            'pret_vanzare_cu_tva' => 121.00,
        ]);
        $this->assertDatabaseHas('produse_furnizori', [
            'produs_id' => $produsId,
            'pret_achizitie_ultim' => 2.0633,
        ]);
        $this->assertDatabaseHas('solduri_stoc', [
            'produs_id' => $produsId,
            'cantitate_fizica' => 17,
        ]);
    }

    public function test_details_page_excludes_fgo_code_and_updates_remaining_fields(): void
    {
        app(ProduseTestSeeder::class)->run();
        $produs = DB::table('produse')->where('cod_fgo', '00445402')->first();
        $categorie = DB::table('categorii')->where('denumire', 'Pe comanda')->first();
        $unitate = DB::table('unitati_masura')->where('cod', 'SET')->first();

        $this->get("/produse/{$produs->id}/detalii")
            ->assertOk()
            ->assertSee('Edit detalii')
            ->assertDontSee('name="cod_fgo"', false);

        $this->patch("/produse/{$produs->id}/detalii", [
            'cod_produs' => 'special/2026 test',
            'denumire_engleza' => 'rub bush updated',
            'descriere_romana' => 'Bucșă actualizată',
            'categorie_id' => $categorie->id,
            'unitate_masura_id' => $unitate->id,
            'marca' => 'kymco test',
            'stoc_minim' => 3,
            'stoc' => 19,
            'pret_intrare' => '2.1234',
            'pret_vanzare_cu_tva' => '121.00',
            'cota_tva' => '21.00',
            'greutate_kg' => '1.250',
            'voluminos' => 1,
            'lungime_cm' => '10.50',
            'latime_cm' => '20.25',
            'inaltime_cm' => '30.75',
            'activ' => 1,
        ])->assertRedirect("/produse/{$produs->id}/detalii");

        $this->assertDatabaseHas('produse', [
            'id' => $produs->id,
            'cod_produs' => 'SPECIAL/2026 TEST',
            'denumire_engleza' => 'RUB BUSH UPDATED',
            'descriere_romana' => 'Bucșă actualizată',
            'categorie_id' => $categorie->id,
            'unitate_masura_id' => $unitate->id,
            'marca' => 'KYMCO TEST',
            'stoc_minim' => 3,
            'pret_vanzare_fara_tva' => 100.0000,
            'pret_vanzare_cu_tva' => 121.00,
            'voluminos' => 1,
        ]);
        $this->assertDatabaseHas('produse_furnizori', [
            'produs_id' => $produs->id,
            'pret_achizitie_ultim' => 2.1234,
        ]);
        $this->assertDatabaseHas('solduri_stoc', [
            'produs_id' => $produs->id,
            'cantitate_fizica' => 19,
        ]);
    }

    public function test_product_without_transaction_history_can_be_deleted_with_its_local_links(): void
    {
        app(ProduseTestSeeder::class)->run();
        $product = DB::table('produse')->where('cod_produs', '11102-1G87-004')->first();

        $this->get('/produse')
            ->assertOk()
            ->assertSee('Șterge')
            ->assertSee("action=\"http://localhost/produse/{$product->id}\"", false)
            ->assertSee("Ștergi definitiv produsul {$product->cod_produs}");

        $this->delete("/produse/{$product->id}")
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('produse', ['id' => $product->id]);
        $this->assertDatabaseMissing('produse_furnizori', ['produs_id' => $product->id]);
        $this->assertDatabaseMissing('solduri_stoc', ['produs_id' => $product->id]);
    }
}
