<?php

namespace App\Services;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use RuntimeException;
use Smalot\PdfParser\Parser;

class MotoTrendInvoiceParser
{
    public function __construct(private readonly Parser $parser) {}

    /**
     * @return array{
     *     supplier_vat:string, customer_vat:string, invoice_number:string,
     *     invoice_date:string, currency:string, total_amount:string,
     *     total_quantity:int, lines:array<int, array<string, mixed>>
     * }
     */
    public function parse(string $path): array
    {
        $text = $this->parser->parseFile($path)->getText();

        $this->assertContains($text, 'MOTO TREND S.A', 'Furnizorul MOTO TREND nu a fost identificat.');
        $this->assertContains($text, 'EL094496688', 'Codul fiscal MOTO TREND nu a fost identificat.');
        $this->assertContains($text, 'RO20548513', 'Factura nu este emisă pentru DESIGN MEDIA BUSINESS SRL.');

        preg_match('/^No:\s*(.+)$/mu', $text, $numberMatch);
        preg_match('/^Date:\s*(\d{2}\/\d{2}\/\d{4})$/mu', $text, $dateMatch);
        preg_match('/Total Q\S*\s+Total Amount\s+[^\d]*([\d.]+,\d{2})\s+(\d+)/u', $text, $totalMatch);

        if (! isset($numberMatch[1], $dateMatch[1], $totalMatch[1], $totalMatch[2])) {
            throw new RuntimeException('Antetul sau totalurile facturii MOTO TREND nu au putut fi citite complet.');
        }

        $lines = [];
        $unparsed = [];
        $insideLines = false;

        foreach (preg_split('/\R/u', $text) ?: [] as $sourceLine) {
            $trimmed = trim($sourceLine);

            if ($trimmed === 'Seller :' || str_starts_with($trimmed, 'Page. :')) {
                $insideLines = false;

                continue;
            }
            if (str_starts_with($trimmed, 'Reference')) {
                $insideLines = true;

                continue;
            }
            if (! $insideLines || $trimmed === '' || str_contains($trimmed, 'cummulatives')) {
                continue;
            }
            if (str_starts_with($trimmed, 'Total Q')) {
                $insideLines = false;

                continue;
            }

            $parsed = $this->parseLine($sourceLine, count($lines) + count($unparsed) + 1);
            if ($parsed !== null) {
                $lines[] = $parsed;
            } elseif (preg_match('/^\d+\s/', $trimmed) === 1) {
                $unparsed[] = [
                    'line_number' => count($lines) + count($unparsed) + 1,
                    'supplier_code' => '',
                    'description' => '',
                    'quantity' => '',
                    'amount' => '',
                    'unit_price' => '',
                    'valid' => false,
                    'error' => 'Linia nu a putut fi citită. Completează manual câmpurile.',
                    'source' => $trimmed,
                ];
            }
        }

        $lines = [...$lines, ...$unparsed];
        usort($lines, fn (array $left, array $right): int => $left['line_number'] <=> $right['line_number']);

        if ($lines === []) {
            throw new RuntimeException('Factura nu conține nicio poziție care să poată fi importată.');
        }

        $expectedAmount = $this->decimal($totalMatch[1]);
        $expectedQuantity = (int) $totalMatch[2];
        $parsedAmountCents = array_sum(array_map(
            fn (array $line): int => $line['valid'] ? (int) round((float) $line['amount'] * 100) : 0,
            $lines,
        ));
        $parsedQuantity = array_sum(array_map(
            fn (array $line): int => $line['valid'] ? (int) $line['quantity'] : 0,
            $lines,
        ));
        $hasInvalidLines = count(array_filter($lines, fn (array $line): bool => ! $line['valid'])) > 0;

        if (! $hasInvalidLines && ($parsedAmountCents !== (int) round((float) $expectedAmount * 100) || $parsedQuantity !== $expectedQuantity)) {
            throw new RuntimeException('Suma sau cantitatea pozițiilor nu corespunde totalului facturii.');
        }

        $date = \DateTimeImmutable::createFromFormat('!d/m/Y', $dateMatch[1]);
        if ($date === false) {
            throw new RuntimeException('Data facturii nu este validă.');
        }

        return [
            'supplier_vat' => 'EL094496688',
            'customer_vat' => 'RO20548513',
            'invoice_number' => trim($numberMatch[1]),
            'invoice_date' => $date->format('Y-m-d'),
            'currency' => 'EUR',
            'total_amount' => $expectedAmount,
            'total_quantity' => $expectedQuantity,
            'lines' => $lines,
        ];
    }

    /** @return array<string, mixed>|null */
    private function parseLine(string $sourceLine, int $lineNumber): ?array
    {
        if (preg_match(
            '/^\s*(\d+)\s+(\d+,\d{2})\s+(\d+,\d{1,2})(?:\s+(\d+,\d{1,2}))?\s+(\d+,\d{2})(.+)$/u',
            $sourceLine,
            $match,
        ) !== 1) {
            return null;
        }

        $quantity = (int) $match[1];
        $amount = $this->decimal($match[5]);
        $middle = trim($match[6]);
        if (preg_match(
            '/^(?<code>\S+?)\d+,\d{2}(?:\s+(?<description>.*?))?\k<code>$/u',
            $middle,
            $descriptionMatch,
        ) !== 1) {
            return null;
        }

        $supplierCode = trim($descriptionMatch['code']);
        $description = trim($descriptionMatch['description'] ?? '');
        $valid = $quantity > 0 && $supplierCode !== '' && $description !== '';
        $error = $valid
            ? null
            : ($description === ''
                ? 'Description of Goods lipsește. Completează descrierea înainte de salvare.'
                : 'Verifică manual codul, descrierea, cantitatea și valoarea.');

        return [
            'line_number' => $lineNumber,
            'supplier_code' => $supplierCode,
            'description' => $description,
            'quantity' => $quantity,
            'amount' => $amount,
            'unit_price' => $quantity > 0
                ? BigDecimal::of($amount)->dividedBy($quantity, 4, RoundingMode::HalfUp)->__toString()
                : '',
            'valid' => $valid,
            'error' => $error,
            'source' => trim($sourceLine),
        ];
    }

    private function decimal(string $value): string
    {
        return str_replace(['.', ','], ['', '.'], trim($value));
    }

    private function assertContains(string $text, string $needle, string $message): void
    {
        if (! str_contains($text, $needle)) {
            throw new RuntimeException($message);
        }
    }
}
