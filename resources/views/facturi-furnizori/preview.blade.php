@extends('layouts.app')

@section('title', 'Previzualizare factură · Gestiune Piese Kymco')
@section('section', 'Facturi furnizori')

@section('content')
    @if(session('status'))
        <div class="success">{{ session('status') }}</div>
    @endif
    @if($errors->any())
        <div class="notice">
            <strong>Corectează pozițiile semnalate înainte de import.</strong>
            <ul>@foreach($errors->all() as $eroare)<li>{{ $eroare }}</li>@endforeach</ul>
        </div>
    @endif

    <div class="page-head">
        <div>
            <h1>Verifică factura înainte de import</h1>
            <p class="lead">Toate câmpurile pozițiilor pot fi corectate manual. Prețul unitar este Amount / Cantitate.</p>
        </div>
        <form method="post" action="{{ route('facturi-furnizori.cancel') }}">
            @csrf
            <button class="button-secondary" style="width:auto;margin:0" type="submit">Renunță</button>
        </form>
    </div>

    <div class="invoice-summary">
        <div><small>Furnizor</small><strong>MOTO TREND S.A</strong></div>
        <div><small>Factură</small><strong>{{ $draft['invoice']['invoice_number'] }}</strong></div>
        <div><small>Data</small><strong>{{ \Illuminate\Support\Carbon::parse($draft['invoice']['invoice_date'])->format('d.m.Y') }}</strong></div>
        <div><small>Cantitate</small><strong>{{ $draft['invoice']['total_quantity'] }} buc.</strong></div>
        <div><small>Total</small><strong>{{ number_format((float) $draft['invoice']['total_amount'], 2, ',', '.') }} EUR</strong></div>
    </div>

    <form method="post" action="{{ route('facturi-furnizori.store') }}">
        @csrf
        <input type="hidden" name="token" value="{{ $draft['token'] }}">
        <section class="panel">
            <div class="panel-head">
                <h2>{{ count($draft['invoice']['lines']) }} poziții detectate</h2>
                <span class="pill">Corectează orice rând galben</span>
            </div>
            <div class="table-wrap">
                <table class="preview-table">
                    <thead><tr><th>#</th><th>Cod furnizor</th><th>Descriere</th><th>Cantitate</th><th>Amount EUR</th><th>Preț intrare</th><th>Produs local</th><th>Stare</th></tr></thead>
                    <tbody>
                    @foreach($draft['invoice']['lines'] as $index => $line)
                        @php
                            $selectedProduct = old("lines.$index.product_id", $line['product_id']);
                            $needsReview = !$line['valid'] || !$selectedProduct;
                        @endphp
                        <tr @class(['row-warning' => $needsReview])>
                            <td>{{ $index + 1 }}</td>
                            <td><input class="code-input" name="lines[{{ $index }}][supplier_code]" value="{{ old("lines.$index.supplier_code", $line['supplier_code']) }}" required></td>
                            <td>
                                <input class="description-input" name="lines[{{ $index }}][description]" value="{{ old("lines.$index.description", $line['description']) }}" required>
                                @if(!$line['valid'])<small class="danger">{{ $line['error'] }}</small>@endif
                            </td>
                            <td><input class="quantity-input" type="number" min="1" step="1" name="lines[{{ $index }}][quantity]" value="{{ old("lines.$index.quantity", $line['quantity']) }}" required></td>
                            <td><input class="amount-input" type="number" min="0.01" step="0.01" name="lines[{{ $index }}][amount]" value="{{ old("lines.$index.amount", $line['amount']) }}" required></td>
                            <td class="money"><strong>{{ $line['unit_price'] ?: '—' }}</strong><small>EUR</small></td>
                            <td>
                                <select class="product-select" name="lines[{{ $index }}][product_id]">
                                    <option value="">Nemapat — va necesita mapare</option>
                                    @foreach($produse as $produs)
                                        <option value="{{ $produs->id }}" @selected((string) $selectedProduct === (string) $produs->id)>{{ $produs->cod_produs }} {{ $produs->denumire_engleza }}</option>
                                    @endforeach
                                </select>
                            </td>
                            <td>
                                @if($selectedProduct)
                                    <span class="status-mapped">Mapat</span>
                                    @if($line['price_warning'])
                                        <div class="price-confirm-inline">
                                            <small>Preț propus</small>
                                            <input form="price-confirm-{{ $index }}" type="number" name="pret_vanzare_cu_tva" value="{{ $line['proposed_sale_price'] }}" min="0" step="0.01" required aria-label="Preț propus cu TVA pentru {{ $line['product_label'] }}">
                                            <small>&gt; {{ number_format((float) $line['current_sale_price'], 2, '.', '') }} (preț actual)</small>
                                            <button form="price-confirm-{{ $index }}" type="submit">OK</button>
                                        </div>
                                    @endif
                                @else
                                    <a class="button-secondary" href="{{ route('facturi-furnizori.produs-nou', ['line' => $index]) }}">Produs NOU</a>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <div class="confirm-bar">
                <span><strong>TVA factură: 0%</strong><br><small>Taxare inversă: DA</small></span>
                <button type="submit">Confirmă și salvează factura</button>
            </div>
        </section>
    </form>

    @foreach($draft['invoice']['lines'] as $index => $line)
        @if(!empty($line['product_id']) && $line['price_warning'])
            <form id="price-confirm-{{ $index }}" method="post" action="{{ route('facturi-furnizori.pret.confirmare', ['line' => $index]) }}">
                @csrf
                @method('patch')
                <input type="hidden" name="token" value="{{ $draft['token'] }}">
            </form>
        @endif
    @endforeach
@endsection
