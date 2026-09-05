<?php

namespace App\Services;

use DOMDocument;
use DOMXPath;
use PharData;
use RuntimeException;
use Throwable;

class StockRegisterXlsxParser
{
    private const SPREADSHEET_NAMESPACE = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    private const RELATIONSHIP_NAMESPACE = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    /**
     * @return array{sheet: string, rows: list<array{row: int, code: string, stock: int, english_name: string, reorder_quantity: int, weight_kg: string, price_with_vat_eur: string, romanian_name: string}>}
     */
    public function parse(string $filePath): array
    {
        if (! is_file($filePath)) {
            throw new RuntimeException('Fișierul Excel nu există.');
        }

        $temporaryBase = tempnam(sys_get_temp_dir(), 'kymco-stock-');
        if ($temporaryBase === false) {
            throw new RuntimeException('Nu a putut fi creat fișierul temporar pentru citirea registrului.');
        }

        $temporaryZip = $temporaryBase.'.zip';
        @unlink($temporaryBase);
        if (! copy($filePath, $temporaryZip)) {
            throw new RuntimeException('Registrul nu a putut fi pregătit pentru citire.');
        }

        try {
            $archive = new PharData($temporaryZip);
            $sheet = $this->findProductsSheet($archive);
            $sharedStrings = $this->readSharedStrings($archive);
            $rows = $this->readRows($archive, $sheet['path'], $sharedStrings);

            return [
                'sheet' => $sheet['name'],
                'rows' => $this->extractStockRows($rows),
            ];
        } catch (RuntimeException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new RuntimeException('Fișierul nu este un registru XLSX valid: '.$exception->getMessage(), 0, $exception);
        } finally {
            @unlink($temporaryZip);
        }
    }

    /** @return array{name: string, path: string} */
    private function findProductsSheet(PharData $archive): array
    {
        $workbook = $this->xml($this->entry($archive, 'xl/workbook.xml'));
        $relations = $this->xml($this->entry($archive, 'xl/_rels/workbook.xml.rels'));
        $workbookXPath = new DOMXPath($workbook);
        $workbookXPath->registerNamespace('s', self::SPREADSHEET_NAMESPACE);
        $workbookXPath->registerNamespace('r', self::RELATIONSHIP_NAMESPACE);

        $relationTargets = [];
        foreach ($relations->getElementsByTagName('Relationship') as $relation) {
            $relationTargets[$relation->getAttribute('Id')] = $relation->getAttribute('Target');
        }

        $fallback = null;
        foreach ($workbookXPath->query('//s:sheets/s:sheet') ?: [] as $node) {
            $name = trim($node->getAttribute('name'));
            $relationId = $node->getAttributeNS(self::RELATIONSHIP_NAMESPACE, 'id');
            $target = $relationTargets[$relationId] ?? '';
            $path = $this->normalizeWorksheetPath($target);
            $candidate = ['name' => $name, 'path' => $path];
            $fallback ??= $candidate;

            if (mb_strtolower($name) === 'produse') {
                return $candidate;
            }
        }

        if ($fallback !== null) {
            return $fallback;
        }

        throw new RuntimeException('Registrul nu conține nicio foaie de calcul.');
    }

    private function normalizeWorksheetPath(string $target): string
    {
        $target = str_replace('\\', '/', trim($target));
        $path = str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/'.$target;
        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($parts);

                continue;
            }
            $parts[] = $part;
        }
        $normalized = implode('/', $parts);
        if (! str_starts_with($normalized, 'xl/worksheets/')) {
            throw new RuntimeException('Calea foii din registru este invalidă.');
        }

        return $normalized;
    }

    /** @return list<string> */
    private function readSharedStrings(PharData $archive): array
    {
        if (! isset($archive['xl/sharedStrings.xml'])) {
            return [];
        }

        $document = $this->xml($this->entry($archive, 'xl/sharedStrings.xml'));
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('s', self::SPREADSHEET_NAMESPACE);
        $strings = [];
        foreach ($xpath->query('//s:si') ?: [] as $item) {
            $parts = [];
            foreach ($xpath->query('.//s:t', $item) ?: [] as $textNode) {
                $parts[] = $textNode->textContent;
            }
            $strings[] = implode('', $parts);
        }

        return $strings;
    }

    /**
     * @param  list<string>  $sharedStrings
     * @return array<int, array<int, string>>
     */
    private function readRows(PharData $archive, string $sheetPath, array $sharedStrings): array
    {
        $document = $this->xml($this->entry($archive, $sheetPath));
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('s', self::SPREADSHEET_NAMESPACE);
        $rows = [];

        foreach ($xpath->query('//s:sheetData/s:row') ?: [] as $rowNode) {
            $rowNumber = (int) $rowNode->getAttribute('r');
            foreach ($xpath->query('./s:c', $rowNode) ?: [] as $cell) {
                if (! preg_match('/^([A-Z]+)(\d+)$/i', $cell->getAttribute('r'), $matches)) {
                    continue;
                }
                $column = $this->columnNumber(mb_strtoupper($matches[1]));
                $type = $cell->getAttribute('t');
                if ($type === 'inlineStr') {
                    $value = $xpath->evaluate('string(s:is)', $cell);
                } else {
                    $raw = $xpath->evaluate('string(s:v)', $cell);
                    $value = $type === 's' ? ($sharedStrings[(int) $raw] ?? '') : $raw;
                }
                $rows[$rowNumber][$column] = trim((string) $value);
            }
        }

        return $rows;
    }

    /**
     * @param  array<int, array<int, string>>  $rows
     * @return list<array{row: int, code: string, stock: int, english_name: string, reorder_quantity: int, weight_kg: string, price_with_vat_eur: string, romanian_name: string}>
     */
    private function extractStockRows(array $rows): array
    {
        $headers = $rows[1] ?? [];
        $normalizedHeaders = array_map($this->normalizeHeader(...), $headers);
        $columns = [];
        foreach ([
            'code' => 'cod - referinta prestashop',
            'stock' => 'stoc local',
            'english_name' => 'nume prestashop',
            'reorder_quantity' => 'nr. produse de comandat',
            'weight_kg' => 'greutate (kg)',
            'price_with_vat_eur' => 'preț cu tva',
            'romanian_name' => 'nume ro',
        ] as $key => $header) {
            $column = array_search($header, $normalizedHeaders, true);
            if ($column === false) {
                throw new RuntimeException("Foaia nu conține coloana obligatorie „{$header}”.");
            }
            $columns[$key] = $column;
        }

        $result = [];
        $seen = [];
        $errors = [];
        foreach ($rows as $rowNumber => $cells) {
            if ($rowNumber === 1) {
                continue;
            }
            $rawCode = trim($cells[$columns['code']] ?? '');
            $rawStock = trim($cells[$columns['stock']] ?? '');
            $englishName = trim($cells[$columns['english_name']] ?? '');
            $rawReorder = trim($cells[$columns['reorder_quantity']] ?? '');
            $rawWeight = trim($cells[$columns['weight_kg']] ?? '');
            $rawPrice = trim($cells[$columns['price_with_vat_eur']] ?? '');
            $romanianName = trim($cells[$columns['romanian_name']] ?? '');
            if ($rawCode === '' && $rawStock === '' && $englishName === '' && $rawReorder === '' && $rawWeight === '' && $rawPrice === '' && $romanianName === '') {
                continue;
            }
            if ($rawCode === '') {
                $errors[] = "rândul {$rowNumber}: cod lipsă";

                continue;
            }
            if (! preg_match('/^[+-]?\d+(?:\.0+)?$/', $rawStock)) {
                $errors[] = "rândul {$rowNumber}: stocul pentru {$rawCode} nu este număr întreg";

                continue;
            }
            if ($englishName === '') {
                $errors[] = "rândul {$rowNumber}: Nume PrestaShop lipsește pentru {$rawCode}";

                continue;
            }
            if ($rawReorder !== '' && ! preg_match('/^\d+(?:\.0+)?$/', $rawReorder)) {
                $errors[] = "rândul {$rowNumber}: numărul de produse de comandat pentru {$rawCode} nu este valid";

                continue;
            }
            if (! preg_match('/^\d+(?:\.\d+)?$/', $rawWeight)) {
                $errors[] = "rândul {$rowNumber}: greutatea pentru {$rawCode} nu este validă";

                continue;
            }
            if (! preg_match('/^\d+(?:\.\d+)?$/', $rawPrice)) {
                $errors[] = "rândul {$rowNumber}: prețul cu TVA pentru {$rawCode} nu este valid";

                continue;
            }
            if ($romanianName === '') {
                $errors[] = "rândul {$rowNumber}: Nume RO lipsește pentru {$rawCode}";

                continue;
            }

            $code = mb_strtoupper($rawCode);
            if (isset($seen[$code])) {
                $errors[] = "rândurile {$seen[$code]} și {$rowNumber}: cod duplicat {$code}";

                continue;
            }
            $seen[$code] = $rowNumber;
            $result[] = [
                'row' => $rowNumber,
                'code' => $code,
                'stock' => max(0, (int) (float) $rawStock),
                'english_name' => $englishName,
                'reorder_quantity' => $rawReorder === '' ? 0 : (int) (float) $rawReorder,
                'weight_kg' => ltrim($rawWeight, '+'),
                'price_with_vat_eur' => ltrim($rawPrice, '+'),
                'romanian_name' => $romanianName,
            ];
        }

        if ($errors !== []) {
            $visible = array_slice($errors, 0, 10);
            $suffix = count($errors) > 10 ? ' Mai există '.(count($errors) - 10).' erori.' : '';
            throw new RuntimeException('Registrul conține erori: '.implode('; ', $visible).'.'.$suffix);
        }
        if ($result === []) {
            throw new RuntimeException('Registrul nu conține poziții de stoc.');
        }

        return $result;
    }

    private function normalizeHeader(string $header): string
    {
        return mb_strtolower(preg_replace('/\s+/u', ' ', trim($header)) ?? trim($header));
    }

    private function columnNumber(string $letters): int
    {
        $number = 0;
        foreach (str_split($letters) as $letter) {
            $number = ($number * 26) + ord($letter) - 64;
        }

        return $number;
    }

    private function entry(PharData $archive, string $path): string
    {
        if (! isset($archive[$path])) {
            throw new RuntimeException("Registrul nu conține componenta necesară {$path}.");
        }

        return $archive[$path]->getContent();
    }

    private function xml(string $contents): DOMDocument
    {
        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument;
        $loaded = $document->loadXML($contents, LIBXML_NONET | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (! $loaded) {
            throw new RuntimeException('Registrul conține XML invalid.');
        }

        return $document;
    }
}
