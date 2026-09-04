<?php

namespace App\Services;

use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class BnrExchangeRateService
{
    public const SOURCE_URL = 'https://www.bnr.ro/files/xml/nbrfxrates.xml';

    /** @return array{currency: string, value: string, published_on: string, fetched_at: string, source_url: string} */
    public function latestEuroRate(): array
    {
        $response = Http::timeout(12)->retry(2, 250)->get(self::SOURCE_URL);
        if (! $response->successful()) {
            throw new RuntimeException('Cursul BNR nu a putut fi preluat. Importul a fost oprit.');
        }

        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument;
        $loaded = $document->loadXML($response->body(), LIBXML_NONET | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (! $loaded) {
            throw new RuntimeException('Răspunsul BNR nu conține XML valid.');
        }

        $xpath = new DOMXPath($document);
        $cube = $xpath->query('//*[local-name()="Cube" and @date]')->item(0);
        $rate = $xpath->query('//*[local-name()="Rate" and @currency="EUR"]')->item(0);
        $value = trim((string) $rate?->textContent);
        $publishedOn = trim((string) $cube?->attributes?->getNamedItem('date')?->nodeValue);
        if ($value === '' || ! preg_match('/^\d+(?:\.\d+)?$/', $value) || $publishedOn === '') {
            throw new RuntimeException('Cursul EUR sau data publicării lipsesc din răspunsul BNR.');
        }

        return [
            'currency' => 'EUR',
            'value' => $value,
            'published_on' => $publishedOn,
            'fetched_at' => now()->toIso8601String(),
            'source_url' => self::SOURCE_URL,
        ];
    }
}
