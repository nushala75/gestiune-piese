@if($errors->any())
    <div class="notice">
        <strong>Furnizorul nu a fost salvat.</strong>
        <ul>@foreach($errors->all() as $eroare)<li>{{ $eroare }}</li>@endforeach</ul>
    </div>
@endif

<div class="form-grid">
    <label>
        <span>Denumire</span>
        <input type="text" name="denumire" value="{{ old('denumire', $furnizor?->denumire) }}" required maxlength="190" autofocus>
    </label>
    <label>
        <span>VAT / Cod fiscal</span>
        <input type="text" name="cod_fiscal" value="{{ old('cod_fiscal', $furnizor?->cod_fiscal) }}" required maxlength="32">
    </label>
    <label>
        <span>Țară (cod ISO)</span>
        <input type="text" name="tara" value="{{ old('tara', $furnizor?->tara) }}" required minlength="2" maxlength="2">
    </label>
    <label>
        <span>Monedă implicită</span>
        <input type="text" name="moneda_implicita" value="{{ old('moneda_implicita', $furnizor?->moneda_implicita ?? 'EUR') }}" required minlength="3" maxlength="3">
    </label>
    <label class="form-span-2">
        <span>Adresă</span>
        <textarea name="adresa" rows="3" maxlength="500">{{ old('adresa', $furnizor?->adresa) }}</textarea>
    </label>
    <label class="form-check">
        <input type="hidden" name="activ" value="0">
        <input type="checkbox" name="activ" value="1" @checked((bool) old('activ', $furnizor?->activ ?? true))>
        <span>Furnizor activ</span>
    </label>
</div>
