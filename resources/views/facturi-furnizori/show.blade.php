@extends('layouts.app')

@section('title', 'Detalii factură furnizor · Gestiune Piese Kymco')
@section('section', 'Facturi furnizori')

@section('content')
    @if(session('status'))<div class="success">{{ session('status') }}</div>@endif
    @if($errors->any())
        <div class="notice">
            <strong>Operația nu a putut fi finalizată.</strong>
            <ul>@foreach($errors->all() as $eroare)<li>{{ $eroare }}</li>@endforeach</ul>
        </div>
    @endif

    <div class="page-head">
        <div>
            <h1>{{ $factura->tip_document === 'storno' ? 'Storno' : 'Factura' }} {{ $factura->numar_original }}</h1>
            <p class="lead">{{ $factura->furnizor->denumire }} · {{ $factura->data_factura->format('d.m.Y') }} · {{ number_format((float) $factura->total_factura, 2, ',', '.') }} {{ $factura->moneda }}</p>
        </div>
        <span class="pill">{{ $factura->receptie ? 'Recepție finalizată' : ($factura->status === 'import_partial' ? 'Import parțial' : 'Import finalizat') }}</span>
    </div>

    <form method="post" action="{{ route('facturi-furnizori.mapari', $factura) }}">
        @csrf
        @method('patch')
        <section class="panel">
            <div class="panel-head"><h2>{{ $factura->linii->count() }} poziții</h2></div>
            <div class="table-wrap">
                <table class="preview-table">
                    <thead><tr><th>#</th><th>Cod furnizor</th><th>Descriere</th><th>Cantitate</th><th>Preț intrare</th><th>Produs local</th><th>Stare</th></tr></thead>
                    <tbody>
                    @foreach($factura->linii as $line)
                        <tr @class(['row-warning' => $line->tip_linie === 'produs' && !$line->produs_id])>
                            <td>{{ $line->numar_linie }}</td>
                            <td><strong>{{ $line->cod_furnizor }}</strong></td>
                            <td>{{ $line->descriere_originala }}</td>
                            <td>{{ $line->cantitate }}</td>
                            <td class="money"><strong>{{ number_format((float) $line->pret_unitar_calculat, 4, '.', '') }}</strong><small>{{ $factura->moneda }}</small></td>
                            <td>
                                @if($line->tip_linie === 'cost')
                                    <span class="pill">Cost fără stoc</span>
                                @else
                                    <select class="product-select" name="lines[{{ $line->id }}][product_id]" @disabled($factura->status !== 'import_partial' || $factura->receptie)>
                                        <option value="">Nemapat</option>
                                        @foreach($produse as $produs)
                                            <option value="{{ $produs->id }}" @selected((int) old("lines.{$line->id}.product_id", $line->produs_id) === $produs->id)>{{ $produs->cod_produs }} {{ $produs->denumire_engleza }}</option>
                                        @endforeach
                                    </select>
                                @endif
                            </td>
                            <td>
                                @if($line->tip_linie === 'cost')
                                    <span class="status-mapped">Cost</span>
                                @elseif($line->produs_id)
                                    <span class="status-mapped">Mapat</span>
                                @elseif($factura->tip_document !== 'storno')
                                    <a class="button-secondary" href="{{ route('facturi-furnizori.importat.produs-nou', ['factura' => $factura, 'linie' => $line]) }}">Produs NOU</a>
                                @else
                                    <span class="status-unmapped">Selectează produs existent</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            @if(!$factura->receptie && $factura->status === 'import_partial')
                <div class="form-actions"><button type="submit">Salvează mapările</button></div>
            @endif
        </section>
    </form>

    @if($factura->status !== 'import_partial')
        <section class="panel" style="margin-top:14px">
            <div class="panel-head"><h2>Export SAGA</h2></div>
            <p><strong>Ordinea este obligatorie:</strong> importă mai întâi nomenclatorul de articole în SAGA, apoi factura.</p>
            <div class="form-actions">
                <form method="post" action="{{ route('facturi-furnizori.export-saga-articole', $factura) }}">
                    @csrf
                    <button type="submit">1. Generează XML articole SAGA</button>
                </form>
                <form method="post" action="{{ route('facturi-furnizori.export-saga-factura', $factura) }}" onsubmit="return confirm('Ai importat deja XML-ul de articole în SAGA?');">
                    @csrf
                    <button type="submit">2. Generează XML factura SAGA</button>
                </form>
            </div>
        </section>
    @endif

    <div class="form-actions" style="margin-top:14px">
        @if(!$factura->receptie && $factura->status === 'import_partial')
            <form method="post" action="{{ route('facturi-furnizori.finalizare', $factura) }}">
                @csrf
                <button type="submit">Finalizează importul</button>
            </form>
        @endif
        @if(!$factura->receptie && $factura->status === 'import_finalizat')
            <a @class(['button-secondary', 'button-danger' => $factura->tip_document === 'storno']) href="{{ route('receptii.create', $factura) }}">{{ $factura->tip_document === 'storno' ? 'Recepție storno' : 'Recepție' }}</a>
        @endif
        @if($factura->receptie)
            <span class="pill">Recepționată la {{ $factura->receptie->data_receptie->format('d.m.Y') }} · definitiv</span>
        @endif
        @if(!$factura->receptie)
            <form method="post" action="{{ route('facturi-furnizori.destroy', $factura) }}" onsubmit="return confirm(@js('Ștergi definitiv factura '.$factura->numar_original.'?'));">
                @csrf
                @method('delete')
                <button class="button-danger" type="submit">Șterge factura</button>
            </form>
        @endif
        <a class="button-secondary" href="{{ route('facturi-furnizori.index') }}">Înapoi la facturi</a>
    </div>
@endsection
