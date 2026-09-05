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
        <div><small>Greutate de modificat</small><strong>{{ $summary['weight_changed'] }}</strong></div>
        <div><small>Nume EN de modificat</small><strong>{{ $summary['english_name_changed'] }}</strong></div>
        <div><small>Nume RO de modificat</small><strong>{{ $summary['romanian_name_changed'] }}</strong></div>
        <div><small>De comandat de modificat</small><strong>{{ $summary['reorder_changed'] }}</strong></div>
        <div><small>Coduri inexistente</small><strong>{{ $summary['missing'] }}</strong></div>
        <div><small>Produse din catalog în afara fișierului</small><strong>{{ $summary['catalog_not_in_file'] }}</strong></div>
    </div>

    <div class="success">
        <strong>Curs folosit: 1 EUR = {{ number_format((float) $draft['exchange_rate'], 4, ',', '.') }} lei</strong><br>
        Prețul final în lei este „Preț cu TVA” din registru × acest curs și este rotunjit la 2 zecimale.
    </div>

    @if($summary['ambiguous'] > 0)
        <div class="notice"><strong>Aplicarea este blocată:</strong> {{ $summary['ambiguous'] }} coduri corespund mai multor produse din catalog.</div>
    @endif

    <section class="panel">
        <div class="panel-head"><h2>Modificări propuse</h2><span class="pill">primele 150</span></div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Rând</th><th>Cod</th><th>Stoc actual → nou</th><th>Preț EUR</th><th>Preț RON actual → nou</th><th>Greutate actuală → nouă</th><th>De comandat actual → nou</th><th>Nume EN actual → nou</th><th>Nume RO actual → nou</th></tr></thead>
                <tbody>
                @forelse(array_slice($draft['preview']['changed'], 0, 150) as $line)
                    <tr>
                        <td>{{ $line['row'] }}</td>
                        <td><code>{{ $line['code'] }}</code></td>
                        <td @class(['danger' => $line['stock_changed']])>{{ $line['old_stock'] }} → {{ $line['new_stock'] }}</td>
                        <td class="money">{{ number_format((float) $line['price_with_vat_eur'], 4, ',', '.') }}</td>
                        <td @class(['money', 'danger' => $line['price_changed']])>{{ $line['old_price'] === null ? '—' : number_format((float) $line['old_price'], 2, ',', '.') }} → {{ number_format((float) $line['new_price'], 2, ',', '.') }}</td>
                        <td @class(['money', 'danger' => $line['weight_changed']])>{{ $line['old_weight'] === null ? '—' : number_format((float) $line['old_weight'], 3, ',', '.') }} → {{ number_format((float) $line['new_weight'], 3, ',', '.') }}</td>
                        <td @class(['danger' => $line['reorder_changed']])>{{ $line['old_reorder_quantity'] }} → {{ $line['new_reorder_quantity'] }}</td>
                        <td @class(['name', 'danger' => $line['english_name_changed']])>{{ $line['old_english_name'] }} → {{ $line['new_english_name'] }}</td>
                        <td @class(['name', 'danger' => $line['romanian_name_changed']])>{{ $line['old_romanian_name'] ?: '—' }} → {{ $line['new_romanian_name'] }}</td>
                    </tr>
                @empty
                    <tr><td class="empty" colspan="9">Nu există valori diferite față de catalog.</td></tr>
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
            <label style="display:flex;align-items:center;gap:8px"><input type="checkbox" name="confirmare" value="1" required> Am verificat corespondența și cursul EUR/RON</label>
            <button type="submit" @disabled($summary['ambiguous'] > 0)>Aplică actualizarea produselor</button>
        </form>
        <form method="post" action="{{ route('stock-update.cancel') }}">
            @csrf
            <button class="button-secondary page-action" type="submit">Anulează</button>
        </form>
    </div>
@endsection
