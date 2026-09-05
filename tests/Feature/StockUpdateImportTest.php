<?php

namespace Tests\Feature;

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
        config(['stock-register.sync_enabled' => false]);
        Schema::create('firme', function (Blueprint $table): void {
            $table->id();
            $table->string('cod_fiscal');
        });
        Schema::create('gestiuni', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('firma_id');
            $table->string('cod');
        });
        Schema::create('categorii', function (Blueprint $table): void {
            $table->id();
            $table->string('denumire');
            $table->boolean('activa')->default(true);
        });
        Schema::create('unitati_masura', function (Blueprint $table): void {
            $table->id();
            $table->string('cod');
            $table->string('denumire');
            $table->boolean('activa')->default(true);
        });
        Schema::create('secvente_cod_fgo', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->unsignedInteger('urmatorul_cod');
            $table->unsignedInteger('cod_minim');
            $table->unsignedInteger('cod_maxim');
            $table->timestamp('updated_at')->nullable();
        });
        Schema::create('produse', function (Blueprint $table): void {
            $table->id();
            $table->char('cod_fgo', 8)->nullable();
            $table->string('cod_produs');
            $table->string('denumire_engleza');
            $table->text('descriere_romana')->nullable();
            $table->unsignedBigInteger('categorie_id')->nullable();
            $table->unsignedBigInteger('unitate_masura_id')->nullable();
            $table->string('marca')->nullable();
            $table->bigInteger('stoc_minim')->default(0);
            $table->bigInteger('cantitate_de_comandat')->default(0);
            $table->unsignedBigInteger('furnizor_comanda_id')->nullable();
            $table->boolean('furnizor_comanda_manual')->default(false);
            $table->decimal('pret_vanzare_fara_tva', 18, 4)->nullable();
            $table->decimal('pret_vanzare_cu_tva', 18, 2)->nullable();
            $table->decimal('cota_tva', 5, 2)->default(21);
            $table->decimal('greutate_kg', 12, 3)->nullable();
            $table->boolean('voluminos')->default(false);
            $table->boolean('activ')->default(true);
            $table->string('sursa')->default('test');
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
        DB::table('categorii')->insert(['id' => 1, 'denumire' => 'Pe comanda', 'activa' => true]);
        DB::table('unitati_masura')->insert(['id' => 1, 'cod' => 'BUC', 'denumire' => 'Bucata', 'activa' => true]);
        DB::table('secvente_cod_fgo')->insert(['id' => 1, 'urmatorul_cod' => 1000000, 'cod_minim' => 1000000, 'cod_maxim' => 1999999]);
        foreach ([
            ['code' => 'ABC-1', 'name' => 'Produs A', 'stock' => 1, 'price' => 40],
            ['code' => 'ABC-2', 'name' => 'Produs B', 'stock' => 0, 'price' => 90],
        ] as $row) {
            $productId = DB::table('produse')->insertGetId([
                'cod_produs' => $row['code'],
                'denumire_engleza' => $row['name'],
                'descriere_romana' => 'Vechi '.$row['name'],
                'cantitate_de_comandat' => 2,
                'pret_vanzare_fara_tva' => 1,
                'pret_vanzare_cu_tva' => $row['price'],
                'greutate_kg' => 1,
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
        foreach (['jurnal_audit', 'miscari_stoc', 'solduri_stoc', 'produse_furnizori', 'produse', 'secvente_cod_fgo', 'unitati_masura', 'categorii', 'gestiuni', 'firme'] as $table) {
            Schema::dropIfExists($table);
        }
        parent::tearDown();
    }

    public function test_preview_and_confirmed_apply_update_all_confirmed_fields(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('sursa/registru-produse-kymco.xlsx', 'continut-test');
        config(['stock-register.path' => Storage::disk('local')->path('sursa/registru-produse-kymco.xlsx')]);
        $rows = [
            ['row' => 2, 'code' => 'ABC-1', 'stock' => 4, 'english_name' => 'English A', 'reorder_quantity' => 3, 'weight_kg' => '0.250', 'price_with_vat_eur' => '10', 'romanian_name' => 'Română A'],
            ['row' => 3, 'code' => 'ABC-2', 'stock' => 0, 'english_name' => 'English B', 'reorder_quantity' => 0, 'weight_kg' => '0.500', 'price_with_vat_eur' => '20', 'romanian_name' => 'Română B'],
            ['row' => 4, 'code' => 'MISSING', 'stock' => 3, 'english_name' => 'Missing', 'reorder_quantity' => 0, 'weight_kg' => '1', 'price_with_vat_eur' => '30', 'romanian_name' => 'Lipsă'],
        ];
        $this->mock(StockRegisterXlsxParser::class, function (MockInterface $mock) use ($rows): void {
            $mock->shouldReceive('parse')->times(3)->andReturn(['sheet' => 'Produse', 'rows' => $rows]);
        });
        $this->post('/stoc/actualizare/pregatire', ['exchange_rate' => '5,31'])
            ->assertRedirect('/stoc/actualizare/previzualizare');
        Storage::disk('local')->assertExists('stock-register/exchange-rate.txt');
        $this->assertSame('5.31', trim(Storage::disk('local')->get('stock-register/exchange-rate.txt')));
        $token = session('stock_update_import_preview.token');

        $this->get('/stoc/actualizare/previzualizare')
            ->assertOk()
            ->assertSee('Curs folosit: 1 EUR = 5,3100 lei')
            ->assertSee('MISSING')
            ->assertSee('53,10')
            ->assertSee('106,20')
            ->assertSee('English A')
            ->assertSee('Română A');

        $this->get('/stoc/actualizare/produs-nou/4')
            ->assertOk()
            ->assertSee('MISSING')
            ->assertSee('159.30');

        $this->post('/stoc/actualizare/produs-nou/4', [
            'token' => $token,
            'categorie_id' => 1,
            'unitate_masura_id' => 1,
            'marca' => 'KYMCO',
            'stoc_minim' => 1,
            'activ' => 1,
        ])->assertRedirect('/stoc/actualizare/previzualizare');
        $this->assertSame(2, session('stock_update_import_preview.preview.summary.price_changed'));

        $this->post('/stoc/actualizare/aplicare', [
            'token' => $token,
            'confirmare' => 1,
        ])->assertRedirect('/stoc/actualizare');

        $firstId = DB::table('produse')->where('cod_produs', 'ABC-1')->value('id');
        $secondId = DB::table('produse')->where('cod_produs', 'ABC-2')->value('id');
        $this->assertDatabaseHas('solduri_stoc', ['produs_id' => $firstId, 'cantitate_fizica' => 4]);
        $this->assertDatabaseHas('solduri_stoc', ['produs_id' => $secondId, 'cantitate_fizica' => 0]);
        $this->assertDatabaseHas('produse', ['id' => $firstId, 'pret_vanzare_cu_tva' => 53.10, 'greutate_kg' => 0.250, 'denumire_engleza' => 'English A', 'descriere_romana' => 'Română A', 'cantitate_de_comandat' => 3]);
        $this->assertDatabaseHas('produse', ['id' => $secondId, 'pret_vanzare_cu_tva' => 106.20, 'greutate_kg' => 0.500, 'denumire_engleza' => 'English B', 'descriere_romana' => 'Română B', 'cantitate_de_comandat' => 0]);
        $this->assertDatabaseCount('miscari_stoc', 1);
        $this->assertDatabaseHas('jurnal_audit', ['actiune' => 'actualizare_produse_xlsx']);
        $this->assertDatabaseHas('produse', [
            'cod_fgo' => '01000000',
            'cod_produs' => 'MISSING',
            'denumire_engleza' => 'Missing',
            'descriere_romana' => 'Lipsă',
            'pret_vanzare_cu_tva' => 159.30,
            'cantitate_de_comandat' => 0,
        ]);
    }

    public function test_manual_upload_remains_available_as_an_alternative(): void
    {
        Storage::fake('local');
        $rows = [
            ['row' => 2, 'code' => 'ABC-1', 'stock' => 4, 'english_name' => 'English A', 'reorder_quantity' => 0, 'weight_kg' => '0.250', 'price_with_vat_eur' => '10', 'romanian_name' => 'Română A'],
        ];
        $this->mock(StockRegisterXlsxParser::class, function (MockInterface $mock) use ($rows): void {
            $mock->shouldReceive('parse')->once()->andReturn(['sheet' => 'Produse', 'rows' => $rows]);
        });
        $this->get('/stoc/actualizare')
            ->assertOk()
            ->assertSee('Curs EUR/RON')
            ->assertSee('value="5.31"', false)
            ->assertSee('Încărcare manuală')
            ->assertSee('name="registru"', false);

        $this->post('/stoc/actualizare/incarcare', [
            'registru' => UploadedFile::fake()->create(
                'registru-manual.xlsx',
                20,
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ),
            'exchange_rate' => '5.31',
        ])->assertRedirect('/stoc/actualizare/previzualizare');

        $temporaryPath = session('stock_update_import_preview.temporary_path');
        $this->assertNotEmpty($temporaryPath);
        Storage::disk('local')->assertExists($temporaryPath);
    }

    public function test_ambiguous_codes_are_listed_and_block_application(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('sursa/registru-produse-kymco.xlsx', 'continut-test');
        config(['stock-register.path' => Storage::disk('local')->path('sursa/registru-produse-kymco.xlsx')]);
        DB::table('produse')->insert([
            'cod_produs' => 'ABC-1',
            'denumire_engleza' => 'Duplicat',
            'categorie_id' => 1,
            'unitate_masura_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $rows = [[
            'row' => 2,
            'code' => 'ABC-1',
            'stock' => 1,
            'english_name' => 'English A',
            'reorder_quantity' => 0,
            'weight_kg' => '0.250',
            'price_with_vat_eur' => '10',
            'romanian_name' => 'Română A',
        ]];
        $this->mock(StockRegisterXlsxParser::class, function (MockInterface $mock) use ($rows): void {
            $mock->shouldReceive('parse')->once()->andReturn(['sheet' => 'Produse', 'rows' => $rows]);
        });

        $this->post('/stoc/actualizare/pregatire', ['exchange_rate' => '5.31'])
            ->assertRedirect('/stoc/actualizare/previzualizare');

        $this->get('/stoc/actualizare/previzualizare')
            ->assertOk()
            ->assertSee('Aplicarea este blocată')
            ->assertSee('ABC-1')
            ->assertSee('2 produse');
    }

    public function test_manual_creation_is_limited_to_ten_products_per_import_session(): void
    {
        $draft = [
            'token' => '11111111-1111-4111-8111-111111111111',
            'created_products' => array_map(fn (int $id) => ['id' => $id, 'code' => "NEW-{$id}"], range(1, 10)),
            'preview' => ['missing' => [['row' => 2, 'code' => 'NEW-11']]],
        ];

        $this->withSession(['stock_update_import_preview' => $draft])
            ->get('/stoc/actualizare/produs-nou/2')
            ->assertRedirect('/stoc/actualizare/previzualizare')
            ->assertSessionHasErrors('registru');
    }
}
