<?php

namespace Tests\Unit;

use App\Models\Categorie;
use App\Models\ExportFgoStoc;
use App\Models\ExportFgoStocLinie;
use App\Models\ExportSaga;
use App\Models\FacturaFurnizor;
use App\Models\FacturaFurnizorLinie;
use App\Models\Firma;
use App\Models\Furnizor;
use App\Models\Gestiune;
use App\Models\ImportFisier;
use App\Models\JurnalAudit;
use App\Models\MiscareStoc;
use App\Models\Produs;
use App\Models\ProdusFurnizor;
use App\Models\Receptie;
use App\Models\ReceptieLinie;
use App\Models\SecventaCodFgo;
use App\Models\SoldStoc;
use App\Models\UnitateMasura;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ModelSchemaMappingTest extends TestCase
{
    public static function tableMappings(): array
    {
        return [
            [Firma::class, 'firme'], [Gestiune::class, 'gestiuni'],
            [Categorie::class, 'categorii'], [UnitateMasura::class, 'unitati_masura'],
            [SecventaCodFgo::class, 'secvente_cod_fgo'], [Produs::class, 'produse'],
            [Furnizor::class, 'furnizori'], [ProdusFurnizor::class, 'produse_furnizori'],
            [ImportFisier::class, 'importuri_fisiere'], [FacturaFurnizor::class, 'facturi_furnizor'],
            [FacturaFurnizorLinie::class, 'facturi_furnizor_linii'], [Receptie::class, 'receptii'],
            [ReceptieLinie::class, 'receptii_linii'], [MiscareStoc::class, 'miscari_stoc'],
            [SoldStoc::class, 'solduri_stoc'], [ExportSaga::class, 'exporturi_saga'],
            [ExportFgoStoc::class, 'exporturi_fgo_stoc'],
            [ExportFgoStocLinie::class, 'exporturi_fgo_stoc_linii'],
            [JurnalAudit::class, 'jurnal_audit'],
        ];
    }

    #[DataProvider('tableMappings')]
    public function test_model_uses_approved_table(string $modelClass, string $table): void
    {
        $this->assertSame($table, (new $modelClass)->getTable());
    }

    public function test_product_price_precision_matches_schema(): void
    {
        $casts = (new Produs)->getCasts();

        $this->assertSame('decimal:4', $casts['pret_vanzare_fara_tva']);
        $this->assertSame('decimal:2', $casts['pret_vanzare_cu_tva']);
    }

    public function test_supplier_purchase_price_uses_four_decimals(): void
    {
        $this->assertSame('decimal:4', (new ProdusFurnizor)->getCasts()['pret_achizitie_ultim']);
    }

    public function test_all_quantity_casts_are_integers(): void
    {
        $this->assertSame('integer', (new Produs)->getCasts()['stoc_minim']);
        $this->assertSame('integer', (new FacturaFurnizorLinie)->getCasts()['cantitate']);
        $this->assertSame('integer', (new ReceptieLinie)->getCasts()['cantitate']);
        $this->assertSame('integer', (new MiscareStoc)->getCasts()['cantitate']);
        $this->assertSame('integer', (new SoldStoc)->getCasts()['cantitate_fizica']);
        $this->assertSame('integer', (new SoldStoc)->getCasts()['cantitate_rezervata']);
        $this->assertSame('integer', (new ExportFgoStocLinie)->getCasts()['cantitate']);
    }
}
