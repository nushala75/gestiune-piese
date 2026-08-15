<?php

namespace App\Services;

use App\Models\FacturaFurnizor;
use DOMDocument;
use DOMElement;
use RuntimeException;

class SagaInvoiceXmlExporter
{
    /** @return array{filename:string, content:string} */
    public function export(FacturaFurnizor $factura): array
    {
        $factura->loadMissing(['furnizor', 'linii.produs.unitateMasura']);

        if ($factura->furnizor === null) {
            throw new RuntimeException('Factura nu are furnizor asociat.');
        }

        if ($factura->linii->isEmpty()) {
            throw new RuntimeException('Factura nu are poziții de exportat în SAGA.');
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        $document->formatOutput = true;

        $facturi = $document->appendChild($document->createElement('Facturi'));
        $facturaNode = $facturi->appendChild($document->createElement('Factura'));
        $antet = $facturaNode->appendChild($document->createElement('Antet'));

        $this->node($document, $antet, 'FurnizorNume', $factura->furnizor->denumire);
        $this->node($document, $antet, 'FurnizorCIF', (string) $factura->furnizor->cod_fiscal);
        $this->node($document, $antet, 'FurnizorTara', (string) ($factura->furnizor->tara ?? ''));

        $this->node($document, $antet, 'ClientNume', 'DESIGN MEDIA BUSINESS SRL');
        $this->node($document, $antet, 'ClientCIF', 'RO20548513');
        $this->node($document, $antet, 'ClientJudet', 'IF');
        $this->node($document, $antet, 'ClientTara', 'RO');
        $this->node($document, $antet, 'ClientLocalitate', 'Cornetu');
        $this->node($document, $antet, 'ClientAdresa', 'Salcamilor 26 O, Cornetu, Ilfov, 077070');

        $this->node($document, $antet, 'FacturaNumar', $factura->numar_original);
        $this->node($document, $antet, 'FacturaData', $factura->data_factura->format('d.m.Y'));
        $this->node(
            $document,
            $antet,
            'FacturaScadenta',
            ($factura->data_scadenta ?? $factura->data_factura)->format('d.m.Y'),
        );
        $this->node($document, $antet, 'FacturaTaxareInversa', $factura->taxare_inversa ? 'Da' : 'Nu');
        $this->node($document, $antet, 'FacturaTVAIncasare', 'Nu');
        if ($factura->taxare_inversa) {
            $this->node($document, $antet, 'FacturaTip', 'T');
        }
        $this->node($document, $antet, 'FacturaInformatiiSuplimentare', 'Generat din Gestiune Piese Kymco');
        if ($factura->moneda !== 'RON') {
            $this->node($document, $antet, 'FacturaMoneda', $factura->moneda);
        }

        $detalii = $facturaNode->appendChild($document->createElement('Detalii'));
        $continut = $detalii->appendChild($document->createElement('Continut'));

        foreach ($factura->linii->sortBy('numar_linie') as $linie) {
            $linieNode = $continut->appendChild($document->createElement('Linie'));
            $this->node($document, $linieNode, 'LinieNrCrt', (string) $linie->numar_linie);

            if (($linie->tip_linie ?? 'produs') === 'produs') {
                $this->node($document, $linieNode, 'Gestiune', 'FIRMA');
            }

            $this->node($document, $linieNode, 'Descriere', $linie->descriere_originala);

            if (($linie->tip_linie ?? 'produs') === 'produs') {
                $codProdus = $linie->produs?->cod_produs ?: $linie->cod_furnizor;
                $this->node($document, $linieNode, 'CodArticolFurnizor', $codProdus);

                if ($linie->produs?->cod_fgo) {
                    $this->node($document, $linieNode, 'GUID_cod_articol', $linie->produs->cod_fgo);
                }
            }

            $this->node($document, $linieNode, 'UM', $linie->produs?->unitateMasura?->cod ?? 'BUC');
            $this->node($document, $linieNode, 'Cantitate', (string) $linie->cantitate);
            $this->node($document, $linieNode, 'Pret', number_format((float) $linie->pret_unitar_calculat, 4, '.', ''));
            $this->node($document, $linieNode, 'Valoare', number_format((float) $linie->amount_sursa, 2, '.', ''));
            $this->node($document, $linieNode, 'ProcTVA', number_format((float) ($linie->produs?->cota_tva ?? 21), 0, '.', ''));
            $this->node($document, $linieNode, 'TVA', number_format((float) $linie->valoare_tva, 2, '.', ''));
        }

        $this->node($document, $facturaNode, 'FacturaID', 'gestiune-piese-'.$factura->id);

        $content = $document->saveXML();
        if ($content === false) {
            throw new RuntimeException('XML-ul SAGA nu a putut fi generat.');
        }

        $supplierFiscal = preg_replace('/[^A-Za-z0-9_-]/', '', (string) $factura->furnizor->cod_fiscal) ?: 'FURNIZOR';
        $invoiceNumber = preg_replace('/[^A-Za-z0-9_-]/', '-', $factura->numar_original) ?: 'FACTURA';
        $filename = sprintf(
            'F_%s_%s_%s.xml',
            $supplierFiscal,
            $invoiceNumber,
            $factura->data_factura->format('d.m.Y'),
        );

        return ['filename' => $filename, 'content' => $content];
    }

    private function node(DOMDocument $document, DOMElement $parent, string $name, string $value): void
    {
        $element = $document->createElement($name);
        $element->appendChild($document->createTextNode($value));
        $parent->appendChild($element);
    }
}
