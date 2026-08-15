<?php

namespace Tests\Feature;

use App\Models\FacturaFurnizor;
use App\Services\MotoTrendInvoiceParser;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Database\Seeders\ProduseTestSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Smalot\PdfParser\Parser;
use Tests\TestCase;

class FacturaFurnizorImportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createProductTables();
        $this->createInvoiceTables();
        app(ProduseTestSeeder::class)->run();
    }

    protected function tearDown(): void
    {
        foreach (['miscari_stoc', 'receptii_linii', 'exporturi_saga', 'receptii', 'facturi_furnizor_linii', 'facturi_furnizor', 'importuri_fisiere', 'solduri_stoc', 'produse_furnizori', 'produse', 'furnizori', 'unitati_masura', 'categorii', 'gestiuni', 'firme', 'secvente_cod_fgo'] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    public function test_parser_reads_all_moto_trend_invoice_lines(): void
    {
        $result = (new MotoTrendInvoiceParser(new Parser))->parse($this->invoicePath());

        $this->assertSame('ΤΙΠ-Ε-001967', $result['invoice_number']);
        $this->assertSame('2026-08-06', $result['invoice_date']);
        $this->assertSame('1360.72', $result['total_amount']);
        $this->assertSame(109, $result['total_quantity']);
        $this->assertCount(47, $result['lines']);
        $this->assertTrue(collect($result['lines'])->every('valid'));
        $this->assertSame('11102-1G87-004', $result['lines'][0]['supplier_code']);
        $this->assertSame('2.0633', $result['lines'][0]['unit_price']);
        $this->assertSame('94511-14000', $result['lines'][46]['supplier_code']);
    }

    public function test_parser_accepts_a_supplier_code_without_the_initial_database_pattern(): void
    {
        $parser = new MotoTrendInvoiceParser(new Parser);
        $method = new \ReflectionMethod($parser, 'parseLine');
        $line = $method->invoke($parser, '2 10,00 0,00 20,00SPECIAL/2026#A10,00 SPECIAL PRODUCTSPECIAL/2026#A', 1);

        $this->assertIsArray($line);
        $this->assertTrue($line['valid']);
        $this->assertSame('SPECIAL/2026#A', $line['supplier_code']);
        $this->assertSame('SPECIAL PRODUCT', $line['description']);
    }

    public function test_parser_preserves_available_values_when_the_product_description_is_missing(): void
    {
        $parser = new MotoTrendInvoiceParser(new Parser);
        $method = new \ReflectionMethod($parser, 'parseLine');
        $line = $method->invoke($parser, '3 15,97 35,0 10,0 28,03FS-11644169,00FS-116441', 68);

        $this->assertIsArray($line);
        $this->assertFalse($line['valid']);
        $this->assertSame('FS-116441', $line['supplier_code']);
        $this->assertSame('', $line['description']);
        $this->assertSame(3, $line['quantity']);
        $this->assertSame('28.03', $line['amount']);
        $this->assertSame('9.3433', $line['unit_price']);
        $this->assertSame('Description of Goods lipsește. Completează descrierea înainte de salvare.', $line['error']);
    }

    public function test_invoice_is_previewed_then_saved_with_automatic_mappings(): void
    {
        Storage::fake('local');
        $content = file_get_contents($this->invoicePath());

        $upload = $this->post('/facturi-furnizori/incarcare', [
            'factura_pdf' => UploadedFile::fake()->createWithContent('moto-trend.pdf', $content),
        ]);

        $upload->assertRedirect('/facturi-furnizori/previzualizare');
        $preview = $this->get('/facturi-furnizori/previzualizare');
        $preview->assertOk()
            ->assertSee('47 poziții detectate')
            ->assertSee('ΤΙΠ-Ε-001967')
            ->assertSee('11102-1G87-004')
            ->assertSee('Preț propus')
            ->assertSee('0.00 (preț actual)')
            ->assertSee('name="pret_vanzare_cu_tva"', false)
            ->assertSee('>OK</button>', false)
            ->assertSee('Taxare inversă: DA');

        $draft = session('factura_furnizor_import_preview');
        $lines = collect($draft['invoice']['lines'])->map(fn (array $line): array => [
            'supplier_code' => $line['supplier_code'],
            'description' => $line['description'],
            'quantity' => $line['quantity'],
            'amount' => $line['amount'],
            'product_id' => $line['product_id'],
        ])->all();

        $this->post('/facturi-furnizori/import', [
            'token' => $draft['token'],
            'lines' => $lines,
        ])->assertRedirect('/facturi-furnizori');

        $this->assertDatabaseCount('importuri_fisiere', 1);
        $this->assertDatabaseCount('facturi_furnizor', 1);
        $this->assertDatabaseCount('facturi_furnizor_linii', 47);
        $this->assertDatabaseHas('facturi_furnizor', [
            'numar_original' => 'ΤΙΠ-Ε-001967',
            'total_factura' => 1360.72,
            'total_tva' => 0,
            'taxare_inversa' => 1,
            'status' => 'import_partial',
        ]);
        $this->assertDatabaseHas('facturi_furnizor_linii', [
            'numar_linie' => 1,
            'cod_furnizor' => '11102-1G87-004',
            'pret_unitar_calculat' => 2.0633,
            'status_mapare' => 'mapat',
        ]);
        $this->assertSame(40, DB::table('facturi_furnizor_linii')->whereNull('produs_id')->count());
        $this->assertDatabaseHas('solduri_stoc', [
            'produs_id' => DB::table('produse')->where('cod_produs', '11102-1G87-004')->value('id'),
            'cantitate_fizica' => 11,
        ]);
        $this->assertDatabaseHas('facturi_furnizor_linii', [
            'cod_furnizor' => '11192-LBA7-900',
            'observatii' => 'Atenție la recepție: prețul de vânzare cu TVA trebuie actualizat la 51.75 RON.',
        ]);
    }

    public function test_proposed_sale_price_can_be_edited_and_confirmed_immediately(): void
    {
        Storage::fake('local');
        $this->post('/facturi-furnizori/incarcare', [
            'factura_pdf' => UploadedFile::fake()->createWithContent('moto-trend.pdf', file_get_contents($this->invoicePath())),
        ])->assertRedirect('/facturi-furnizori/previzualizare');

        $draft = session('factura_furnizor_import_preview');
        $lineIndex = collect($draft['invoice']['lines'])
            ->search(fn (array $line): bool => ! empty($line['product_id']) && $line['price_warning']);
        $this->assertIsInt($lineIndex);
        $productId = $draft['invoice']['lines'][$lineIndex]['product_id'];

        $this->patch("/facturi-furnizori/previzualizare/pret/{$lineIndex}", [
            'token' => $draft['token'],
            'pret_vanzare_cu_tva' => '55.25',
        ])->assertRedirect('/facturi-furnizori/previzualizare');

        $this->assertDatabaseHas('produse', [
            'id' => $productId,
            'pret_vanzare_cu_tva' => 55.25,
            'pret_vanzare_fara_tva' => 45.6612,
        ]);
        $updatedLine = session('factura_furnizor_import_preview')['invoice']['lines'][$lineIndex];
        $this->assertSame('55.25', $updatedLine['current_sale_price']);
        $this->assertSame('55.25', $updatedLine['proposed_sale_price']);
        $this->assertFalse($updatedLine['price_warning']);
    }

    public function test_same_pdf_cannot_be_imported_twice(): void
    {
        Storage::fake('local');
        $hash = hash_file('sha256', $this->invoicePath());
        DB::table('importuri_fisiere')->insert([
            'tip' => 'factura_furnizor_moto_trend',
            'nume_fisier' => 'deja.pdf',
            'hash_sha256' => $hash,
            'cale_stocare' => 'importuri/deja.pdf',
            'status' => 'finalizat',
            'created_at' => now(),
        ]);

        $this->post('/facturi-furnizori/incarcare', [
            'factura_pdf' => UploadedFile::fake()->createWithContent('duplicat.pdf', file_get_contents($this->invoicePath())),
        ])->assertRedirect()->assertSessionHasErrors('factura_pdf');
    }

    public function test_existing_product_with_an_incomplete_invoice_line_opens_manual_preview_instead_of_failing(): void
    {
        Storage::fake('local');
        $supplierId = DB::table('furnizori')->where('cod_fiscal', 'EL094496688')->value('id');
        $productId = DB::table('produse')->value('id');
        DB::table('produse_furnizori')->updateOrInsert(
            ['furnizor_id' => $supplierId, 'cod_furnizor' => 'FS-116441'],
            [
                'produs_id' => $productId,
                'denumire_furnizor' => 'FS-116441',
                'moneda' => 'EUR',
                'confirmata_manual' => true,
            ],
        );

        $this->mock(MotoTrendInvoiceParser::class, function ($mock): void {
            $mock->shouldReceive('parse')->once()->andReturn([
                'supplier_vat' => 'EL094496688',
                'customer_vat' => 'RO20548513',
                'invoice_number' => 'TEST-FS-116441',
                'invoice_date' => '2026-06-18',
                'currency' => 'EUR',
                'total_amount' => '28.03',
                'total_quantity' => 3,
                'lines' => [[
                    'line_number' => 1,
                    'supplier_code' => 'FS-116441',
                    'description' => '',
                    'quantity' => '',
                    'amount' => '',
                    'unit_price' => '',
                    'valid' => false,
                    'error' => 'Linia nu a putut fi citită. Completează manual câmpurile.',
                    'source' => '3 15,97 35,0 10,0 28,03 FS-116441',
                ]],
            ]);
        });

        $this->post('/facturi-furnizori/incarcare', [
            'factura_pdf' => UploadedFile::fake()->createWithContent('fs-116441.pdf', file_get_contents($this->invoicePath())),
        ])->assertRedirect('/facturi-furnizori/previzualizare');

        $this->get('/facturi-furnizori/previzualizare')
            ->assertOk()
            ->assertSee('FS-116441')
            ->assertSee('Completează manual câmpurile.')
            ->assertDontSee('Preț propus');
    }

    public function test_invoice_cannot_be_saved_without_description_of_goods(): void
    {
        Storage::fake('local');
        $this->post('/facturi-furnizori/incarcare', [
            'factura_pdf' => UploadedFile::fake()->createWithContent('moto-trend.pdf', file_get_contents($this->invoicePath())),
        ])->assertRedirect('/facturi-furnizori/previzualizare');

        $draft = session('factura_furnizor_import_preview');
        $lines = collect($draft['invoice']['lines'])->map(fn (array $line): array => [
            'supplier_code' => $line['supplier_code'],
            'description' => $line['description'],
            'quantity' => $line['quantity'],
            'amount' => $line['amount'],
            'product_id' => $line['product_id'],
        ])->all();
        $lines[0]['description'] = '';

        $this->post('/facturi-furnizori/import', [
            'token' => $draft['token'],
            'lines' => $lines,
        ])->assertSessionHasErrors('lines.0.description');

        $this->assertDatabaseCount('facturi_furnizor', 0);
    }

    public function test_unmapped_invoice_line_can_create_and_activate_a_new_product(): void
    {
        Storage::fake('local');
        $this->post('/facturi-furnizori/incarcare', [
            'factura_pdf' => UploadedFile::fake()->createWithContent('moto-trend.pdf', file_get_contents($this->invoicePath())),
        ])->assertRedirect('/facturi-furnizori/previzualizare');

        $draft = session('factura_furnizor_import_preview');
        $lineIndex = collect($draft['invoice']['lines'])
            ->search(fn (array $line): bool => empty($line['product_id']));
        $this->assertIsInt($lineIndex);
        $line = $draft['invoice']['lines'][$lineIndex];

        $this->get('/facturi-furnizori/previzualizare')
            ->assertOk()
            ->assertSee('Produs NOU');

        $this->get("/facturi-furnizori/previzualizare/produs-nou/{$lineIndex}")
            ->assertOk()
            ->assertSee($line['supplier_code'])
            ->assertSee($line['description'])
            ->assertSee($line['proposed_sale_price'])
            ->assertSee('preț intrare × 11,5');

        $categoryId = DB::table('categorii')->where('denumire', 'Pe comanda')->value('id');
        $unitId = DB::table('unitati_masura')->where('cod', 'BUC')->value('id');

        $customProductCode = 'special/2026 test';
        $this->post("/facturi-furnizori/previzualizare/produs-nou/{$lineIndex}", [
            'token' => $draft['token'],
            'cod_produs' => $customProductCode,
            'denumire_engleza' => $line['description'],
            'descriere_romana' => 'Produs creat din factura',
            'categorie_id' => $categoryId,
            'unitate_masura_id' => $unitId,
            'marca' => 'KYMCO',
            'stoc_minim' => 1,
            'pret_intrare' => $line['unit_price'],
            'pret_vanzare_cu_tva' => $line['proposed_sale_price'],
            'greutate_kg' => null,
            'voluminos' => 0,
            'lungime_cm' => null,
            'latime_cm' => null,
            'inaltime_cm' => null,
            'activ' => 1,
        ])->assertRedirect('/facturi-furnizori/previzualizare');

        $productId = DB::table('produse')->where('cod_produs', mb_strtoupper($customProductCode))->value('id');
        $this->assertNotNull($productId);
        $this->assertDatabaseHas('produse', [
            'id' => $productId,
            'cod_fgo' => '01000000',
            'cod_produs' => 'SPECIAL/2026 TEST',
            'pret_vanzare_cu_tva' => $line['proposed_sale_price'],
            'cota_tva' => 21,
            'activ' => 1,
            'sursa' => 'factura_moto_trend',
        ]);
        $this->assertDatabaseHas('produse_furnizori', [
            'produs_id' => $productId,
            'cod_furnizor' => $line['supplier_code'],
            'pret_achizitie_ultim' => $line['unit_price'],
            'moneda' => 'EUR',
        ]);
        $this->assertSame($productId, session('factura_furnizor_import_preview')['invoice']['lines'][$lineIndex]['product_id']);
    }

    public function test_partial_import_can_be_completed_and_finalized(): void
    {
        $factura = $this->importInvoice();

        $this->get("/facturi-furnizori/{$factura->id}")
            ->assertOk()
            ->assertSee('Import parțial')
            ->assertSee('Produs NOU')
            ->assertSee('Finalizează importul');

        $this->post("/facturi-furnizori/{$factura->id}/finalizare")
            ->assertSessionHasErrors('factura');

        $productId = DB::table('produse')->value('id');
        $lines = DB::table('facturi_furnizor_linii')
            ->where('factura_id', $factura->id)
            ->pluck('id')
            ->mapWithKeys(fn (int $id): array => [$id => ['product_id' => $productId]])
            ->all();

        $this->patch("/facturi-furnizori/{$factura->id}/mapari", ['lines' => $lines])
            ->assertSessionHasNoErrors();
        $this->post("/facturi-furnizori/{$factura->id}/finalizare")
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('facturi_furnizor', ['id' => $factura->id, 'status' => 'import_finalizat']);
        $this->assertSame(0, DB::table('facturi_furnizor_linii')->where('factura_id', $factura->id)->whereNull('produs_id')->count());
    }

    public function test_finalized_invoice_is_received_integrally_only_after_manual_saga_confirmation(): void
    {
        $factura = $this->importInvoice();
        $productId = DB::table('produse')->value('id');
        $gestiuneId = DB::table('gestiuni')->where('cod', 'FIRMA')->value('id');
        $initialStock = (int) DB::table('solduri_stoc')
            ->where('gestiune_id', $gestiuneId)
            ->where('produs_id', $productId)
            ->value('cantitate_fizica');
        $invoiceQuantity = (int) DB::table('facturi_furnizor_linii')
            ->where('factura_id', $factura->id)
            ->sum('cantitate');

        $this->mapAllLinesAndFinalize($factura, $productId);

        $this->get("/facturi-furnizori/{$factura->id}/receptie")
            ->assertOk()
            ->assertSee('Recepția este integrală și definitivă')
            ->assertSee('name="data_receptie" value="'.now()->toDateString().'"', false)
            ->assertSee('name="confirmare_saga"', false);

        $this->post("/facturi-furnizori/{$factura->id}/receptie", [
            'data_receptie' => '2026-08-15',
        ])->assertSessionHasErrors('confirmare_saga');
        $this->assertDatabaseCount('receptii', 0);
        $this->assertDatabaseCount('miscari_stoc', 0);

        $this->post("/facturi-furnizori/{$factura->id}/receptie", [
            'data_receptie' => '2026-08-15',
            'confirmare_saga' => '1',
        ])->assertRedirect("/facturi-furnizori/{$factura->id}")
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('receptii', [
            'factura_id' => $factura->id,
            'gestiune_id' => $gestiuneId,
            'data_receptie' => '2026-08-15 00:00:00',
            'status' => 'finalizata',
        ]);
        $this->assertDatabaseCount('receptii_linii', 47);
        $this->assertDatabaseCount('miscari_stoc', 47);
        $this->assertSame($invoiceQuantity, (int) DB::table('miscari_stoc')->sum('cantitate'));
        $this->assertSame(
            $initialStock + $invoiceQuantity,
            (int) DB::table('solduri_stoc')
                ->where('gestiune_id', $gestiuneId)
                ->where('produs_id', $productId)
                ->value('cantitate_fizica')
        );
        $this->assertDatabaseHas('produse_furnizori', [
            'furnizor_id' => $factura->furnizor_id,
            'cod_furnizor' => '11102-1G87-004',
            'pret_achizitie_ultim' => '2.0633',
            'moneda' => 'EUR',
        ]);

        $stockAfterReception = (int) DB::table('solduri_stoc')
            ->where('gestiune_id', $gestiuneId)
            ->where('produs_id', $productId)
            ->value('cantitate_fizica');
        $this->post("/facturi-furnizori/{$factura->id}/receptie", [
            'data_receptie' => '2026-08-15',
            'confirmare_saga' => '1',
        ])->assertSessionHasErrors('receptie');
        $this->assertSame($stockAfterReception, (int) DB::table('solduri_stoc')
            ->where('gestiune_id', $gestiuneId)
            ->where('produs_id', $productId)
            ->value('cantitate_fizica'));
    }

    public function test_partial_invoice_cannot_enter_reception(): void
    {
        $factura = $this->importInvoice();

        $this->get("/facturi-furnizori/{$factura->id}/receptie")
            ->assertRedirect("/facturi-furnizori/{$factura->id}")
            ->assertSessionHasErrors('receptie');

        $this->post("/facturi-furnizori/{$factura->id}/receptie", [
            'data_receptie' => '2026-08-15',
            'confirmare_saga' => '1',
        ])->assertSessionHasErrors('receptie');
        $this->assertDatabaseCount('receptii', 0);
    }

    public function test_unreceived_invoice_can_be_deleted_and_reimported(): void
    {
        $factura = $this->importInvoice();
        $import = DB::table('importuri_fisiere')->first();
        $this->assertTrue(Storage::disk('local')->exists($import->cale_stocare));

        $this->delete("/facturi-furnizori/{$factura->id}")
            ->assertRedirect('/facturi-furnizori')
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('facturi_furnizor', 0);
        $this->assertDatabaseCount('facturi_furnizor_linii', 0);
        $this->assertDatabaseCount('importuri_fisiere', 0);
        $this->assertFalse(Storage::disk('local')->exists($import->cale_stocare));
        $this->assertDatabaseCount('produse', 7);

        $this->post('/facturi-furnizori/incarcare', [
            'factura_pdf' => UploadedFile::fake()->createWithContent('reimport.pdf', file_get_contents($this->invoicePath())),
        ])->assertRedirect('/facturi-furnizori/previzualizare');
    }

    public function test_new_product_can_be_created_while_continuing_a_partial_import(): void
    {
        $factura = $this->importInvoice();
        $line = DB::table('facturi_furnizor_linii')
            ->where('factura_id', $factura->id)
            ->whereNull('produs_id')
            ->first();

        $this->get("/facturi-furnizori/{$factura->id}/linii/{$line->id}/produs-nou")
            ->assertOk()
            ->assertSee($line->cod_furnizor)
            ->assertSee('preț intrare × 11,5');

        $categoryId = DB::table('categorii')->where('denumire', 'Pe comanda')->value('id');
        $unitId = DB::table('unitati_masura')->where('cod', 'BUC')->value('id');
        $salePrice = BigDecimal::of($line->pret_unitar_calculat)
            ->multipliedBy('11.5')
            ->toScale(2, RoundingMode::HalfUp)
            ->__toString();

        $this->post("/facturi-furnizori/{$factura->id}/linii/{$line->id}/produs-nou", [
            'cod_produs' => $line->cod_furnizor,
            'denumire_engleza' => $line->descriere_originala,
            'descriere_romana' => null,
            'categorie_id' => $categoryId,
            'unitate_masura_id' => $unitId,
            'marca' => 'KYMCO',
            'stoc_minim' => 1,
            'pret_intrare' => number_format((float) $line->pret_unitar_calculat, 4, '.', ''),
            'pret_vanzare_cu_tva' => $salePrice,
            'greutate_kg' => null,
            'voluminos' => 0,
            'lungime_cm' => null,
            'latime_cm' => null,
            'inaltime_cm' => null,
            'activ' => 1,
        ])->assertRedirect("/facturi-furnizori/{$factura->id}");

        $productId = DB::table('produse')->where('cod_produs', $line->cod_furnizor)->value('id');
        $this->assertDatabaseHas('produse', [
            'id' => $productId,
            'cod_fgo' => '01000000',
            'activ' => 1,
        ]);
        $this->assertDatabaseHas('facturi_furnizor_linii', [
            'id' => $line->id,
            'produs_id' => $productId,
            'status_mapare' => 'mapat',
        ]);
    }

    public function test_product_used_by_invoice_cannot_be_deleted(): void
    {
        $factura = $this->importInvoice();
        $productId = DB::table('facturi_furnizor_linii')
            ->where('factura_id', $factura->id)
            ->whereNotNull('produs_id')
            ->value('produs_id');

        $this->delete("/produse/{$productId}")->assertSessionHasErrors('produs');
        $this->assertDatabaseHas('produse', ['id' => $productId]);
    }

    private function importInvoice(): FacturaFurnizor
    {
        Storage::fake('local');
        $this->post('/facturi-furnizori/incarcare', [
            'factura_pdf' => UploadedFile::fake()->createWithContent('moto-trend.pdf', file_get_contents($this->invoicePath())),
        ])->assertRedirect('/facturi-furnizori/previzualizare');

        $draft = session('factura_furnizor_import_preview');
        $lines = collect($draft['invoice']['lines'])->map(fn (array $line): array => [
            'supplier_code' => $line['supplier_code'],
            'description' => $line['description'],
            'quantity' => $line['quantity'],
            'amount' => $line['amount'],
            'product_id' => $line['product_id'],
        ])->all();

        $this->post('/facturi-furnizori/import', ['token' => $draft['token'], 'lines' => $lines])
            ->assertRedirect('/facturi-furnizori');

        return FacturaFurnizor::query()->sole();
    }

    private function mapAllLinesAndFinalize(FacturaFurnizor $factura, int $productId): void
    {
        $lines = DB::table('facturi_furnizor_linii')
            ->where('factura_id', $factura->id)
            ->pluck('id')
            ->mapWithKeys(fn (int $id): array => [$id => ['product_id' => $productId]])
            ->all();

        $this->patch("/facturi-furnizori/{$factura->id}/mapari", ['lines' => $lines])
            ->assertSessionHasNoErrors();
        $this->post("/facturi-furnizori/{$factura->id}/finalizare")
            ->assertSessionHasNoErrors();
    }

    private function invoicePath(): string
    {
        return glob(base_path('facturi-furnizori/MOTO-TREND/*.pdf'))[0];
    }

    private function createProductTables(): void
    {
        Schema::create('secvente_cod_fgo', function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('urmatorul_cod');
            $table->unsignedBigInteger('cod_minim');
            $table->unsignedBigInteger('cod_maxim');
        });
        DB::table('secvente_cod_fgo')->insert([
            'id' => 1,
            'urmatorul_cod' => 1000000,
            'cod_minim' => 1000000,
            'cod_maxim' => 8999999,
        ]);

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

    private function createInvoiceTables(): void
    {
        Schema::create('importuri_fisiere', function (Blueprint $table): void {
            $table->id();
            $table->string('tip', 32);
            $table->string('nume_fisier');
            $table->char('hash_sha256', 64);
            $table->string('cale_stocare', 500);
            $table->string('status', 32);
            $table->json('rezultat')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->unique(['hash_sha256', 'tip']);
        });
        Schema::create('facturi_furnizor', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('furnizor_id');
            $table->unsignedBigInteger('import_fisier_id')->nullable();
            $table->string('numar_original', 100);
            $table->string('numar_normalizat', 100);
            $table->date('data_factura');
            $table->date('data_scadenta')->nullable();
            $table->char('moneda', 3);
            $table->decimal('total_fara_tva', 18, 2);
            $table->decimal('total_tva', 18, 2)->default(0);
            $table->decimal('total_factura', 18, 2);
            $table->boolean('taxare_inversa')->default(false);
            $table->string('status', 32);
            $table->timestamps();
            $table->unique(['furnizor_id', 'numar_original']);
        });
        Schema::create('facturi_furnizor_linii', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('factura_id');
            $table->unsignedInteger('numar_linie');
            $table->unsignedBigInteger('produs_id')->nullable();
            $table->string('cod_furnizor', 100);
            $table->string('descriere_originala', 500);
            $table->bigInteger('cantitate');
            $table->string('unitate_masura_originala', 16)->nullable();
            $table->decimal('amount_sursa', 18, 2);
            $table->decimal('pret_unitar_calculat', 24, 12);
            $table->decimal('cota_tva', 5, 2);
            $table->decimal('valoare_tva', 18, 2)->default(0);
            $table->string('status_mapare', 32);
            $table->text('observatii')->nullable();
            $table->unique(['factura_id', 'numar_linie']);
        });

        Schema::create('receptii', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('factura_id')->unique();
            $table->unsignedBigInteger('gestiune_id');
            $table->dateTime('data_receptie');
            $table->string('status');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('receptii_linii', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('receptie_id');
            $table->unsignedBigInteger('factura_linie_id');
            $table->unsignedBigInteger('produs_id');
            $table->bigInteger('cantitate');
            $table->decimal('cost_unitar', 24, 12);
            $table->decimal('valoare', 18, 2);
            $table->unique(['receptie_id', 'factura_linie_id']);
        });

        Schema::create('miscari_stoc', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('gestiune_id');
            $table->unsignedBigInteger('produs_id');
            $table->string('tip', 32);
            $table->bigInteger('cantitate');
            $table->decimal('cost_unitar', 24, 12)->nullable();
            $table->unsignedBigInteger('receptie_linie_id')->nullable();
            $table->string('referinta_tip', 32)->nullable();
            $table->unsignedBigInteger('referinta_id')->nullable();
            $table->string('explicatie', 500);
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('exporturi_saga', function (Blueprint $table): void {
            $table->id();
            $table->string('tip');
            $table->unsignedBigInteger('factura_id')->nullable();
            $table->string('nume_fisier');
            $table->string('hash_sha256', 64)->unique();
            $table->string('cale_stocare');
            $table->string('status');
            $table->dateTime('confirmat_la')->nullable();
            $table->text('mesaj_rezultat')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }
}
