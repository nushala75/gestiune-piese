@extends('layouts.app')

@section('title', 'Adaugă produs · Gestiune Piese Kymco')
@section('section', 'Produse')

@section('content')
    @if($errors->any())
        <div class="notice">
            <strong>Produsul nu a fost salvat.</strong>
            <ul>@foreach($errors->all() as $eroare)<li>{{ $eroare }}</li>@endforeach</ul>
        </div>
    @endif

    <div class="page-head">
        <div>
            <h1>Adaugă produs</h1>
            <p class="lead">Codul FGO va fi alocat automat la salvare.</p>
        </div>
    </div>

    <form class="panel form-panel" method="post" action="{{ route('produse.store') }}">
        @csrf
        <div class="form-grid">
            <label>
                <span>Cod produs</span>
                <input type="text" name="cod_produs" value="{{ old('cod_produs') }}" required maxlength="64" autofocus>
            </label>
            <label>
                <span>Denumire în engleză</span>
                <input type="text" name="denumire_engleza" value="{{ old('denumire_engleza') }}" required maxlength="255">
            </label>
            <label class="form-span-2">
                <span>Descriere în română</span>
                <textarea name="descriere_romana" rows="2">{{ old('descriere_romana') }}</textarea>
            </label>
            <label>
                <span>Categorie</span>
                <select name="categorie_id" required>
                    @foreach($categorii as $categorie)
                        <option value="{{ $categorie->id }}" @selected((int) old('categorie_id', $categorieImplicita) === $categorie->id)>{{ $categorie->denumire }}</option>
                    @endforeach
                </select>
            </label>
            <label>
                <span>Unitate de măsură</span>
                <select name="unitate_masura_id" required>
                    @foreach($unitatiMasura as $unitate)
                        <option value="{{ $unitate->id }}" @selected(old('unitate_masura_id') !== null ? (int) old('unitate_masura_id') === $unitate->id : $unitate->cod === 'BUC')>{{ $unitate->cod }} — {{ $unitate->denumire }}</option>
                    @endforeach
                </select>
            </label>
            <label>
                <span>Marcă</span>
                <input type="text" name="marca" value="{{ old('marca', 'KYMCO') }}" maxlength="100">
            </label>
            <label>
                <span>Stoc minim</span>
                <input type="number" name="stoc_minim" value="{{ old('stoc_minim', 1) }}" min="0" step="1" required>
            </label>
            <label>
                <span>Stoc actual</span>
                <input type="number" name="stoc" value="{{ old('stoc', 0) }}" min="0" step="1" required>
            </label>
            <label>
                <span>Preț intrare</span>
                <input type="text" value="Se completează după asocierea furnizorului" disabled>
            </label>
            <label>
                <span>Preț vânzare cu TVA (RON)</span>
                <input type="number" name="pret_vanzare_cu_tva" value="{{ old('pret_vanzare_cu_tva') }}" min="0" step="0.01">
            </label>
            <label>
                <span>TVA</span>
                <input type="text" value="21%" disabled>
            </label>
            <label class="form-check">
                <input type="hidden" name="activ" value="0">
                <input type="checkbox" name="activ" value="1" @checked((bool) old('activ', false))>
                <span>Produs activ</span>
            </label>
        </div>
        <div class="notice" style="margin-top:18px;margin-bottom:0">Pentru activare sunt obligatorii descrierea în română și prețul de vânzare cu TVA.</div>
        <div class="form-actions">
            <button type="submit">Salvează produsul</button>
            <a class="button-secondary" href="{{ route('produse.index') }}">Renunță</a>
        </div>
    </form>
@endsection
