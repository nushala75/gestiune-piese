@extends('layouts.app')

@section('title', 'Facturi furnizori · Gestiune Piese Kymco')
@section('section', 'Facturi furnizori')

@section('content')
    @if(session('status'))
        <div class="success">{{ session('status') }}</div>
    @endif
    @if($errors->any())
        <div class="notice">
            <strong>Factura nu a putut fi procesată.</strong>
            <ul>@foreach($errors->all() as $eroare)<li>{{ $eroare }}</li>@endforeach</ul>
        </div>
    @endif

    <div class="page-head">
        <div>
            <h1>Facturi furnizori</h1>
            <p class="lead">Import PDF MOTO-TREND, cu verificare înainte de salvare.</p>
        </div>
    </div>

    <section class="panel" style="margin-bottom:20px">
        <div class="panel-head"><h2>Importă o factură MOTO-TREND</h2></div>
        <form class="import-form" method="post" action="{{ route('facturi-furnizori.upload') }}" enctype="multipart/form-data">
            @csrf
            <label>
                <span>Fișier PDF</span>
                <input type="file" name="factura_pdf" accept="application/pdf,.pdf" required>
            </label>
            <button type="submit">Încarcă și previzualizează</button>
        </form>
    </section>

    <section class="panel">
        <div class="panel-head"><h2>{{ $facturi->total() }} facturi importate</h2></div>
        @if($facturi->isEmpty())
            <div class="empty">Nu există încă facturi importate.</div>
        @else
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Număr</th><th>Data</th><th>Furnizor</th><th>Poziții</th><th>Total</th><th>Status</th></tr></thead>
                    <tbody>
                    @foreach($facturi as $factura)
                        <tr>
                            <td><strong>{{ $factura->numar_original }}</strong></td>
                            <td>{{ $factura->data_factura->format('d.m.Y') }}</td>
                            <td>{{ $factura->furnizor->denumire }}</td>
                            <td>{{ $factura->linii->count() }}</td>
                            <td class="money"><strong>{{ number_format((float) $factura->total_factura, 2, ',', '.') }}</strong> {{ $factura->moneda }}</td>
                            <td><span class="pill">{{ str_replace('_', ' ', $factura->status) }}</span></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <div class="pagination">{{ $facturi->links() }}</div>
        @endif
    </section>
@endsection
