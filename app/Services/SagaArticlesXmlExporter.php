<?php

namespace App\Services;

use App\Models\FacturaFurnizor;
use DOMDocument;
use DOMElement;
use RuntimeException;

class SagaArticlesXmlExporter
{
    /** @return array{filename:string, content:string, count:int} */
    public function export(FacturaFurnizor $factura): array
    {
        $factura->loadMissing(['linii.produs.unitateMasura']);

        $products = $factura->linii
            ->where('tip_linie', 'produs')
            ->pluck('produs')
            ->filter()
            ->unique('id')
            ->values();

        if ($products->isEmpty()) {
            throw new RuntimeException('Factura nu are produse mapate pentru exportul nomenclatorului SAGA.');
        }

        if ($factura->linii->where('tip_linie', 'produs')->contains(fn ($linie): bool => $linie->produs === null)) {
            throw new RuntimeException('Toate pozițiile de produs trebuie mapate înainte de exportul SAGA.');
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        $document->formatOutput = true;
        $articles = $document->appendChild($document->createElement('Articole'));

        foreach ($products as $product) {
            if (! $product->cod_fgo) {
                throw new RuntimeException("Produsul {$product->cod_produs} nu are cod FGO.");
            }

            $line = $articles->appendChild($document->createElement('Linie'));
            $this->node($document, $line, 'Cod', $product->cod_produs);
            $this->node($document, $line, 'Denumire', $product->denumire_engleza);
            $this->node($document, $line, 'UM', $product->unitateMasura?->cod ?? 'BUC');
            $this->node($document, $line, 'Tip', 'Marfuri');
            $this->node($document, $line, 'TVA', number_format((float) ($product->cota_tva ?? 21), 0, '.', ''));

            if ($product->pret_vanzare_fara_tva !== null) {
                $this->node($document, $line, 'Pret', number_format((float) $product->pret_vanzare_fara_tva, 4, '.', ''));
            }
            if ($product->pret_vanzare_cu_tva !== null) {
                $this->node($document, $line, 'Pret_TVA', number_format((float) $product->pret_vanzare_cu_tva, 2, '.', ''));
            }

            $this->node($document, $line, 'Informatii', 'Generat din Gestiune Piese Kymco pentru factura '.$factura->numar_original);
            $this->node($document, $line, 'Guid_cod', $product->cod_fgo);
        }

        $content = $document->saveXML();
        if ($content === false) {
            throw new RuntimeException('XML-ul de articole SAGA nu a putut fi generat.');
        }

        return [
            'filename' => 'ART_'.$factura->data_factura->format('d.m.Y').'.xml',
            'content' => $content,
            'count' => $products->count(),
        ];
    }

    private function node(DOMDocument $document, DOMElement $parent, string $name, string $value): void
    {
        $element = $document->createElement($name);
        $element->appendChild($document->createTextNode($value));
        $parent->appendChild($element);
    }
}
