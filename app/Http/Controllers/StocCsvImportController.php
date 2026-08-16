<?php

namespace App\Http\Controllers;

use App\Models\Gestiune;
use App\Models\Produs;
use App\Services\NecesarAprovizionareService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StocCsvImportController extends Controller
{
    public function store(Request $request, NecesarAprovizionareService $necesarAprovizionare): View
    {
        $request->validate([
            'fisier_stoc' => ['required', 'file', 'max:10240'],
        ]);

        $fisier = $request->file('fisier_stoc');
        if (mb_strtolower((string) $fisier->getClientOriginalExtension()) !== 'csv') {
            throw ValidationException::withMessages([
                'fisier_stoc' => 'Fișierul trebuie să fie CSV.',
            ]);
        }

        [$cantitatiCsv, $raportCitire] = $this->citesteCsv($fisier->getRealPath());

        if ($cantitatiCsv === []) {
            throw ValidationException::withMessages([
                'fisier_stoc' => 'Fișierul nu conține nicio poziție validă de stoc.',
            ]);
        }

        $gestiune = Gestiune::query()
            ->where('cod', 'FIRMA')
            ->whereHas('firma', fn (Builder $query) => $query->where('cod_fiscal', 'RO20548513'))
            ->sole();

        $produse = Produs::query()
            ->with(['solduriStoc' => fn ($query) => $query->where('gestiune_id', $gestiune->id)])
            ->get();

        $produsePeCod = $produse->groupBy(fn (Produs $produs): string => $this->normalizeazaCod($produs->cod_produs));
        $coduriCsv = array_fill_keys(array_keys($cantitatiCsv), true);

        $produseNoi = [];
        $coduriAmbigue = [];
        $actualizate = 0;
        $neschimbate = 0;
        $trecuteLaZero = 0;
        $produseAfectate = collect();

        DB::transaction(function () use (
            $cantitatiCsv,
            $coduriCsv,
            $gestiune,
            $necesarAprovizionare,
            $produse,
            $produsePeCod,
            &$actualizate,
            &$coduriAmbigue,
            &$neschimbate,
            &$produseAfectate,
            &$produseNoi,
            &$trecuteLaZero,
        ): void {
            foreach ($cantitatiCsv as $cod => $cantitate) {
                /** @var Collection<int, Produs>|null $potriviri */
                $potriviri = $produsePeCod->get($cod);

                if ($potriviri === null || $potriviri->isEmpty()) {
                    $produseNoi[] = ['cod' => $cod, 'cantitate' => $cantitate];
                    continue;
                }

                if ($potriviri->count() !== 1) {
                    $coduriAmbigue[] = [
                        'cod' => $cod,
                        'cantitate' => $cantitate,
                        'produse_gasite' => $potriviri->count(),
                    ];
                    continue;
                }

                $produs = $potriviri->first();
                $sold = $produs->solduriStoc->first();
                $stocCurent = (int) ($sold?->cantitate_fizica ?? 0);

                if ($stocCurent === $cantitate) {
                    $neschimbate++;
                    continue;
                }

                DB::table('solduri_stoc')->updateOrInsert(
                    ['gestiune_id' => $gestiune->id, 'produs_id' => $produs->id],
                    [
                        'cantitate_fizica' => $cantitate,
                        'cantitate_rezervata' => (int) ($sold?->cantitate_rezervata ?? 0),
                        'updated_at' => now(),
                    ],
                );

                $actualizate++;
                $produseAfectate->push($produs);
            }

            foreach ($produse as $produs) {
                $cod = $this->normalizeazaCod($produs->cod_produs);
                if (isset($coduriCsv[$cod])) {
                    continue;
                }

                $sold = $produs->solduriStoc->first();
                $stocCurent = (int) ($sold?->cantitate_fizica ?? 0);
                if ($stocCurent === 0) {
                    continue;
                }

                DB::table('solduri_stoc')->updateOrInsert(
                    ['gestiune_id' => $gestiune->id, 'produs_id' => $produs->id],
                    [
                        'cantitate_fizica' => 0,
                        'cantitate_rezervata' => (int) ($sold?->cantitate_rezervata ?? 0),
                        'updated_at' => now(),
                    ],
                );

                $trecuteLaZero++;
                $produseAfectate->push($produs);
            }

            $produseAfectate->unique('id')->each(
                fn (Produs $produs) => $necesarAprovizionare->sincronizeaza($produs, $gestiune),
            );
        });

        $raport = [
            'fisier' => $fisier->getClientOriginalName(),
            'randuri_date' => $raportCitire['randuri_date'],
            'coduri_valide' => count($cantitatiCsv),
            'actualizate' => $actualizate,
            'neschimbate' => $neschimbate,
            'trecute_la_zero' => $trecuteLaZero,
            'produse_noi' => $produseNoi,
            'coduri_ambigue' => $coduriAmbigue,
            'duplicate_csv' => $raportCitire['duplicate_csv'],
            'randuri_invalide' => $raportCitire['randuri_invalide'],
        ];

        return view('produse.raport-import-stoc', compact('raport'));
    }

    /**
     * @return array{0: array<string, int>, 1: array{randuri_date:int, duplicate_csv:array<int, array{cod:string, linie:int}>, randuri_invalide:array<int, array{linie:int, motiv:string}>}}
     */
    private function citesteCsv(string $cale): array
    {
        $handle = fopen($cale, 'rb');
        if ($handle === false) {
            throw ValidationException::withMessages(['fisier_stoc' => 'Fișierul nu poate fi citit.']);
        }

        $primaLinie = fgets($handle);
        rewind($handle);
        $delimiter = $this->detecteazaDelimiter($primaLinie ?: '');

        $cantitati = [];
        $duplicateCsv = [];
        $coduriDuplicate = [];
        $randuriInvalide = [];
        $randuriDate = 0;
        $linie = 0;

        while (($rand = fgetcsv($handle, 0, $delimiter)) !== false) {
            $linie++;
            if ($rand === [null] || $rand === [] || trim((string) ($rand[0] ?? '')) === '') {
                continue;
            }

            $codBrut = $this->curataBom(trim((string) ($rand[0] ?? '')));
            $cantitateBruta = trim((string) ($rand[1] ?? ''));

            if ($linie === 1 && in_array(mb_strtolower($codBrut), ['cod', 'code', 'cod produs', 'sku'], true)) {
                continue;
            }

            $randuriDate++;
            $cod = $this->normalizeazaCod($codBrut);

            if ($cod === '') {
                $randuriInvalide[] = ['linie' => $linie, 'motiv' => 'Cod produs lipsă.'];
                continue;
            }

            $cantitateNormalizata = str_replace(',', '.', $cantitateBruta);
            if (! is_numeric($cantitateNormalizata)) {
                $randuriInvalide[] = ['linie' => $linie, 'motiv' => "Cantitate invalidă pentru {$cod}."];
                continue;
            }

            $cantitateNumerica = (float) $cantitateNormalizata;
            if (floor($cantitateNumerica) !== $cantitateNumerica) {
                $randuriInvalide[] = ['linie' => $linie, 'motiv' => "Cantitatea pentru {$cod} trebuie să fie număr întreg."];
                continue;
            }

            $cantitate = max(0, (int) $cantitateNumerica);

            if (isset($coduriDuplicate[$cod])) {
                continue;
            }

            if (array_key_exists($cod, $cantitati)) {
                unset($cantitati[$cod]);
                $coduriDuplicate[$cod] = true;
                $duplicateCsv[] = ['cod' => $cod, 'linie' => $linie];
                continue;
            }

            $cantitati[$cod] = $cantitate;
        }

        fclose($handle);

        return [$cantitati, [
            'randuri_date' => $randuriDate,
            'duplicate_csv' => $duplicateCsv,
            'randuri_invalide' => $randuriInvalide,
        ]];
    }

    private function detecteazaDelimiter(string $linie): string
    {
        $candidate = [',' => substr_count($linie, ','), ';' => substr_count($linie, ';'), "\t" => substr_count($linie, "\t")];
        arsort($candidate);

        return (string) array_key_first($candidate);
    }

    private function normalizeazaCod(string $cod): string
    {
        return mb_strtoupper(trim($this->curataBom($cod)));
    }

    private function curataBom(string $valoare): string
    {
        return preg_replace('/^\xEF\xBB\xBF/', '', $valoare) ?? $valoare;
    }
}
