<?php

namespace App\Http\Controllers;

use App\Models\Gestiune;
use App\Models\Produs;
use App\Services\ProductRegisterSynchronizer;
use App\Services\StockRegisterExchangeRate;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class StockRegisterLocalUpdateController extends Controller
{
    public function __invoke(
        Request $request,
        ProductRegisterSynchronizer $synchronizer,
        StockRegisterExchangeRate $exchangeRateState,
    ): BinaryFileResponse {
        $data = $request->validate([
            'registru' => ['required', 'file', 'extensions:xlsx', 'max:20480'],
            'exchange_rate' => ['required', 'string', 'regex:/^\d+(?:[\.,]\d{1,4})?$/'],
        ], [
            'registru.required' => 'Alege registrul Excel local care trebuie actualizat.',
            'registru.extensions' => 'Fișierul selectat trebuie să fie în format .xlsx.',
            'exchange_rate.regex' => 'Cursul EUR/RON trebuie să fie un număr pozitiv cu maximum 4 zecimale.',
        ]);

        $exchangeRate = str_replace(',', '.', trim($data['exchange_rate']));
        if (BigDecimal::of($exchangeRate)->isLessThanOrEqualTo(0)) {
            throw ValidationException::withMessages([
                'exchange_rate' => 'Cursul EUR/RON trebuie să fie mai mare decât zero.',
            ]);
        }

        $products = Produs::query()->orderBy('id')->get();
        $duplicateCodes = $products
            ->groupBy(fn (Produs $product): string => mb_strtoupper(trim($product->cod_produs)))
            ->filter(fn ($matches): bool => $matches->count() > 1)
            ->keys()
            ->values();
        if ($duplicateCodes->isNotEmpty()) {
            throw ValidationException::withMessages([
                'registru' => 'Actualizarea este blocată: următoarele coduri corespund mai multor produse din aplicație: '
                    .$duplicateCodes->take(20)->implode(', ')
                    .($duplicateCodes->count() > 20 ? ' și încă '.($duplicateCodes->count() - 20) : '')
                    .'.',
            ]);
        }

        $file = $request->file('registru');
        $token = (string) Str::uuid();
        $temporaryPath = "exporturi/registru-local/{$token}.xlsx";
        if (! Storage::disk('local')->putFileAs('exporturi/registru-local', $file, "{$token}.xlsx")) {
            throw ValidationException::withMessages([
                'registru' => 'Registrul local nu a putut fi copiat pentru actualizare.',
            ]);
        }

        $absolutePath = Storage::disk('local')->path($temporaryPath);
        try {
            $exchangeRateState->remember($exchangeRate);
            $updated = $synchronizer->updateFile($absolutePath, $products, $this->companyWarehouse());
            if ($updated === 0) {
                throw ValidationException::withMessages([
                    'registru' => 'Niciun cod din registrul ales nu există în catalogul aplicației.',
                ]);
            }

            return response()->download(
                $absolutePath,
                $file->getClientOriginalName(),
                [
                    'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    'X-Kymco-Updated-Products' => (string) $updated,
                ],
            )->deleteFileAfterSend(true);
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($temporaryPath);

            throw $exception;
        }
    }

    private function companyWarehouse(): Gestiune
    {
        return Gestiune::query()
            ->where('cod', 'FIRMA')
            ->whereHas('firma', fn (Builder $query) => $query->where('cod_fiscal', 'RO20548513'))
            ->sole();
    }
}
