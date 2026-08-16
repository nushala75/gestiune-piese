<?php

namespace App\Http\Controllers;

use App\Models\ExportSaga;
use App\Models\FacturaFurnizor;
use App\Services\SagaArticlesXmlExporter;
use App\Services\SagaInvoiceXmlExporter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class SagaExportController extends Controller
{
    public function generateArticles(FacturaFurnizor $factura, SagaArticlesXmlExporter $exporter): RedirectResponse|StreamedResponse
    {
        try {
            $export = $exporter->export($factura);
            $path = 'exporturi/saga/'.$factura->id.'/articole/'.$export['filename'];
            Storage::disk('local')->put($path, $export['content']);

            $hash = hash('sha256', $export['content']);
            $record = ExportSaga::query()->firstOrCreate(
                ['hash_sha256' => $hash],
                [
                    'factura_id' => $factura->id,
                    'tip' => 'articole_xml',
                    'nume_fisier' => $export['filename'],
                    'cale_stocare' => $path,
                    'status' => 'generat',
                ],
            );

            if ($record->factura_id !== $factura->id) {
                return back()->withErrors(['saga' => 'Hash-ul XML de articole există deja pentru altă factură.']);
            }
        } catch (Throwable $exception) {
            report($exception);

            return back()->withErrors(['saga' => 'XML-ul de articole SAGA nu a putut fi generat: '.$exception->getMessage()]);
        }

        return Storage::disk('local')->download($path, $export['filename'], [
            'Content-Type' => 'application/xml; charset=UTF-8',
        ]);
    }

    public function generateInvoice(FacturaFurnizor $factura, SagaInvoiceXmlExporter $exporter): RedirectResponse|StreamedResponse
    {
        if (! $factura->exporturiSaga()->where('tip', 'articole_xml')->exists()) {
            return back()->withErrors([
                'saga' => 'Generează și importă mai întâi XML-ul de articole în SAGA, apoi generează factura.',
            ]);
        }

        try {
            $export = $exporter->export($factura);
            $path = 'exporturi/saga/'.$factura->id.'/factura/'.$export['filename'];
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

            return back()->withErrors(['saga' => 'XML-ul facturii SAGA nu a putut fi generat: '.$exception->getMessage()]);
        }

        return Storage::disk('local')->download($path, $export['filename'], [
            'Content-Type' => 'application/xml; charset=UTF-8',
        ]);
    }
}
