<?php

namespace Database\Seeders;

use App\Models\Categorie;
use App\Models\Firma;
use App\Models\Furnizor;
use App\Models\Gestiune;
use App\Models\Produs;
use App\Models\ProdusFurnizor;
use App\Models\SoldStoc;
use App\Models\UnitateMasura;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ProduseTestSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $firma = Firma::query()->firstOrCreate(
                ['cod_fiscal' => 'RO20548513'],
                ['denumire' => 'DESIGN MEDIA BUSINESS SRL'],
            );

            $gestiune = Gestiune::query()->firstOrCreate(
                ['firma_id' => $firma->id, 'cod' => 'FIRMA'],
                ['denumire' => 'FIRMA', 'activa' => true],
            );

            $categorii = collect(['Marfuri', 'Pe comanda'])->mapWithKeys(
                fn (string $denumire) => [
                    $denumire => Categorie::query()->firstOrCreate(
                        ['denumire' => $denumire],
                        ['activa' => true],
                    ),
                ],
            );

            $unitati = collect([
                'BUC' => 'Bucata',
                'SET' => 'Set',
            ])->mapWithKeys(
                fn (string $denumire, string $cod) => [
                    $cod => UnitateMasura::query()->firstOrCreate(
                        ['cod' => $cod],
                        ['denumire' => $denumire, 'activa' => true],
                    ),
                ],
            );

            $furnizor = Furnizor::query()->firstOrCreate(
                ['cod_fiscal' => 'EL094496688'],
                [
                    'denumire' => 'MOTO TREND S.A',
                    'tara' => 'GR',
                    'moneda_implicita' => 'EUR',
                    'activ' => true,
                ],
            );

            foreach ($this->produse() as $date) {
                $produs = Produs::query()->updateOrCreate(
                    ['cod_fgo' => $date['cod_fgo']],
                    [
                        'cod_produs' => $date['cod_produs'],
                        'denumire_engleza' => $date['denumire_engleza'],
                        'descriere_romana' => $date['descriere_romana'],
                        'categorie_id' => $categorii[$date['categorie']]->id,
                        'unitate_masura_id' => $unitati[$date['um']]->id,
                        'marca' => 'KYMCO',
                        'stoc_minim' => 1,
                        'pret_vanzare_fara_tva' => $date['pret_vanzare_fara_tva'],
                        'pret_vanzare_cu_tva' => $date['pret_vanzare_cu_tva'],
                        'cota_tva' => 21,
                        'activ' => true,
                        'sursa' => 'fgo_test',
                    ],
                );

                ProdusFurnizor::query()->updateOrCreate(
                    [
                        'furnizor_id' => $furnizor->id,
                        'cod_furnizor' => $date['cod_produs'],
                    ],
                    [
                        'produs_id' => $produs->id,
                        'denumire_furnizor' => $date['cod_produs'].' '.$date['denumire_engleza'],
                        'pret_achizitie_ultim' => $date['pret_intrare'],
                        'moneda' => 'EUR',
                        'data_ultimei_achizitii' => '2026-08-06',
                        'confirmata_manual' => true,
                    ],
                );

                SoldStoc::query()->updateOrCreate(
                    [
                        'gestiune_id' => $gestiune->id,
                        'produs_id' => $produs->id,
                    ],
                    [
                        'cantitate_fizica' => $date['stoc_initial'],
                        'cantitate_rezervata' => 0,
                    ],
                );
            }
        });
    }

    /** @return array<int, array<string, int|float|string>> */
    private function produse(): array
    {
        return [
            ['cod_fgo' => '00445402', 'cod_produs' => '11102-1G87-004', 'denumire_engleza' => 'RUB BUSH ENG HANGER', 'descriere_romana' => 'Bucsa motor', 'categorie' => 'Marfuri', 'um' => 'BUC', 'stoc_initial' => 11, 'pret_vanzare_fara_tva' => 20.6612, 'pret_vanzare_cu_tva' => 25.00, 'pret_intrare' => 2.0633],
            ['cod_fgo' => '00446656', 'cod_produs' => '11192-LBA7-900', 'denumire_engleza' => 'GASKET CRANK CASE', 'descriere_romana' => 'Garnitura intre cartere', 'categorie' => 'Pe comanda', 'um' => 'BUC', 'stoc_initial' => 2, 'pret_vanzare_fara_tva' => 0.0000, 'pret_vanzare_cu_tva' => 0.00, 'pret_intrare' => 4.5000],
            ['cod_fgo' => '00445579', 'cod_produs' => '12100-KHE7-900', 'denumire_engleza' => 'CYLINDER COMP', 'descriere_romana' => 'Cilindru', 'categorie' => 'Marfuri', 'um' => 'BUC', 'stoc_initial' => 4, 'pret_vanzare_fara_tva' => 797.5207, 'pret_vanzare_cu_tva' => 965.00, 'pret_intrare' => 109.6800],
            ['cod_fgo' => '00445380', 'cod_produs' => '12391-KHE7-900', 'denumire_engleza' => 'GASKET HEAD COVER', 'descriere_romana' => 'Garnitura capac chiulasa', 'categorie' => 'Pe comanda', 'um' => 'BUC', 'stoc_initial' => 1, 'pret_vanzare_fara_tva' => 0.0000, 'pret_vanzare_cu_tva' => 0.00, 'pret_intrare' => 4.2300],
            ['cod_fgo' => '00451652', 'cod_produs' => '13000-BLB3-910', 'denumire_engleza' => 'CARNK SHAFT COMP', 'descriere_romana' => 'Ambielaj', 'categorie' => 'Pe comanda', 'um' => 'BUC', 'stoc_initial' => 0, 'pret_vanzare_fara_tva' => 842.9752, 'pret_vanzare_cu_tva' => 1020.00, 'pret_intrare' => 123.1000],
            ['cod_fgo' => '00448266', 'cod_produs' => '13011-PWB1-900', 'denumire_engleza' => 'RING SET PISTON', 'descriere_romana' => 'Set segmenti', 'categorie' => 'Marfuri', 'um' => 'SET', 'stoc_initial' => 0, 'pret_vanzare_fara_tva' => 134.7107, 'pret_vanzare_cu_tva' => 163.00, 'pret_intrare' => 15.8200],
            ['cod_fgo' => '00445185', 'cod_produs' => '13101-KHE7-900', 'denumire_engleza' => 'PISTON', 'descriere_romana' => 'Piston 250cc', 'categorie' => 'Marfuri', 'um' => 'BUC', 'stoc_initial' => 1, 'pret_vanzare_fara_tva' => 107.4380, 'pret_vanzare_cu_tva' => 130.00, 'pret_intrare' => 12.6200],
        ];
    }
}
