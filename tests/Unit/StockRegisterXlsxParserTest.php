<?php

namespace Tests\Unit;

use App\Services\StockRegisterXlsxParser;
use PharData;
use PHPUnit\Framework\TestCase;

class StockRegisterXlsxParserTest extends TestCase
{
    public function test_it_reads_stock_and_cached_vat_price_from_the_expected_columns(): void
    {
        $path = $this->makeWorkbook();

        try {
            $result = (new StockRegisterXlsxParser)->parse($path);
        } finally {
            @unlink($path);
        }

        $this->assertSame('Produse', $result['sheet']);
        $this->assertSame([
            ['row' => 2, 'code' => 'ABC-1', 'stock' => -1, 'price_with_vat' => '10.5'],
        ], $result['rows']);
    }

    private function makeWorkbook(): string
    {
        $base = sys_get_temp_dir().'/kymco-parser-'.bin2hex(random_bytes(6));
        $zipPath = $base.'.zip';
        $xlsxPath = $base.'.xlsx';
        $archive = new PharData($zipPath);
        $archive->addFromString('xl/workbook.xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Produse" sheetId="1" r:id="rId1"/></sheets></workbook>
XML);
        $archive->addFromString('xl/_rels/workbook.xml.rels', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>
XML);
        $archive->addFromString('xl/worksheets/sheet1.xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1"><c r="A1" t="inlineStr"><is><t>Cod - Referinta Prestashop</t></is></c><c r="B1" t="inlineStr"><is><t>Stoc local</t></is></c><c r="C1" t="inlineStr"><is><t>Preț cu TVA</t></is></c></row><row r="2"><c r="A2" t="inlineStr"><is><t>abc-1</t></is></c><c r="B2"><v>-1</v></c><c r="C2"><f>9.5+1</f><v>10.5</v></c></row></sheetData></worksheet>
XML);
        unset($archive);
        rename($zipPath, $xlsxPath);

        return $xlsxPath;
    }
}
