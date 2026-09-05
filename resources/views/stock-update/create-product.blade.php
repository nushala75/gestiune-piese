@extends('layouts.app')

@section('title', 'Produs nou din registru · Gestiune Piese Kymco')
@section('section', 'Actualizare produse')

@section('content')
    @if($errors->any())
        <div class="notice">
            <strong>Produsul nu a fost salvat.</strong>
            <ul>@foreach($errors->all() as $eroare)<li>{{ $eroare }}</li>@endforeach</ul>
        </div>
    @endif

    <div class="page-head">
        <div>
            <h1>Creează manual produsul</h1>
            <p class="lead">Rândul {{ $line['row'] }} din {{ $draft['original_name'] }} · {{ count($draft['created_products'] ?? []) }}/10 produse create în sesiune</p>
        </div>
    </div>

    <form class="panel form-panel" method="post" action="{{ route('stock-update.product.store', ['row' => $line['row']]) }}">
        @csrf
        <input type="hidden" name="token" value="{{ $draft['token'] }}">
        <div class="form-grid">
            <label><span>Cod produs</span><input type="text" value="{{ $line['code'] }}" readonly></label>
            <label><span>Nume în engleză</span><input type="text" value="{{ $line['english_name'] }}" readonly></label>
            <label class="form-span-2"><span>Nume în română</span><textarea rows="2" readonly>{{ $line['romanian_name'] }}</textarea></label>
            <label><span>Stoc inițial</span><input type="number" value="{{ $line['stock'] }}" readonly></label>
            <label><span>De comandat</span><input type="number" value="{{ $line['reorder_quantity'] }}" readonly></label>
            <label><span>Greutate (kg)</span><input type="text" value="{{ $line['weight_kg'] }}" readonly></label>
            <label><span>Preț final EUR</span><input type="text" value="{{ $line['price_with_vat_eur'] }}" readonly></label>
            <label><span>Curs EUR/RON</span><input type="text" value="{{ $draft['exchange_rate'] }}" readonly></label>
            <label><span>Preț final RON</span><input type="text" value="{{ $priceRon }}" readonly></label>
            <label>
                <span>Categorie</span>
                <select name="categorie_id" required>
                    @foreach($categorii as $categorie)
                        <option value="{{ $categorie->id }}" @selected((int) old('categorie_id', $categorieImplicita?->id) === $categorie->id)>{{ $categorie->denumire }}</option>
                    @endforeach
                </select>
            </label>
            <label>
                <span>Unitate de măsură</span>
                <select name="unitate_masura_id" required>
                    @foreach($unitatiMasura as $unitate)
                        <option value="{{ $unitate->id }}" @selected((int) old('unitate_masura_id', $unitateImplicita?->id) === $unitate->id)>{{ $unitate->cod }} — {{ $unitate->denumire }}</option>
                    @endforeach
                </select>
            </label>
            <label><span>Marcă</span><input type="text" name="marca" value="{{ old('marca', 'KYMCO') }}" maxlength="100"></label>
            <label><span>Stoc minim</span><input type="number" name="stoc_minim" value="{{ old('stoc_minim', 1) }}" min="0" step="1" required></label>
            <label class="form-check">
                <input type="hidden" name="activ" value="0">
                <input type="checkbox" name="activ" value="1" @checked((bool) old('activ', false))>
                <span>Produs activ — confirm datele și prețul</span>
            </label>
        </div>
        <div class="notice" style="margin-top:18px;margin-bottom:0">Codul FGO va fi alocat automat. Valorile provenite din registru se verifică în previzualizarea importului.</div>
        <div class="form-actions">
            <button type="submit">Salvează produsul</button>
            <a class="button-secondary" href="{{ route('stock-update.preview') }}">Înapoi la previzualizare</a>
        </div>
    </form>
@endsection
