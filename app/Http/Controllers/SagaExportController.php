<?php

namespace App\Http\Controllers;

use App\Models\ExportSaga;
use App\Models\FacturaFurnizor;
use App\Services\SagaInvoiceXmlExporter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class SagaExportController extends Controller
{
    public function generate(FacturaFurnizor $factura, SagaInvoiceXmlExporter $exporter): RedirectResponse
    {
        try {
            $export = $exporter->export($factura);
            $path = 'exporturi/saga/'.$export['filename'];
            Storage::disk('local')->put($path, $export['content']);

            $hash = hash('sha256', $export['content']);
            $record = ExportSaga::query()->firstOrCreate(
                ['hash_sha256' => $hash],
                [
                    'factura_id' => $factura->id,
                    'tip' => 'factura_intrare_xml',
                    'nume_fisier' => $export['filename'],
                    'cale_stocare' => $path,
                    'status' => 'generat',
                ],
            );

            if ($record->factura_id !== $factura->id) {
                return back()->withErrors(['saga' => 'Hash-ul XML există deja pentru altă factură.']);
            }
        } catch (Throwable $exception) {
            report($exception);

            return back()->withErrors(['saga' => 'XML-ul SAGA nu a putut fi generat: '.$exception->getMessage()]);
        }

        return redirect()->route('facturi-furnizori.show', $factura)
            ->with('status', 'XML-ul pentru SAGA a fost generat și salvat pe server.');
    }

    public function download(FacturaFurnizor $factura, ExportSaga $export): StreamedResponse
    {
        abort_unless($export->factura_id === $factura->id, 404);
        abort_unless(Storage::disk('local')->exists($export->cale_stocare), 404);

        return Storage::disk('local')->download($export->cale_stocare, $export->nume_fisier, [
            'Content-Type' => 'application/xml; charset=UTF-8',
        ]);
    }
}
