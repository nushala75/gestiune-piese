<?php

namespace Tests\Feature;

use App\Models\Gestiune;
use App\Models\Produs;
use App\Services\ProductRegisterSynchronizer;
use App\Services\StockRegisterExchangeRate;
use App\Services\StockRegisterXlsxParser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ProductRegisterSynchronizerTest extends TestCase
{
    private string $workbookPath;

    private Gestiune $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

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
            $table->char('cod_fgo', 8)->nullable();
            $table->string('cod_produs');
            $table->string('denumire_engleza');
            $table->text('descriere_romana')->nullable();
            $table->unsignedBigInteger('categorie_id')->nullable();
            $table->unsignedBigInteger('unitate_masura_id')->nullable();
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
            $table->string('sursa')->default('test');
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

        $companyId = DB::table('firme')->insertGetId(['cod_fiscal' => 'RO20548513']);
        $warehouseId = DB::table('gestiuni')->insertGetId(['firma_id' => $companyId, 'cod' => 'FIRMA']);
        $this->warehouse = Gestiune::query()->findOrFail($warehouseId);

        $this->workbookPath = sys_get_temp_dir().'/kymco-register-sync-'.bin2hex(random_bytes(6)).'.xlsx';
        copy(base_path('registru-produse-kymco.xlsx'), $this->workbookPath);
        app(StockRegisterExchangeRate::class)->remember('5.31');
    }

    protected function tearDown(): void
    {
        @unlink($this->workbookPath);
        foreach (['solduri_stoc', 'produse', 'gestiuni', 'firme'] as $table) {
            Schema::dropIfExists($table);
        }
        parent::tearDown();
    }

    public function test_it_updates_all_mapped_values_and_preserves_the_vat_formula(): void
    {
        $product = $this->product('106B-KPS0-003');
        DB::table('solduri_stoc')->insert([
            'gestiune_id' => $this->warehouse->id,
            'produs_id' => $product->id,
            'cantitate_fizica' => 7,
            'cantitate_rezervata' => 0,
        ]);

        app(ProductRegisterSynchronizer::class)->updateFile($this->workbookPath, [$product], $this->warehouse);

        $row = collect(app(StockRegisterXlsxParser::class)->parse($this->workbookPath)['rows'])
            ->firstWhere('code', '106B-KPS0-003');
        $this->assertSame(7, $row['stock']);
        $this->assertSame('UPDATED ENGLISH NAME', $row['english_name']);
        $this->assertSame(4, $row['reorder_quantity']);
        $this->assertSame('0.321', $row['weight_kg']);
        $this->assertSame('12.1', $row['price_with_vat_eur']);
        $this->assertSame('Nume română actualizat', $row['romanian_name']);

        $xml = $this->worksheetXml();
        $this->assertStringContainsString('<c r="J2"', $xml);
        $this->assertMatchesRegularExpression('/<c r="J2"[^>]*><v>10<\/v><\/c>/', $xml);
        $this->assertMatchesRegularExpression('/<c r="K2"[^>]*><f[^>]*>J2\*1\.21<\/f><v>12\.1<\/v><\/c>/', $xml);
    }

    public function test_a_locked_workbook_blocks_and_rolls_back_the_database_change_with_unsaved_values(): void
    {
        $product = $this->product('106B-KPS0-003');
        DB::table('solduri_stoc')->insert([
            'gestiune_id' => $this->warehouse->id,
            'produs_id' => $product->id,
            'cantitate_fizica' => 1,
            'cantitate_rezervata' => 0,
        ]);
        $handle = fopen($this->workbookPath, 'r+b');
        flock($handle, LOCK_EX | LOCK_NB);

        try {
            DB::transaction(function () use ($product): void {
                $product->update(['denumire_engleza' => 'NESALVAT']);
                app(ProductRegisterSynchronizer::class)->updateFile($this->workbookPath, [$product->refresh()], $this->warehouse);
            });
            $this->fail('Sincronizarea trebuia blocată.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('deschis sau blocat', $exception->errors()['registru_excel'][0]);
            $this->assertStringContainsString('Produse neactualizate', $exception->errors()['registru_excel'][0]);
            $this->assertStringContainsString('EN „NESALVAT”', $exception->errors()['registru_excel'][0]);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }

        $this->assertSame('UPDATED ENGLISH NAME', $product->refresh()->denumire_engleza);
    }

    public function test_a_product_absent_from_excel_does_not_block_the_database_save(): void
    {
        $product = $this->product('NOT-IN-REGISTER');
        $before = hash_file('sha256', $this->workbookPath);

        app(ProductRegisterSynchronizer::class)->updateFile($this->workbookPath, [$product], $this->warehouse);

        $this->assertSame($before, hash_file('sha256', $this->workbookPath));
    }

    public function test_the_last_editable_exchange_rate_is_used_for_the_excel_net_price(): void
    {
        app(StockRegisterExchangeRate::class)->remember('5.5');
        $product = $this->product('106B-KPS0-003');
        $product->update(['pret_vanzare_fara_tva' => '55.0000']);

        app(ProductRegisterSynchronizer::class)->updateFile($this->workbookPath, [$product->refresh()], $this->warehouse);

        $this->assertSame('5.5', app(StockRegisterExchangeRate::class)->current());
        $this->assertMatchesRegularExpression('/<c r="J2"[^>]*><v>10<\/v><\/c>/', $this->worksheetXml());
    }

    private function product(string $code): Produs
    {
        return Produs::query()->create([
            'cod_produs' => $code,
            'denumire_engleza' => 'UPDATED ENGLISH NAME',
            'descriere_romana' => 'Nume română actualizat',
            'cantitate_de_comandat' => 4,
            'pret_vanzare_fara_tva' => '53.1000',
            'cota_tva' => '21.00',
            'greutate_kg' => '0.321',
        ]);
    }

    private function worksheetXml(): string
    {
        $zipPath = $this->workbookPath.'.zip';
        copy($this->workbookPath, $zipPath);
        try {
            $archive = new \PharData($zipPath);

            return $archive['xl/worksheets/sheet1.xml']->getContent();
        } finally {
            @unlink($zipPath);
        }
    }
}
