@extends('layouts.app')

@section('title', 'Raport actualizare stoc · Gestiune Piese Kymco')
@section('section', 'Produse')

@section('content')
    <div class="page-head">
        <div>
            <h1>Raport actualizare stoc</h1>
            <p class="lead">Fișier: {{ $raport['fisier'] }}</p>
        </div>
        <a class="button-secondary page-action" href="{{ route('produse.index') }}">Înapoi la produse</a>
    </div>

    <section class="panel">
        <div class="table-wrap">
            <table>
                <tbody>
                    <tr><th>Rânduri de date citite</th><td>{{ $raport['randuri_date'] }}</td></tr>
                    <tr><th>Coduri CSV valide și unice</th><td>{{ $raport['coduri_valide'] }}</td></tr>
                    <tr><th>Produse actualizate din CSV</th><td>{{ $raport['actualizate'] }}</td></tr>
                    <tr><th>Produse fără modificări</th><td>{{ $raport['neschimbate'] }}</td></tr>
                    <tr><th>Produse trecute la stoc 0 fiind absente din CSV</th><td>{{ $raport['trecute_la_zero'] }}</td></tr>
                    <tr><th>Produse noi / coduri inexistente în bază</th><td>{{ count($raport['produse_noi']) }}</td></tr>
                    <tr><th>Coduri ambigue în baza locală</th><td>{{ count($raport['coduri_ambigue']) }}</td></tr>
                    <tr><th>Coduri duplicate în CSV</th><td>{{ count($raport['duplicate_csv']) }}</td></tr>
                    <tr><th>Rânduri invalide</th><td>{{ count($raport['randuri_invalide']) }}</td></tr>
                </tbody>
            </table>
        </div>
    </section>

    @if($raport['produse_noi'])
        <section class="panel">
            <div class="panel-head"><h2>Produse noi care nu există în bază</h2></div>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Cod</th><th>Stoc din CSV</th></tr></thead>
                    <tbody>
                        @foreach($raport['produse_noi'] as $produs)
                            <tr><td><code>{{ $produs['cod'] }}</code></td><td>{{ $produs['cantitate'] }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    @if($raport['coduri_ambigue'])
        <section class="panel">
            <div class="panel-head"><h2>Coduri neactualizate: mai multe produse locale au același cod</h2></div>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Cod</th><th>Stoc CSV</th><th>Produse locale găsite</th></tr></thead>
                    <tbody>
                        @foreach($raport['coduri_ambigue'] as $rand)
                            <tr><td><code>{{ $rand['cod'] }}</code></td><td>{{ $rand['cantitate'] }}</td><td>{{ $rand['produse_gasite'] }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    @if($raport['duplicate_csv'])
        <section class="panel">
            <div class="panel-head"><h2>Coduri duplicate în CSV — neimportate</h2></div>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Cod</th><th>Linia unde s-a detectat duplicatul</th></tr></thead>
                    <tbody>
                        @foreach($raport['duplicate_csv'] as $rand)
                            <tr><td><code>{{ $rand['cod'] }}</code></td><td>{{ $rand['linie'] }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    @if($raport['randuri_invalide'])
        <section class="panel">
            <div class="panel-head"><h2>Rânduri invalide — neimportate</h2></div>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Linie</th><th>Motiv</th></tr></thead>
                    <tbody>
                        @foreach($raport['randuri_invalide'] as $rand)
                            <tr><td>{{ $rand['linie'] }}</td><td>{{ $rand['motiv'] }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif
@endsection
