@extends('layouts.app')

@section('title', 'Previzualizare actualizare · Gestiune Piese Kymco')
@section('section', 'Actualizare stoc')

@section('content')
    @php($summary = $draft['preview']['summary'])
    @if($errors->any())
        <div class="notice">
            <strong>Actualizarea nu a fost aplicată.</strong>
            <ul>@foreach($errors->all() as $eroare)<li>{{ $eroare }}</li>@endforeach</ul>
        </div>
    @endif

    <div class="page-head">
        <div>
            <h1>Previzualizare actualizare</h1>
            <p class="lead">{{ $draft['original_name'] }} · foaia {{ $draft['sheet'] }}</p>
        </div>
    </div>

    <div class="invoice-summary">
        <div><small>Poziții în fișier</small><strong>{{ $summary['rows'] }}</strong></div>
        <div><small>Potrivite exact</small><strong>{{ $summary['matched'] }}</strong></div>
        <div><small>Stoc de modificat</small><strong>{{ $summary['stock_changed'] }}</strong></div>
        <div><small>Preț de modificat</small><strong>{{ $summary['price_changed'] }}</strong></div>
        <div><small>Coduri inexistente</small><strong>{{ $summary['missing'] }}</strong></div>
        <div><small>Produse din catalog în afara fișierului</small><strong>{{ $summary['catalog_not_in_file'] }}</strong></div>
    </div>

    <div class="success">
        <strong>Curs BNR EUR: {{ number_format((float) $draft['exchange_rate']['value'], 4, ',', '.') }} RON</strong><br>
        Publicat pentru {{ $draft['exchange_rate']['published_on'] }} și preluat la {{ \Illuminate\Support\Carbon::parse($draft['exchange_rate']['fetched_at'])->format('d.m.Y H:i') }}.
        Prețurile finale sunt rotunjite la 2 zecimale. <a href="{{ $draft['exchange_rate']['source_url'] }}" target="_blank" rel="noopener">Sursa XML BNR</a>.
    </div>

    @if($summary['ambiguous'] > 0)
        <div class="notice"><strong>Aplicarea este blocată:</strong> {{ $summary['ambiguous'] }} coduri corespund mai multor produse din catalog.</div>
    @endif

    <section class="panel">
        <div class="panel-head"><h2>Modificări propuse</h2><span class="pill">primele 150</span></div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Rând</th><th>Cod</th><th>Produs</th><th>Stoc actual</th><th>Stoc nou</th><th>Preț fișier EUR cu TVA</th><th>Preț actual RON</th><th>Preț final RON</th></tr></thead>
                <tbody>
                @forelse(array_slice($draft['preview']['changed'], 0, 150) as $line)
                    <tr>
                        <td>{{ $line['row'] }}</td>
                        <td><code>{{ $line['code'] }}</code></td>
                        <td class="name">{{ $line['product_name'] }}</td>
                        <td @class(['danger' => $line['stock_changed']])>{{ $line['old_stock'] }}</td>
                        <td @class(['danger' => $line['stock_changed']])>{{ $line['new_stock'] }}</td>
                        <td class="money">{{ number_format((float) $line['price_with_vat'], 4, ',', '.') }}</td>
                        <td class="money">{{ $line['old_price'] === null ? '—' : number_format((float) $line['old_price'], 2, ',', '.') }}</td>
                        <td @class(['money', 'danger' => $line['price_changed']])>{{ number_format((float) $line['new_price'], 2, ',', '.') }}</td>
                    </tr>
                @empty
                    <tr><td class="empty" colspan="8">Nu există valori diferite față de catalog.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>

    @if($summary['missing'] > 0 || $summary['ambiguous'] > 0)
        <section class="panel" style="margin-top:18px">
            <div class="panel-head"><h2>Poziții neaplicabile</h2></div>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Rând</th><th>Cod</th><th>Motiv</th></tr></thead>
                    <tbody>
                    @foreach(array_slice($draft['preview']['missing'], 0, 100) as $line)
                        <tr><td>{{ $line['row'] }}</td><td><code>{{ $line['code'] }}</code></td><td>Codul nu există în catalogul local</td></tr>
                    @endforeach
                    @foreach(array_slice($draft['preview']['ambiguous'], 0, 100) as $line)
                        <tr><td>{{ $line['row'] }}</td><td><code>{{ $line['code'] }}</code></td><td>{{ $line['matches'] }} produse au același cod; aplicarea este blocată</td></tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    <div class="panel confirm-bar" style="margin-top:18px">
        <form method="post" action="{{ route('stock-update.apply') }}">
            @csrf
            <input type="hidden" name="token" value="{{ $draft['token'] }}">
            <label style="display:flex;align-items:center;gap:8px"><input type="checkbox" name="confirmare" value="1" required> Am verificat previzualizarea și cursul BNR</label>
            <button type="submit" @disabled($summary['ambiguous'] > 0)>Actualizare stoc și prețuri</button>
        </form>
        <form method="post" action="{{ route('stock-update.cancel') }}">
            @csrf
            <button class="button-secondary page-action" type="submit">Anulează</button>
        </form>
    </div>
@endsection
