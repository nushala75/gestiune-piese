@extends('layouts.app')

@section('title', 'Panou principal · Gestiune Piese Kymco')

@section('content')
    <div class="page-head">
        <div>
            <h1>Panou principal</h1>
            <p class="lead">Situația operațională a gestiunii FIRMA.</p>
        </div>
    </div>

    @unless($schemaDisponibila)
        <div class="notice">Schema bazei de date nu este disponibilă în această instalare. Aplică migrările înainte de popularea datelor.</div>
    @endunless

    <section class="cards" aria-label="Indicatori">
        <div class="card"><span>Produse active</span><strong>{{ number_format($produseActive, 0, ',', '.') }}</strong></div>
        <div class="card"><span>Produse cu stoc</span><strong>{{ number_format($produseInStoc, 0, ',', '.') }}</strong></div>
        <div class="card"><span>Unități în stoc</span><strong>{{ number_format((int) $unitatiInStoc, 0, ',', '.') }}</strong></div>
    </section>

    <section class="panel quick">
        <h2>Acces rapid</h2>
        <div class="quick-grid">
            <a href="{{ route('produse.index') }}"><strong>Vezi produsele</strong><br><small>Coduri FGO, prețuri și stoc</small></a>
            <a href="{{ route('facturi-furnizori.index') }}"><strong>Importă o factură</strong><br><small>PDF MOTO-TREND cu previzualizare</small></a>
            <span><strong>Generează export SAGA</strong><br><small>Disponibil după maparea facturii</small></span>
        </div>
    </section>
@endsection
