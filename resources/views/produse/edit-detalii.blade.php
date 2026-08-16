@extends('layouts.app')

@section('title', 'Editare detalii produs · Gestiune Piese Kymco')
@section('section', 'Produse')

@section('content')
    @if(session('status'))
        <div class="success">{{ session('status') }}</div>
    @endif
    @if($errors->any())
        <div class="notice">
            <strong>Detaliile nu au fost salvate.</strong>
            <ul>
                @foreach($errors->all() as $eroare)<li>{{ $eroare }}</li>@endforeach
            </ul>
        </div>
    @endif

    <div class="page-head">
        <div>
            <h1>Edit detalii</h1>
            <p class="lead">{{ $produs->cod_produs }} {{ $produs->denumire_engleza }}</p>
        </div>
    </div>

    <form class="panel form-panel" method="post" action="{{ route('produse.update-detalii', $produs) }}">
        @csrf
        @method('patch')

        <div class="form-grid">
            <label>
                <span>Cod FGO</span>
                <input type="text" name="cod_fgo" value="{{ old('cod_fgo', $produs->cod_fgo) }}" required minlength="8" maxlength="8" inputmode="numeric" pattern="[0-9]{8}">
                <small>Unic, exact 8 cifre.</small>
            </label>
            <label>
                <span>Cod produs</span>
                <input type="text" name="cod_produs" value="{{ old('cod_produs', $produs->cod_produs) }}" required maxlength="64">
            </label>
            <label>
                <span>Denumire în engleză</span>
                <input type="text" name="denumire_engleza" value="{{ old('denumire_engleza', $produs->denumire_engleza) }}" required maxlength="255">
            </label>
            <label class="form-span-2">
                <span>Descriere în română</span>
                <textarea name="descriere_romana" rows="2">{{ old('descriere_romana', $produs->descriere_romana) }}</textarea>
            </label>
            <label>
                <span>Categorie</span>
                <select name="categorie_id" required>
                    @foreach($categorii as $categorie)
                        <option value="{{ $categorie->id }}" @selected((int) old('categorie_id', $produs->categorie_id) === $categorie->id)>{{ $categorie->denumire }}</option>
                    @endforeach
                </select>
            </label>
            <label>
                <span>Unitate de măsură</span>
                <select name="unitate_masura_id" required>
                    @foreach($unitatiMasura as $unitate)
                        <option value="{{ $unitate->id }}" @selected((int) old('unitate_masura_id', $produs->unitate_masura_id) === $unitate->id)>{{ $unitate->cod }} — {{ $unitate->denumire }}</option>
                    @endforeach
                </select>
            </label>
            <label>
                <span>Marcă</span>
                <input type="text" name="marca" value="{{ old('marca', $produs->marca) }}" maxlength="100">
            </label>
            <label>
                <span>Stoc minim</span>
                <input type="number" name="stoc_minim" value="{{ old('stoc_minim', $produs->stoc_minim) }}" min="0" step="1" required>
            </label>
            <label>
                <span>Stoc actual</span>
                <input type="number" name="stoc" value="{{ old('stoc', $stoc) }}" min="0" step="1" required>
            </label>
            <label>
                <span>Cantitate de comandat</span>
                <input type="number" name="cantitate_de_comandat" value="{{ old('cantitate_de_comandat', $produs->cantitate_de_comandat) }}" min="0" step="1" required>
            </label>
            <label>
                <span>Furnizor comandă</span>
                <select name="furnizor_comanda_id">
                    <option value="">— Fără furnizor selectat —</option>
                    @foreach($produs->furnizori->unique('furnizor_id')->values() as $mapareComanda)
                        <option value="{{ $mapareComanda->furnizor_id }}" @selected((string) old('furnizor_comanda_id', $produs->furnizor_comanda_id) === (string) $mapareComanda->furnizor_id)>
                            {{ $mapareComanda->furnizor->denumire }}
                        </option>
                    @endforeach
                </select>
                <small>Poate fi ales doar dintre furnizorii deja mapați produsului.</small>
            </label>
            <label>
                <span>Preț intrare (EUR)</span>
                @if($mapareFurnizor)
                    <input type="number" name="pret_intrare" value="{{ old('pret_intrare', number_format((float) $mapareFurnizor->pret_achizitie_ultim, 4, '.', '')) }}" min="0" step="0.0001" required>
                @else
                    <input type="text" value="Fără furnizor asociat" disabled>
                @endif
            </label>
            <label>
                <span>Preț vânzare cu TVA (RON)</span>
                <input type="number" name="pret_vanzare_cu_tva" value="{{ old('pret_vanzare_cu_tva', number_format((float) $produs->pret_vanzare_cu_tva, 2, '.', '')) }}" min="0" step="0.01" required>
                <small>Prețul fără TVA se calculează automat la salvare.</small>
            </label>
            <label>
                <span>TVA (%)</span>
                <input type="number" name="cota_tva" value="{{ old('cota_tva', number_format((float) $produs->cota_tva, 2, '.', '')) }}" min="0" max="100" step="0.01" required>
            </label>
            <label>
                <span>Greutate (kg)</span>
                <input type="number" name="greutate_kg" value="{{ old('greutate_kg', $produs->greutate_kg) }}" min="0" step="0.001">
            </label>
            <label class="form-check">
                <input type="hidden" name="voluminos" value="0">
                <input type="checkbox" name="voluminos" value="1" @checked((bool) old('voluminos', $produs->voluminos))>
                <span>Produs voluminos</span>
            </label>
            <label>
                <span>Lungime (cm)</span>
                <input type="number" name="lungime_cm" value="{{ old('lungime_cm', $produs->lungime_cm) }}" min="0" step="0.01">
            </label>
            <label>
                <span>Lățime (cm)</span>
                <input type="number" name="latime_cm" value="{{ old('latime_cm', $produs->latime_cm) }}" min="0" step="0.01">
            </label>
            <label>
                <span>Înălțime (cm)</span>
                <input type="number" name="inaltime_cm" value="{{ old('inaltime_cm', $produs->inaltime_cm) }}" min="0" step="0.01">
            </label>
            <label class="form-check">
                <input type="hidden" name="activ" value="0">
                <input type="checkbox" name="activ" value="1" @checked((bool) old('activ', $produs->activ))>
                <span>Produs activ</span>
            </label>
        </div>

        <div class="form-actions">
            <button type="submit">Salvează detaliile</button>
            <a class="button-secondary" href="{{ route('produse.index') }}">Înapoi la produse</a>
        </div>
    </form>
@endsection
