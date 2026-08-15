<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

class ReplaceProductionCatalog extends Command
{
    private const EXPECTED_PRODUCTS = 5298;

    private const EXPECTED_LOGICAL_HASH = 'd6274a175e10de3b8b04b09f4a0e2f83b150c50ca5118232ecf88ff01dd846dc';

    private const CONFIRMATION = 'STERGE-TESTELE-SI-IMPORTA-5298';

    /** @var array<int, string> */
    private const DATA_TABLES = [
        'jurnal_audit',
        'exporturi_fgo_stoc_linii',
        'exporturi_fgo_stoc',
        'exporturi_saga',
        'miscari_stoc',
        'receptii_linii',
        'receptii',
        'facturi_furnizor_linii',
        'facturi_furnizor',
        'importuri_fisiere',
        'solduri_stoc',
        'produse_furnizori',
        'produse',
    ];

    /** @var array<int, string> */
    private const REQUIRED_MIGRATIONS = [
        '001_initial_schema',
        '002_extindere_interval_cod_fgo',
        '003_pret_achizitie_4_zecimale',
        '004_cantitati_intregi',
        '005_tip_document_storno',
        '006_necesar_aprovizionare',
        '007_furnizori_si_linii_cost',
        '008_cod_produs_neunic',
    ];

    protected $signature = 'catalog:replace-production
        {--dry-run : Verifică fișierul și schema fără să modifice baza}
        {--confirm= : Confirmarea exactă pentru operația distructivă}';

    protected $description = 'Înlocuiește datele de test cu catalogul FGO îmbunătățit verificat';

    public function handle(): int
    {
        try {
            $this->assertTargetDatabase();
            $this->assertSchemaReady();
            $products = $this->readAndValidateCatalog();

            $this->info(sprintf(
                'Catalog valid: %d produse, %d coduri FGO unice.',
                count($products),
                count(array_unique(array_column($products, 'cod_fgo'))),
            ));

            if ($this->option('dry-run')) {
                $this->info('Verificarea s-a încheiat fără modificarea bazei de date.');

                return self::SUCCESS;
            }

            if ($this->option('confirm') !== self::CONFIRMATION) {
                $this->error('Confirmarea lipsește sau este incorectă. Baza nu a fost modificată.');
                $this->line('Folosește --confirm='.self::CONFIRMATION);

                return self::FAILURE;
            }

            $backupPath = $this->createBackup();
            $this->replaceCatalog($products);

            $this->info('Catalogul online a fost înlocuit cu succes.');
            $this->line('Copie de siguranță: '.$backupPath);

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());
            $this->error('Operația a fost oprită.');

            return self::FAILURE;
        }
    }

    private function assertTargetDatabase(): void
    {
        if (app()->environment('testing')) {
            return;
        }

        $connection = DB::connection();
        $database = (string) $connection->getDatabaseName();
        if ($database !== 'piesekym_gestiune') {
            throw new RuntimeException("Baza conectată este {$database}; operația permite numai piesekym_gestiune.");
        }
    }

    private function assertSchemaReady(): void
    {
        foreach (self::DATA_TABLES as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException("Lipsește tabela obligatorie {$table}.");
            }
        }
        foreach (['firme', 'gestiuni', 'categorii', 'unitati_masura', 'schema_migrations'] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException("Lipsește tabela obligatorie {$table}.");
            }
        }

        $applied = DB::table('schema_migrations')->pluck('versiune')->all();
        $missing = array_values(array_diff(self::REQUIRED_MIGRATIONS, $applied));
        if ($missing !== []) {
            throw new RuntimeException('Lipsesc migrările: '.implode(', ', $missing).'.');
        }

        if (! Schema::hasColumns('produse', ['cantitate_de_comandat', 'furnizor_comanda_id', 'furnizor_comanda_manual'])) {
            throw new RuntimeException('Tabela produse nu are structura necesară importului final.');
        }

        $firmCount = DB::table('firme')->where('cod_fiscal', 'RO20548513')->count();
        if ($firmCount !== 1) {
            throw new RuntimeException('Firma RO20548513 trebuie să existe exact o dată.');
        }
        $managementCount = DB::table('gestiuni')
            ->join('firme', 'firme.id', '=', 'gestiuni.firma_id')
            ->where('firme.cod_fiscal', 'RO20548513')
            ->where('gestiuni.cod', 'FIRMA')
            ->count();
        if ($managementCount !== 1) {
            throw new RuntimeException('Gestiunea FIRMA trebuie să existe exact o dată pentru RO20548513.');
        }
    }

    /** @return array<int, array<string, int|float|string|null>> */
    private function readAndValidateCatalog(): array
    {
        $path = database_path('data/baza_produse_imbunatatita.csv');
        if (! File::isFile($path)) {
            throw new RuntimeException('Fișierul catalogului lipsește: '.$path);
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Fișierul catalogului nu poate fi deschis.');
        }

        try {
            $expectedHeader = [
                'Cod FGO', 'Cod produs', 'Nume produs', 'Categorie', 'Categorie conta',
                'Stoc initial', 'UM', 'Pret vanzare fara TVA (RON)',
                'Pret vanzare cu TVA (RON)', 'Pret intrare', 'Moneda pret intrare',
                'TVA %', 'Descriere',
            ];
            $header = fgetcsv($handle);
            if ($header !== $expectedHeader) {
                throw new RuntimeException('Antetul catalogului nu corespunde versiunii verificate.');
            }

            $products = [];
            $fgoCodes = [];
            $logicalHash = hash_init('sha256');
            $lineNumber = 1;
            while (($row = fgetcsv($handle)) !== false) {
                $lineNumber++;
                if ($row === [null] || $row === []) {
                    continue;
                }
                if (count($row) !== count($expectedHeader)) {
                    throw new RuntimeException("Număr incorect de coloane la linia {$lineNumber}.");
                }
                hash_update($logicalHash, implode(chr(31), $row).chr(30));

                [$codFgo, $codProdus, $numeProdus, $categorie, $categorieConta, $stoc, $um,
                    $pretFaraTva, $pretCuTva, $pretIntrare, $monedaIntrare, $tva, $descriere] = $row;

                if (! preg_match('/^\d{8}$/', $codFgo)) {
                    throw new RuntimeException("Cod FGO invalid la linia {$lineNumber}.");
                }
                if (isset($fgoCodes[$codFgo])) {
                    throw new RuntimeException("Cod FGO duplicat: {$codFgo}.");
                }
                $fgoCodes[$codFgo] = true;
                if ($codProdus === '' || mb_strlen($codProdus) > 64) {
                    throw new RuntimeException("Cod produs invalid la linia {$lineNumber}.");
                }
                $prefix = $codProdus.' ';
                if (! str_starts_with($numeProdus, $prefix)) {
                    throw new RuntimeException("Numele produsului nu începe cu codul la linia {$lineNumber}.");
                }
                $englishName = trim(mb_substr($numeProdus, mb_strlen($prefix)));
                if ($englishName === '' || mb_strlen($englishName) > 255) {
                    throw new RuntimeException("Description of Goods invalidă la linia {$lineNumber}.");
                }

                $normalizedCategory = mb_strtolower(trim($categorie));
                $categoryName = match ($normalizedCategory) {
                    'marfuri' => 'Marfuri',
                    'pe comanda' => 'Pe comanda',
                    default => throw new RuntimeException("Categorie necunoscută la linia {$lineNumber}: {$categorie}."),
                };
                if ($categorieConta !== 'Marfuri gestiune 1 firma') {
                    throw new RuntimeException("Categorie contabilă invalidă la linia {$lineNumber}.");
                }
                if (! preg_match('/^\d+$/', $stoc)) {
                    throw new RuntimeException("Stoc neîntreg sau negativ la linia {$lineNumber}.");
                }
                if (! in_array($um, ['BUC', 'SET'], true)) {
                    throw new RuntimeException("Unitate de măsură invalidă la linia {$lineNumber}: {$um}.");
                }
                if (! is_numeric($pretFaraTva) || ! is_numeric($pretCuTva)) {
                    throw new RuntimeException("Preț de vânzare invalid la linia {$lineNumber}.");
                }
                if ($pretIntrare !== '') {
                    throw new RuntimeException("Prețul de intrare trebuie să fie gol la linia {$lineNumber}.");
                }
                if ($monedaIntrare !== 'EUR' || (float) $tva !== 21.0) {
                    throw new RuntimeException("Moneda sau TVA-ul este invalid la linia {$lineNumber}.");
                }

                $stock = (int) $stoc;
                $products[] = [
                    'cod_fgo' => $codFgo,
                    'cod_produs' => $codProdus,
                    'denumire_engleza' => $englishName,
                    'descriere_romana' => trim($descriere) !== '' ? trim($descriere) : null,
                    'categorie' => $categoryName,
                    'um' => $um,
                    'stoc' => $stock,
                    'pret_vanzare_fara_tva' => number_format((float) $pretFaraTva, 4, '.', ''),
                    'pret_vanzare_cu_tva' => number_format((float) $pretCuTva, 2, '.', ''),
                    'cantitate_de_comandat' => $stock < 1 ? 1 : 0,
                ];
            }
        } finally {
            fclose($handle);
        }

        if (count($products) !== self::EXPECTED_PRODUCTS) {
            throw new RuntimeException(sprintf(
                'Catalogul conține %d produse; erau așteptate %d.',
                count($products),
                self::EXPECTED_PRODUCTS,
            ));
        }
        if (hash_final($logicalHash) !== self::EXPECTED_LOGICAL_HASH) {
            throw new RuntimeException('Conținutul logic al catalogului diferă de versiunea verificată.');
        }

        return $products;
    }

    private function createBackup(): string
    {
        $directory = storage_path('app/private/backups');
        File::ensureDirectoryExists($directory);
        $path = $directory.'/inainte_catalog_'.now()->format('Ymd_His').'.jsonl';
        $handle = fopen($path, 'xb');
        if ($handle === false) {
            throw new RuntimeException('Copia de siguranță nu a putut fi creată.');
        }

        try {
            fwrite($handle, json_encode([
                'tip' => 'metadata',
                'creat_la' => now()->toIso8601String(),
                'baza' => DB::connection()->getDatabaseName(),
                'tabele' => self::DATA_TABLES,
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL);

            foreach (self::DATA_TABLES as $table) {
                foreach (DB::table($table)->cursor() as $row) {
                    fwrite($handle, json_encode([
                        'tabela' => $table,
                        'rand' => (array) $row,
                    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL);
                }
            }
        } finally {
            fclose($handle);
        }

        if (! File::isFile($path) || File::size($path) === 0) {
            throw new RuntimeException('Copia de siguranță este goală; operația a fost oprită.');
        }

        return $path;
    }

    /** @param array<int, array<string, int|float|string|null>> $products */
    private function replaceCatalog(array $products): void
    {
        DB::transaction(function () use ($products): void {
            foreach (self::DATA_TABLES as $table) {
                DB::table($table)->delete();
            }

            $categoryIds = collect(['Marfuri', 'Pe comanda'])->mapWithKeys(function (string $name): array {
                $id = DB::table('categorii')->where('denumire', $name)->value('id');
                if ($id === null) {
                    $id = DB::table('categorii')->insertGetId(['denumire' => $name, 'activa' => true]);
                }

                return [$name => (int) $id];
            });
            $unitIds = collect(['BUC' => 'Bucata', 'SET' => 'Set'])->mapWithKeys(function (string $name, string $code): array {
                $id = DB::table('unitati_masura')->where('cod', $code)->value('id');
                if ($id === null) {
                    $id = DB::table('unitati_masura')->insertGetId(['cod' => $code, 'denumire' => $name, 'activa' => true]);
                }

                return [$code => (int) $id];
            });
            $managementId = (int) DB::table('gestiuni')
                ->join('firme', 'firme.id', '=', 'gestiuni.firma_id')
                ->where('firme.cod_fiscal', 'RO20548513')
                ->where('gestiuni.cod', 'FIRMA')
                ->value('gestiuni.id');
            $timestamp = now();

            foreach (array_chunk($products, 500) as $chunk) {
                DB::table('produse')->insert(array_map(fn (array $product): array => [
                    'cod_fgo' => $product['cod_fgo'],
                    'cod_produs' => $product['cod_produs'],
                    'denumire_engleza' => $product['denumire_engleza'],
                    'descriere_romana' => $product['descriere_romana'],
                    'categorie_id' => $categoryIds[$product['categorie']],
                    'unitate_masura_id' => $unitIds[$product['um']],
                    'marca' => 'KYMCO',
                    'stoc_minim' => 1,
                    'cantitate_de_comandat' => $product['cantitate_de_comandat'],
                    'furnizor_comanda_id' => null,
                    'furnizor_comanda_manual' => false,
                    'pret_vanzare_fara_tva' => $product['pret_vanzare_fara_tva'],
                    'pret_vanzare_cu_tva' => $product['pret_vanzare_cu_tva'],
                    'cota_tva' => '21.00',
                    'greutate_kg' => null,
                    'voluminos' => false,
                    'lungime_cm' => null,
                    'latime_cm' => null,
                    'inaltime_cm' => null,
                    'activ' => true,
                    'sursa' => 'fgo_initial',
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ], $chunk));
            }

            $idsByFgo = DB::table('produse')->pluck('id', 'cod_fgo');
            foreach (array_chunk($products, 500) as $chunk) {
                DB::table('solduri_stoc')->insert(array_map(fn (array $product): array => [
                    'gestiune_id' => $managementId,
                    'produs_id' => $idsByFgo[$product['cod_fgo']],
                    'cantitate_fizica' => $product['stoc'],
                    'cantitate_rezervata' => 0,
                    'updated_at' => $timestamp,
                ], $chunk));
            }

            if (DB::table('produse')->count() !== self::EXPECTED_PRODUCTS
                || DB::table('produse')->distinct()->count('cod_fgo') !== self::EXPECTED_PRODUCTS
                || DB::table('solduri_stoc')->count() !== self::EXPECTED_PRODUCTS
                || DB::table('produse_furnizori')->count() !== 0) {
                throw new RuntimeException('Verificarea finală a importului a eșuat; tranzacția va fi anulată.');
            }
        }, 3);
    }
}
