@extends('layouts.app')

@section('title', 'Furnizori · Gestiune Piese Kymco')
@section('section', 'Furnizori')

@section('content')
    @if(session('status'))
        <div class="success">{{ session('status') }}</div>
    @endif

    <div class="page-head">
        <div>
            <h1>Furnizori</h1>
            <p class="lead">Datele furnizorilor și legăturile lor cu produsele și facturile.</p>
        </div>
        <a class="button-secondary page-action" href="{{ route('furnizori.create') }}">Adaugă furnizor</a>
    </div>

    <div class="panel table-wrap">
        <table>
            <thead>
                <tr><th>Furnizor</th><th>VAT</th><th>Țară</th><th>Adresă</th><th>Monedă</th><th>Produse mapate</th><th>Facturi</th><th>Status</th><th>Acțiuni</th></tr>
            </thead>
            <tbody>
                @forelse($furnizori as $furnizor)
                    <tr>
                        <td><strong>{{ $furnizor->denumire }}</strong></td>
                        <td>{{ $furnizor->cod_fiscal }}</td>
                        <td>{{ $furnizor->tara }}</td>
                        <td>{{ $furnizor->adresa ?: '—' }}</td>
                        <td>{{ $furnizor->moneda_implicita }}</td>
                        <td>{{ number_format($furnizor->produse_count, 0, ',', '.') }}</td>
                        <td>{{ number_format($furnizor->facturi_count, 0, ',', '.') }}</td>
                        <td><span class="{{ $furnizor->activ ? 'stock-positive' : 'stock-zero' }}">{{ $furnizor->activ ? 'Activ' : 'Inactiv' }}</span></td>
                        <td><div class="row-actions"><a class="button-secondary" href="{{ route('furnizori.edit', $furnizor) }}">Editează</a></div></td>
                    </tr>
                @empty
                    <tr><td colspan="9">Nu există furnizori.</td></tr>
                @endforelse
            </tbody>
        </table>
        {{ $furnizori->links() }}
    </div>
@endsection
