@extends('layouts.app')

@section('title', 'Recepție factură · Gestiune Piese Kymco')
@section('section', 'Recepții')

@section('content')
    <div class="page-head">
        <div>
            <h1>{{ $factura->tip_document === 'storno' ? 'Recepție storno' : 'Recepție' }} {{ $factura->numar_original }}</h1>
            <p class="lead">{{ $factura->furnizor->denumire }} · {{ $factura->linii->count() }} poziții · {{ $factura->linii->sum('cantitate') }} bucăți</p>
        </div>
        <span class="pill">Gestiune FIRMA</span>
    </div>

    @if($errors->any())
        <div class="notice">
            <strong>Recepția nu a fost creată.</strong>
            <ul>@foreach($errors->all() as $eroare)<li>{{ $eroare }}</li>@endforeach</ul>
        </div>
    @endif

    <div class="notice">
        @if($factura->tip_document === 'storno')
            Recepția storno este definitivă. La finalizare se scad din stoc cantitățile pozitive din document. Prețurile de intrare rămân neschimbate.
        @else
            Recepția este integrală și definitivă. La finalizare se adaugă în stoc toate cantitățile de mai jos și se actualizează prețurile de intrare în {{ $factura->moneda }}.
        @endif
    </div>

    @if($avertismenteStoc->isNotEmpty())
        <div class="notice">
            <strong>Atenție: recepția storno va genera stoc negativ.</strong>
            <ul>
                @foreach($avertismenteStoc as $avertisment)
                    <li>{{ $avertisment['produs'] }}: stoc {{ $avertisment['stoc_curent'] }} − {{ $avertisment['cantitate_storno'] }} = {{ $avertisment['stoc_dupa'] }}</li>
                @endforeach
            </ul>
            Operația este permisă și poate fi continuată după verificare.
        </div>
    @endif

    <section class="panel">
        <div class="panel-head"><h2>Pozițiile recepției</h2></div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>#</th><th>Cod furnizor</th><th>Produs</th><th>Cantitate</th><th>Preț intrare</th><th>Valoare</th></tr></thead>
                <tbody>
                @foreach($factura->linii as $linie)
                    <tr>
                        <td>{{ $linie->numar_linie }}</td>
                        <td><strong>{{ $linie->cod_furnizor }}</strong></td>
                        <td class="name"><strong>{{ $linie->produs->cod_produs }}</strong><br><small>{{ $linie->descriere_originala }}</small></td>
                        <td>{{ $linie->cantitate }}</td>
                        <td class="money">{{ number_format((float) $linie->pret_unitar_calculat, 4, '.', '') }} {{ $factura->moneda }}</td>
                        <td class="money">{{ number_format((float) $linie->amount_sursa, 2, ',', '.') }} {{ $factura->moneda }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </section>

    <form method="post" action="{{ route('receptii.store', $factura) }}" onsubmit="return confirm(@js('Finalizezi definitiv recepția integrală a facturii '.$factura->numar_original.'?'));">
        @csrf
        <section class="panel form-panel" style="margin-top:18px">
            <div class="form-grid">
                <label>
                    <span>Data recepției</span>
                    <input type="date" name="data_receptie" value="{{ old('data_receptie', now()->toDateString()) }}" required>
                </label>
                <label class="form-check">
                    <input type="checkbox" name="confirmare_saga" value="1" @checked(old('confirmare_saga')) required>
                    <span>Confirm că factura a fost importată manual în SAGA</span>
                </label>
            </div>
            <div class="form-actions">
                <button @class(['button-danger' => $factura->tip_document === 'storno']) type="submit">Finalizează {{ $factura->tip_document === 'storno' ? 'recepția storno' : 'recepția' }}</button>
                <a class="button-secondary" href="{{ route('facturi-furnizori.show', $factura) }}">Înapoi la factură</a>
            </div>
        </section>
    </form>
@endsection
