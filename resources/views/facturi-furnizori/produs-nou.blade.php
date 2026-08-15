@extends('layouts.app')

@section('title', 'Produs nou din factură · Gestiune Piese Kymco')
@section('section', 'Facturi furnizori')

@section('content')
    @if($errors->any())
        <div class="notice">
            <strong>Produsul nu a fost salvat.</strong>
            <ul>@foreach($errors->all() as $eroare)<li>{{ $eroare }}</li>@endforeach</ul>
        </div>
    @endif

    <div class="page-head">
        <div>
            <h1>Produs NOU</h1>
            <p class="lead">Poziția {{ $lineIndex + 1 }} din factura {{ $draft['invoice']['invoice_number'] }} · câmpurile disponibile au fost completate din PDF.</p>
        </div>
    </div>

    <form class="panel form-panel" method="post" action="{{ route('facturi-furnizori.produs-nou.store', ['line' => $lineIndex]) }}">
        @csrf
        <input type="hidden" name="token" value="{{ $draft['token'] }}">
        <div class="form-grid">
            <label>
                <span>Cod produs</span>
                <input type="text" name="cod_produs" value="{{ old('cod_produs', $line['supplier_code']) }}" required maxlength="64">
            </label>
            <label>
                <span>Denumire în engleză</span>
                <input type="text" name="denumire_engleza" value="{{ old('denumire_engleza', $line['description']) }}" required maxlength="255">
            </label>
            <label class="form-span-2">
                <span>Descriere în română</span>
                <textarea name="descriere_romana" rows="2">{{ old('descriere_romana') }}</textarea>
            </label>
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
            <label>
                <span>Marcă</span>
                <input type="text" name="marca" value="{{ old('marca', 'KYMCO') }}" maxlength="100">
            </label>
            <label>
                <span>Stoc minim</span>
                <input type="number" name="stoc_minim" value="{{ old('stoc_minim', 1) }}" min="0" step="1" required>
            </label>
            <label>
                <span>Preț intrare (EUR)</span>
                <input type="number" name="pret_intrare" value="{{ old('pret_intrare', $line['unit_price']) }}" min="0" step="0.0001" required>
            </label>
            <label>
                <span>Preț vânzare cu TVA (RON)</span>
                <input type="number" name="pret_vanzare_cu_tva" value="{{ old('pret_vanzare_cu_tva', $line['proposed_sale_price']) }}" min="0" step="0.01" required>
                <small>Propunere: preț intrare × 11,5.</small>
            </label>
            <label>
                <span>TVA (%)</span>
                <input type="text" value="21" disabled>
            </label>
            <label>
                <span>Greutate (kg)</span>
                <input type="number" name="greutate_kg" value="{{ old('greutate_kg') }}" min="0" step="0.001">
            </label>
            <label class="form-check">
                <input type="hidden" name="voluminos" value="0">
                <input type="checkbox" name="voluminos" value="1" @checked((bool) old('voluminos', false))>
                <span>Produs voluminos</span>
            </label>
            <label>
                <span>Lungime (cm)</span>
                <input type="number" name="lungime_cm" value="{{ old('lungime_cm') }}" min="0" step="0.01">
            </label>
            <label>
                <span>Lățime (cm)</span>
                <input type="number" name="latime_cm" value="{{ old('latime_cm') }}" min="0" step="0.01">
            </label>
            <label>
                <span>Înălțime (cm)</span>
                <input type="number" name="inaltime_cm" value="{{ old('inaltime_cm') }}" min="0" step="0.01">
            </label>
            <label class="form-check">
                <input type="hidden" name="activ" value="0">
                <input type="checkbox" name="activ" value="1" @checked((bool) old('activ', false))>
                <span>Produs activ — confirm prețul de vânzare</span>
            </label>
        </div>

        <div class="form-actions">
            <button type="submit">Salvează și mapează produsul</button>
            <a class="button-secondary" href="{{ route('facturi-furnizori.preview') }}">Înapoi la factură</a>
        </div>
    </form>
@endsection
