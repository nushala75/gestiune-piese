<?php

namespace App\Services;

use App\Models\Gestiune;
use App\Models\Produs;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PharData;
use RuntimeException;
use Throwable;

class ProductRegisterSynchronizer
{
    private const SPREADSHEET_NAMESPACE = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    private const RELATIONSHIP_NAMESPACE = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    public function __construct(private readonly StockRegisterExchangeRate $exchangeRate) {}

    /**
     * @param  iterable<int, Produs>  $products
     * @param  array<int, string>  $lookupCodesByProductId
     */
    public function updateFile(
        string $path,
        iterable $products,
        ?Gestiune $warehouse = null,
        array $lookupCodesByProductId = [],
    ): int {
        $products = collect($products)->unique('id')->values();
        if ($products->isEmpty()) {
            return 0;
        }

        $warehouse ??= Gestiune::query()
            ->where('cod', 'FIRMA')
            ->whereHas('firma', fn ($query) => $query->where('cod_fiscal', 'RO20548513'))
            ->sole();

        $changes = $this->changes($products, $warehouse, $lookupCodesByProductId);
        if (! is_file($path)) {
            $this->fail('Fișierul Excel selectat nu există: '.$path, $changes);
        }

        $temporaryPath = dirname($path).DIRECTORY_SEPARATOR.'.'.basename($path).'.sync-'.bin2hex(random_bytes(6)).'.zip';
        if (! @copy($path, $temporaryPath)) {
            $this->fail('Registrul Excel nu a putut fi citit pentru sincronizare.', $changes);
        }

        try {
            $archive = new PharData($temporaryPath);
            $sheet = $this->findProductsSheet($archive);
            $sharedStrings = $this->readSharedStrings($archive);
            $document = $this->xml($this->entry($archive, $sheet['path']));
            $xpath = new DOMXPath($document);
            $xpath->registerNamespace('s', self::SPREADSHEET_NAMESPACE);
            $columns = $this->columns($xpath, $sharedStrings);
            $rowsByCode = $this->rowsByCode($xpath, $sharedStrings, $columns['code']);
            $matched = [];

            foreach ($changes as $change) {
                $lookupCode = $this->normalizeCode($change['lookup_code']);
                $currentCode = $this->normalizeCode($change['code']);
                $rows = $rowsByCode[$lookupCode] ?? $rowsByCode[$currentCode] ?? [];
                if (count($rows) > 1) {
                    $this->fail("Codul {$change['lookup_code']} apare pe mai multe rânduri în registrul Excel.", [$change]);
                }
                if ($rows === []) {
                    continue;
                }

                $row = $rows[0];
                $priceEur = $change['price_without_vat_ron'] === null
                    ? null
                    : $this->trimDecimal(BigDecimal::of($change['price_without_vat_ron'])
                        ->dividedBy($this->exchangeRate->current(), 6, RoundingMode::HalfUp)
                        ->__toString());

                $this->setTextCell($document, $xpath, $row, $columns['code'], $change['code']);
                $this->setNumberCell($document, $xpath, $row, $columns['stock'], (string) $change['stock']);
                $this->setTextCell($document, $xpath, $row, $columns['english_name'], $change['english_name']);
                $this->setNumberCell($document, $xpath, $row, $columns['reorder_quantity'], (string) $change['reorder_quantity']);
                $this->setNumberCell($document, $xpath, $row, $columns['weight_kg'], $change['weight_kg']);
                $this->setNumberCell($document, $xpath, $row, $columns['price_without_vat'], $priceEur);
                $this->setNumberCell($document, $xpath, $row, $columns['vat_rate'], $change['vat_rate']);
                $this->setTextCell($document, $xpath, $row, $columns['romanian_name'], $change['romanian_name']);
                $this->setFormulaCache(
                    $document,
                    $xpath,
                    $row,
                    $columns['price_with_vat'],
                    $priceEur === null
                        ? '0'
                        : $this->trimDecimal(BigDecimal::of($priceEur)
                            ->multipliedBy(BigDecimal::one()->plus(BigDecimal::of($change['vat_rate'])->dividedBy(100, 8, RoundingMode::HalfUp)))
                            ->toScale(8, RoundingMode::HalfUp)
                            ->__toString()),
                );
                $matched[] = $change;
            }

            if ($matched === []) {
                unset($archive);

                return 0;
            }

            $xml = $document->saveXML();
            if ($xml === false) {
                throw new RuntimeException('Foaia Produse nu a putut fi serializată.');
            }
            $archive[$sheet['path']] = $xml;
            unset($archive);

            $this->overwriteLocked($path, $temporaryPath, $matched);

            return count($matched);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $this->fail('Registrul Excel nu a putut fi actualizat: '.$exception->getMessage(), $changes);
        } finally {
            @unlink($temporaryPath);
        }
    }

    /** @param Collection<int, Produs> $products @param array<int, string> $lookupCodesByProductId */
    private function changes(Collection $products, Gestiune $warehouse, array $lookupCodesByProductId): array
    {
        $stocks = DB::table('solduri_stoc')
            ->where('gestiune_id', $warehouse->id)
            ->whereIn('produs_id', $products->pluck('id'))
            ->pluck('cantitate_fizica', 'produs_id');

        return $products->map(fn (Produs $product): array => [
            'product_id' => $product->id,
            'lookup_code' => $lookupCodesByProductId[$product->id] ?? $product->cod_produs,
            'code' => $product->cod_produs,
            'stock' => (int) ($stocks[$product->id] ?? 0),
            'english_name' => trim((string) $product->denumire_engleza),
            'reorder_quantity' => (int) $product->cantitate_de_comandat,
            'weight_kg' => $product->greutate_kg === null ? null : (string) $product->greutate_kg,
            'price_without_vat_ron' => $product->pret_vanzare_fara_tva === null ? null : (string) $product->pret_vanzare_fara_tva,
            'vat_rate' => (string) $product->cota_tva,
            'romanian_name' => trim((string) $product->descriere_romana),
        ])->all();
    }

    private function overwriteLocked(string $path, string $temporaryPath, array $changes): void
    {
        $target = @fopen($path, 'r+b');
        if ($target === false || ! @flock($target, LOCK_EX | LOCK_NB)) {
            if (is_resource($target)) {
                fclose($target);
            }
            $this->fail('Fișierul Excel este deschis sau blocat. Închide-l și repetă salvarea.', $changes);
        }

        $backupPath = $temporaryPath.'.backup';
        $source = null;
        try {
            if (! @copy($path, $backupPath)) {
                throw new RuntimeException('Nu a putut fi creată copia temporară de siguranță.');
            }
            $source = @fopen($temporaryPath, 'rb');
            if ($source === false || ! rewind($target) || ! ftruncate($target, 0)) {
                throw new RuntimeException('Fișierul Excel nu poate fi rescris.');
            }
            $written = stream_copy_to_stream($source, $target);
            if ($written === false || ! fflush($target)) {
                throw new RuntimeException('Scrierea registrului Excel nu s-a încheiat corect.');
            }
        } catch (Throwable $exception) {
            $backup = @fopen($backupPath, 'rb');
            if ($backup !== false) {
                rewind($target);
                ftruncate($target, 0);
                stream_copy_to_stream($backup, $target);
                fflush($target);
                fclose($backup);
            }
            throw $exception;
        } finally {
            if (is_resource($source)) {
                fclose($source);
            }
            @flock($target, LOCK_UN);
            fclose($target);
            @unlink($backupPath);
        }
    }

    private function findProductsSheet(PharData $archive): array
    {
        $workbook = $this->xml($this->entry($archive, 'xl/workbook.xml'));
        $relations = $this->xml($this->entry($archive, 'xl/_rels/workbook.xml.rels'));
        $xpath = new DOMXPath($workbook);
        $xpath->registerNamespace('s', self::SPREADSHEET_NAMESPACE);
        $targets = [];
        foreach ($relations->getElementsByTagName('Relationship') as $relation) {
            $targets[$relation->getAttribute('Id')] = $relation->getAttribute('Target');
        }
        foreach ($xpath->query('//s:sheets/s:sheet') ?: [] as $node) {
            if (mb_strtolower(trim($node->getAttribute('name'))) !== 'produse') {
                continue;
            }
            $relationId = $node->getAttributeNS(self::RELATIONSHIP_NAMESPACE, 'id');
            $target = str_replace('\\', '/', $targets[$relationId] ?? '');
            $path = str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/'.$target;
            $parts = [];
            foreach (explode('/', $path) as $part) {
                if ($part === '' || $part === '.') {
                    continue;
                }
                if ($part === '..') {
                    array_pop($parts);
                } else {
                    $parts[] = $part;
                }
            }

            return ['path' => implode('/', $parts)];
        }

        throw new RuntimeException('Registrul nu conține foaia Produse.');
    }

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
            foreach ($xpath->query('.//s:t', $item) ?: [] as $text) {
                $parts[] = $text->textContent;
            }
            $strings[] = implode('', $parts);
        }

        return $strings;
    }

    private function columns(DOMXPath $xpath, array $sharedStrings): array
    {
        $headers = [];
        foreach ($xpath->query('//s:sheetData/s:row[@r="1"]/s:c') ?: [] as $cell) {
            if (preg_match('/^([A-Z]+)1$/i', $cell->getAttribute('r'), $match)) {
                $headers[$this->normalizeHeader($this->cellValue($xpath, $cell, $sharedStrings))] = mb_strtoupper($match[1]);
            }
        }
        $required = [
            'code' => 'cod - referinta prestashop',
            'stock' => 'stoc local',
            'english_name' => 'nume prestashop',
            'reorder_quantity' => 'nr. produse de comandat',
            'weight_kg' => 'greutate (kg)',
            'price_without_vat' => 'preț fără tva',
            'price_with_vat' => 'preț cu tva',
            'vat_rate' => 'cota tva',
            'romanian_name' => 'nume ro',
        ];
        $columns = [];
        foreach ($required as $key => $header) {
            if (! isset($headers[$header])) {
                throw new RuntimeException("Foaia Produse nu conține coloana „{$header}”.");
            }
            $columns[$key] = $headers[$header];
        }

        return $columns;
    }

    private function rowsByCode(DOMXPath $xpath, array $sharedStrings, string $codeColumn): array
    {
        $rows = [];
        foreach ($xpath->query('//s:sheetData/s:row[position() > 1]') ?: [] as $row) {
            $rowNumber = (int) $row->getAttribute('r');
            $cell = $xpath->query('./s:c[@r="'.$codeColumn.$rowNumber.'"]', $row)?->item(0);
            if (! $cell instanceof DOMElement) {
                continue;
            }
            $code = $this->normalizeCode($this->cellValue($xpath, $cell, $sharedStrings));
            if ($code !== '') {
                $rows[$code][] = $rowNumber;
            }
        }

        return $rows;
    }

    private function cellValue(DOMXPath $xpath, DOMElement $cell, array $sharedStrings): string
    {
        if ($cell->getAttribute('t') === 'inlineStr') {
            return trim((string) $xpath->evaluate('string(s:is)', $cell));
        }
        $value = trim((string) $xpath->evaluate('string(s:v)', $cell));

        return $cell->getAttribute('t') === 's' ? ($sharedStrings[(int) $value] ?? '') : $value;
    }

    private function setTextCell(DOMDocument $document, DOMXPath $xpath, int $row, string $column, string $value): void
    {
        $cell = $this->cell($document, $xpath, $row, $column);
        $this->removeChildren($cell);
        $cell->setAttribute('t', 'inlineStr');
        $inline = $document->createElementNS(self::SPREADSHEET_NAMESPACE, 'is');
        $text = $document->createElementNS(self::SPREADSHEET_NAMESPACE, 't');
        $text->appendChild($document->createTextNode($value));
        $inline->appendChild($text);
        $cell->appendChild($inline);
    }

    private function setNumberCell(DOMDocument $document, DOMXPath $xpath, int $row, string $column, ?string $value): void
    {
        $cell = $this->cell($document, $xpath, $row, $column);
        $this->removeChildren($cell);
        $cell->removeAttribute('t');
        $cell->appendChild($document->createElementNS(self::SPREADSHEET_NAMESPACE, 'v', $value ?? ''));
    }

    private function setFormulaCache(DOMDocument $document, DOMXPath $xpath, int $row, string $column, string $value): void
    {
        $cell = $this->cell($document, $xpath, $row, $column);
        $cached = $xpath->query('./s:v', $cell)?->item(0);
        if ($cached instanceof DOMNode) {
            $cell->removeChild($cached);
        }
        $cell->appendChild($document->createElementNS(self::SPREADSHEET_NAMESPACE, 'v', $value));
    }

    private function cell(DOMDocument $document, DOMXPath $xpath, int $rowNumber, string $column): DOMElement
    {
        $row = $xpath->query('//s:sheetData/s:row[@r="'.$rowNumber.'"]')?->item(0);
        if (! $row instanceof DOMElement) {
            throw new RuntimeException("Rândul {$rowNumber} lipsește din foaia Produse.");
        }
        $reference = $column.$rowNumber;
        $cell = $xpath->query('./s:c[@r="'.$reference.'"]', $row)?->item(0);
        if ($cell instanceof DOMElement) {
            return $cell;
        }

        $cell = $document->createElementNS(self::SPREADSHEET_NAMESPACE, 'c');
        $cell->setAttribute('r', $reference);
        $targetColumn = $this->columnNumber($column);
        foreach ($xpath->query('./s:c', $row) ?: [] as $existing) {
            if (preg_match('/^([A-Z]+)/i', $existing->getAttribute('r'), $match)
                && $this->columnNumber(mb_strtoupper($match[1])) > $targetColumn) {
                $row->insertBefore($cell, $existing);

                return $cell;
            }
        }
        $row->appendChild($cell);

        return $cell;
    }

    private function removeChildren(DOMElement $element): void
    {
        while ($element->firstChild !== null) {
            $element->removeChild($element->firstChild);
        }
    }

    private function entry(PharData $archive, string $path): string
    {
        if (! isset($archive[$path])) {
            throw new RuntimeException("Registrul nu conține componenta {$path}.");
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

    private function normalizeHeader(string $header): string
    {
        return mb_strtolower(preg_replace('/\s+/u', ' ', trim($header)) ?? trim($header));
    }

    private function normalizeCode(string $code): string
    {
        return mb_strtoupper(trim($code));
    }

    private function columnNumber(string $letters): int
    {
        $number = 0;
        foreach (str_split($letters) as $letter) {
            $number = ($number * 26) + ord($letter) - 64;
        }

        return $number;
    }

    private function trimDecimal(string $value): string
    {
        return rtrim(rtrim($value, '0'), '.');
    }

    private function fail(string $reason, array $changes): never
    {
        $visible = array_slice(array_map(fn (array $change): string => sprintf(
            '%s: stoc %d, preț fără TVA %s RON, greutate %s kg, TVA %s%%, de comandat %d, EN „%s”, RO „%s”',
            $change['code'],
            $change['stock'],
            $change['price_without_vat_ron'] ?? 'gol',
            $change['weight_kg'] ?? 'gol',
            $change['vat_rate'],
            $change['reorder_quantity'],
            mb_strimwidth($change['english_name'], 0, 60, '…'),
            mb_strimwidth($change['romanian_name'], 0, 60, '…'),
        ), $changes), 0, 10);
        $remaining = count($changes) > 10 ? ' și încă '.(count($changes) - 10).' produse' : '';

        throw ValidationException::withMessages([
            'registru_excel' => $reason.' Produse neactualizate: '.implode('; ', $visible).$remaining.'.',
        ]);
    }
}
