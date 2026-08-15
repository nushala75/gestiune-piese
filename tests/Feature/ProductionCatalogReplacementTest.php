<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ProductionCatalogReplacementTest extends TestCase
{
    /** @var array<int, string> */
    private array $existingBackups = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->existingBackups = File::glob(storage_path('app/private/backups/inainte_catalog_*.jsonl'));
        $this->createSchema();
        $this->seedCurrentTestData();
    }

    protected function tearDown(): void
    {
        $currentBackups = File::glob(storage_path('app/private/backups/inainte_catalog_*.jsonl'));
        foreach (array_diff($currentBackups, $this->existingBackups) as $backup) {
            File::delete($backup);
        }

        parent::tearDown();
    }

    public function test_command_validates_then_replaces_test_data_with_verified_catalog(): void
    {
        $this->assertSame(0, Artisan::call('catalog:replace-production', ['--dry-run' => true]));
        $this->assertDatabaseHas('produse', ['cod_fgo' => '00999999']);

        $this->assertSame(0, Artisan::call('catalog:replace-production', [
            '--confirm' => 'STERGE-TESTELE-SI-IMPORTA-5298',
        ]));

        $this->assertDatabaseCount('produse', 5298);
        $this->assertSame(5298, DB::table('produse')->distinct()->count('cod_fgo'));
        $this->assertDatabaseCount('solduri_stoc', 5298);
        $this->assertDatabaseCount('produse_furnizori', 0);
        $this->assertDatabaseCount('facturi_furnizor', 0);
        $this->assertDatabaseCount('miscari_stoc', 0);
        $this->assertDatabaseMissing('produse', ['cod_fgo' => '00999999']);
        foreach (['00450805', '00450121', '00449844', '00450932'] as $excludedCode) {
            $this->assertDatabaseMissing('produse', ['cod_fgo' => $excludedCode]);
        }
        $this->assertDatabaseHas('produse', [
            'cod_fgo' => '00445402',
            'cod_produs' => '11102-1G87-004',
            'denumire_engleza' => 'RUB BUSH ENG HANGER',
            'pret_vanzare_cu_tva' => 25.00,
            'cota_tva' => 21.00,
        ]);
        $productId = DB::table('produse')->where('cod_fgo', '00445402')->value('id');
        $this->assertDatabaseHas('solduri_stoc', [
            'produs_id' => $productId,
            'cantitate_fizica' => 11,
        ]);
        $this->assertSame(2, DB::table('produse')->where('cod_produs', '11192-LLB1-900')->count());
    }

    private function createSchema(): void
    {
        Schema::create('schema_migrations', function (Blueprint $table): void {
            $table->string('versiune')->primary();
        });
        Schema::create('firme', function (Blueprint $table): void {
            $table->id();
            $table->string('cod_fiscal')->unique();
        });
        Schema::create('gestiuni', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('firma_id');
            $table->string('cod');
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
        Schema::create('produse', function (Blueprint $table): void {
            $table->id();
            $table->char('cod_fgo', 8)->unique();
            $table->string('cod_produs', 64)->index();
            $table->string('denumire_engleza');
            $table->text('descriere_romana')->nullable();
            $table->unsignedBigInteger('categorie_id');
            $table->unsignedBigInteger('unitate_masura_id');
            $table->string('marca')->nullable();
            $table->bigInteger('stoc_minim')->default(1);
            $table->bigInteger('cantitate_de_comandat')->default(0);
            $table->unsignedBigInteger('furnizor_comanda_id')->nullable();
            $table->boolean('furnizor_comanda_manual')->default(false);
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
        Schema::create('solduri_stoc', function (Blueprint $table): void {
            $table->unsignedBigInteger('gestiune_id');
            $table->unsignedBigInteger('produs_id');
            $table->bigInteger('cantitate_fizica')->default(0);
            $table->bigInteger('cantitate_rezervata')->default(0);
            $table->timestamp('updated_at')->nullable();
            $table->primary(['gestiune_id', 'produs_id']);
        });
        Schema::create('produse_furnizori', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('produs_id');
        });

        foreach ([
            'jurnal_audit', 'exporturi_fgo_stoc_linii', 'exporturi_fgo_stoc',
            'exporturi_saga', 'miscari_stoc', 'receptii_linii', 'receptii',
            'facturi_furnizor_linii', 'facturi_furnizor', 'importuri_fisiere',
        ] as $tableName) {
            Schema::create($tableName, function (Blueprint $table): void {
                $table->id();
                $table->string('valoare')->nullable();
            });
        }
    }

    private function seedCurrentTestData(): void
    {
        foreach ([
            '001_initial_schema', '002_extindere_interval_cod_fgo',
            '003_pret_achizitie_4_zecimale', '004_cantitati_intregi',
            '005_tip_document_storno', '006_necesar_aprovizionare',
            '007_furnizori_si_linii_cost', '008_cod_produs_neunic',
        ] as $version) {
            DB::table('schema_migrations')->insert(['versiune' => $version]);
        }
        $firmId = DB::table('firme')->insertGetId(['cod_fiscal' => 'RO20548513']);
        $managementId = DB::table('gestiuni')->insertGetId(['firma_id' => $firmId, 'cod' => 'FIRMA']);
        $categoryId = DB::table('categorii')->insertGetId(['denumire' => 'Pe comanda', 'activa' => true]);
        $unitId = DB::table('unitati_masura')->insertGetId(['cod' => 'BUC', 'denumire' => 'Bucata', 'activa' => true]);
        $productId = DB::table('produse')->insertGetId([
            'cod_fgo' => '00999999',
            'cod_produs' => 'TEST',
            'denumire_engleza' => 'TEST PRODUCT',
            'categorie_id' => $categoryId,
            'unitate_masura_id' => $unitId,
            'stoc_minim' => 1,
            'cantitate_de_comandat' => 0,
            'furnizor_comanda_manual' => false,
            'cota_tva' => 21,
            'voluminos' => false,
            'activ' => true,
            'sursa' => 'test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('produse_furnizori')->insert(['produs_id' => $productId]);
        DB::table('solduri_stoc')->insert([
            'gestiune_id' => $managementId,
            'produs_id' => $productId,
            'cantitate_fizica' => 1,
            'cantitate_rezervata' => 0,
            'updated_at' => now(),
        ]);
        foreach ([
            'jurnal_audit', 'exporturi_fgo_stoc_linii', 'exporturi_fgo_stoc',
            'exporturi_saga', 'miscari_stoc', 'receptii_linii', 'receptii',
            'facturi_furnizor_linii', 'facturi_furnizor', 'importuri_fisiere',
        ] as $tableName) {
            DB::table($tableName)->insert(['valoare' => 'test']);
        }
    }
}
