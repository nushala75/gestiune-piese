<?php

namespace Tests\Feature;

use App\Services\BnrExchangeRateService;
use App\Services\StockRegisterXlsxParser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use Tests\TestCase;

class StockUpdateImportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('firme', function (Blueprint $table): void {
            $table->id();
            $table->string('cod_fiscal');
        });
        Schema::create('gestiuni', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('firma_id');
            $table->string('cod');
        });
        Schema::create('produse', function (Blueprint $table): void {
            $table->id();
            $table->string('cod_produs');
            $table->string('denumire_engleza');
            $table->bigInteger('stoc_minim')->default(0);
            $table->bigInteger('cantitate_de_comandat')->default(0);
            $table->unsignedBigInteger('furnizor_comanda_id')->nullable();
            $table->boolean('furnizor_comanda_manual')->default(false);
            $table->decimal('pret_vanzare_fara_tva', 18, 4)->nullable();
            $table->decimal('pret_vanzare_cu_tva', 18, 2)->nullable();
            $table->timestamps();
        });
        Schema::create('produse_furnizori', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('produs_id');
            $table->unsignedBigInteger('furnizor_id')->nullable();
            $table->date('data_ultimei_achizitii')->nullable();
        });
        Schema::create('solduri_stoc', function (Blueprint $table): void {
            $table->unsignedBigInteger('gestiune_id');
            $table->unsignedBigInteger('produs_id');
            $table->bigInteger('cantitate_fizica')->default(0);
            $table->bigInteger('cantitate_rezervata')->default(0);
            $table->timestamp('updated_at')->nullable();
            $table->primary(['gestiune_id', 'produs_id']);
        });
        Schema::create('miscari_stoc', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('gestiune_id');
            $table->unsignedBigInteger('produs_id');
            $table->string('tip');
            $table->bigInteger('cantitate');
            $table->decimal('cost_unitar', 18, 4)->nullable();
            $table->unsignedBigInteger('receptie_linie_id')->nullable();
            $table->string('referinta_tip')->nullable();
            $table->unsignedBigInteger('referinta_id')->nullable();
            $table->string('explicatie');
            $table->timestamp('created_at')->nullable();
        });
        Schema::create('jurnal_audit', function (Blueprint $table): void {
            $table->id();
            $table->string('actor_tip');
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actiune');
            $table->string('entitate_tip');
            $table->unsignedBigInteger('entitate_id')->nullable();
            $table->json('date_inainte')->nullable();
            $table->json('date_dupa')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        $companyId = DB::table('firme')->insertGetId(['cod_fiscal' => 'RO20548513']);
        $warehouseId = DB::table('gestiuni')->insertGetId(['firma_id' => $companyId, 'cod' => 'FIRMA']);
        foreach ([
            ['code' => 'ABC-1', 'name' => 'Produs A', 'stock' => 1, 'price' => 40],
            ['code' => 'ABC-2', 'name' => 'Produs B', 'stock' => 0, 'price' => 90],
        ] as $row) {
            $productId = DB::table('produse')->insertGetId([
                'cod_produs' => $row['code'],
                'denumire_engleza' => $row['name'],
                'pret_vanzare_fara_tva' => 1,
                'pret_vanzare_cu_tva' => $row['price'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('solduri_stoc')->insert([
                'gestiune_id' => $warehouseId,
                'produs_id' => $productId,
                'cantitate_fizica' => $row['stock'],
                'cantitate_rezervata' => 0,
            ]);
        }
    }

    protected function tearDown(): void
    {
        foreach (['jurnal_audit', 'miscari_stoc', 'solduri_stoc', 'produse_furnizori', 'produse', 'gestiuni', 'firme'] as $table) {
            Schema::dropIfExists($table);
        }
        parent::tearDown();
    }

    public function test_preview_and_confirmed_apply_update_stock_and_final_price(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('sursa/registru-produse-kymco.xlsx', 'continut-test');
        config(['stock-register.path' => Storage::disk('local')->path('sursa/registru-produse-kymco.xlsx')]);
        $rows = [
            ['row' => 2, 'code' => 'ABC-1', 'stock' => 4, 'price_with_vat' => '10'],
            ['row' => 3, 'code' => 'ABC-2', 'stock' => -1, 'price_with_vat' => '20'],
            ['row' => 4, 'code' => 'MISSING', 'stock' => 3, 'price_with_vat' => '30'],
        ];
        $this->mock(StockRegisterXlsxParser::class, function (MockInterface $mock) use ($rows): void {
            $mock->shouldReceive('parse')->twice()->andReturn(['sheet' => 'Produse', 'rows' => $rows]);
        });
        $this->mock(BnrExchangeRateService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('latestEuroRate')->once()->andReturn([
                'currency' => 'EUR',
                'value' => '5.0000',
                'published_on' => '2026-09-04',
                'fetched_at' => '2026-09-04T12:00:00+03:00',
                'source_url' => BnrExchangeRateService::SOURCE_URL,
            ]);
        });

        $this->post('/stoc/actualizare/pregatire')
            ->assertRedirect('/stoc/actualizare/previzualizare');
        $token = session('stock_update_import_preview.token');

        $this->get('/stoc/actualizare/previzualizare')
            ->assertOk()
            ->assertSee('Curs BNR EUR: 5,0000 RON')
            ->assertSee('MISSING')
            ->assertSee('50,00')
            ->assertSee('100,00');

        $this->post('/stoc/actualizare/aplicare', [
            'token' => $token,
            'confirmare' => 1,
        ])->assertRedirect('/stoc/actualizare');

        $firstId = DB::table('produse')->where('cod_produs', 'ABC-1')->value('id');
        $secondId = DB::table('produse')->where('cod_produs', 'ABC-2')->value('id');
        $this->assertDatabaseHas('solduri_stoc', ['produs_id' => $firstId, 'cantitate_fizica' => 4]);
        $this->assertDatabaseHas('solduri_stoc', ['produs_id' => $secondId, 'cantitate_fizica' => -1]);
        $this->assertDatabaseHas('produse', ['id' => $firstId, 'pret_vanzare_cu_tva' => 50.00]);
        $this->assertDatabaseHas('produse', ['id' => $secondId, 'pret_vanzare_cu_tva' => 100.00]);
        $this->assertDatabaseCount('miscari_stoc', 2);
        $this->assertDatabaseHas('jurnal_audit', ['actiune' => 'actualizare_stoc_xlsx']);
    }

    public function test_manual_upload_remains_available_as_an_alternative(): void
    {
        Storage::fake('local');
        $rows = [
            ['row' => 2, 'code' => 'ABC-1', 'stock' => 4, 'price_with_vat' => '10'],
        ];
        $this->mock(StockRegisterXlsxParser::class, function (MockInterface $mock) use ($rows): void {
            $mock->shouldReceive('parse')->once()->andReturn(['sheet' => 'Produse', 'rows' => $rows]);
        });
        $this->mock(BnrExchangeRateService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('latestEuroRate')->once()->andReturn([
                'currency' => 'EUR',
                'value' => '5.0000',
                'published_on' => '2026-09-04',
                'fetched_at' => '2026-09-04T12:00:00+03:00',
                'source_url' => BnrExchangeRateService::SOURCE_URL,
            ]);
        });

        $this->get('/stoc/actualizare')
            ->assertOk()
            ->assertSee('Actualizare stoc și prețuri')
            ->assertSee('Încărcare manuală')
            ->assertSee('name="registru"', false);

        $this->post('/stoc/actualizare/incarcare', [
            'registru' => UploadedFile::fake()->create(
                'registru-manual.xlsx',
                20,
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ),
        ])->assertRedirect('/stoc/actualizare/previzualizare');

        $temporaryPath = session('stock_update_import_preview.temporary_path');
        $this->assertNotEmpty($temporaryPath);
        Storage::disk('local')->assertExists($temporaryPath);
    }
}
