@extends('layouts.app')

@section('title', 'Produse · Gestiune Piese Kymco')
@section('section', 'Produse')

@section('content')
    @if(session('status'))
        <div class="success">{{ session('status') }}</div>
    @endif
    @if($errors->any())
        <div class="notice">
            <strong>Modificările nu au fost salvate.</strong>
            <ul>
                @foreach($errors->all() as $eroare)<li>{{ $eroare }}</li>@endforeach
            </ul>
        </div>
    @endif

    <div class="page-head">
        <div>
            <h1>Produse</h1>
            <p class="lead">Catalogul local cu maparea FGO, prețuri și stoc.</p>
        </div>
        <div class="row-actions page-action">
            <form class="row-actions" method="post" action="{{ route('produse.actualizare-stoc') }}" enctype="multipart/form-data">
                @csrf
                <input type="file" name="fisier_stoc" accept=".csv,text/csv" required>
                <button type="submit">Actualizare stoc</button>
            </form>
            <a class="button-secondary" href="{{ route('produse.create') }}">Adaugă produs</a>
        </div>
    </div>

    <form class="product-filters" method="get" action="{{ route('produse.index') }}">
        <label>
            <span>Denumire produs</span>
            <input type="search" name="q" value="{{ $cautare }}" placeholder="Denumire, cod produs sau cod FGO">
        </label>
        <label>
            <span>Categorie</span>
            <select name="categorie" onchange="this.form.submit()">
                <option value="">Toate categoriile</option>
                @foreach($categorii as $categorie)
                    <option value="{{ $categorie->id }}" @selected($categorieSelectata === $categorie->id)>{{ $categorie->denumire }}</option>
                @endforeach
            </select>
        </label>
        <label>
            <span>Stoc</span>
            <select name="stoc">
                <option value="toate" @selected($filtruStoc === 'toate')>Toate</option>
                <option value="pozitiv" @selected($filtruStoc === 'pozitiv')>Pozitiv</option>
                <option value="zero" @selected($filtruStoc === 'zero')>Zero</option>
                <option value="negativ" @selected($filtruStoc === 'negativ')>Negativ</option>
            </select>
        </label>
        <button type="submit">Filtrează</button>
        @if($cautare !== '' || $categorieSelectata !== null || $filtruStoc !== 'toate')
            <a class="button-secondary" href="{{ route('produse.index') }}">Șterge filtrele</a>
        @endif
    </form>

    <section class="panel">
        <div class="panel-head">
            <h2>{{ $produse->total() }} produse</h2>
            @if($cautare !== '' || $categorieSelectata !== null || $filtruStoc !== 'toate')<span class="pill">Filtre active</span>@endif
        </div>
        @if($produse->isEmpty())
            <div class="empty">Nu există produse pentru criteriul selectat.</div>
        @else
            <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th>Cod FGO</th><th>Produs</th><th>Categorie</th><th>Stoc</th><th>De comandat</th><th>Furnizor comandă</th><th>Intrare EUR</th><th>Vânzare cu TVA</th><th>Acțiuni</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($produse as $produs)
                        @php
                            $mapare = $produs->furnizori->sortByDesc('data_ultimei_achizitii')->first();
                            $soldFirma = $produs->solduriStoc->first(fn ($sold) => $sold->gestiune?->cod === 'FIRMA');
                            $stoc = (int) ($soldFirma?->cantitate_fizica ?? 0);
                            $necesitaComanda = $stoc < (int) $produs->stoc_minim;
                            $mapariFurnizori = $produs->furnizori->unique('furnizor_id')->values();
                            $formId = 'editare-rapida-'.$produs->id;
                            $deleteFormId = 'stergere-produs-'.$produs->id;
                        @endphp
                        <tr>
                            <td>
                                <form id="{{ $formId }}" method="post" action="{{ route('produse.update-rapid', $produs) }}">
                                    @csrf
                                    @method('patch')
                                </form>
                                <form id="{{ $deleteFormId }}" method="post" action="{{ route('produse.destroy', $produs) }}" onsubmit="return confirm(@js('Ștergi definitiv produsul '.$produs->cod_produs.' '.$produs->denumire_engleza.'?'));">
                                    @csrf
                                    @method('delete')
                                </form>
                                <code>{{ $produs->cod_fgo }}</code>
                            </td>
                            <td class="name product-summary">
                                <strong>{{ $produs->cod_produs }} {{ $produs->denumire_engleza }}</strong>
                                <small>{{ $produs->descriere_romana ?: 'Fără descriere în română' }}</small>
                            </td>
                            <td><span class="pill">{{ $produs->categorie->denumire }}</span></td>
                            <td>
                                <div class="number-with-unit">
                                    <input form="{{ $formId }}" type="number" name="stoc" value="{{ $stoc }}" min="0" step="1" required>
                                    <span>{{ $produs->unitateMasura->cod }}</span>
                                </div>
                            </td>
                            <td>
                                @if($necesitaComanda)
                                    <input form="{{ $formId }}" class="quantity-order-input" type="number" name="cantitate_de_comandat" value="{{ $produs->cantitate_de_comandat }}" min="1" step="1" required>
                                @else
                                    <span>—</span>
                                @endif
                            </td>
                            <td>
                                @if($necesitaComanda && $mapariFurnizori->isNotEmpty())
                                    <select form="{{ $formId }}" class="supplier-order-select" name="furnizor_comanda_id" required>
                                        @foreach($mapariFurnizori as $mapareFurnizor)
                                            <option value="{{ $mapareFurnizor->furnizor_id }}" @selected((int) $produs->furnizor_comanda_id === $mapareFurnizor->furnizor_id)>{{ $mapareFurnizor->furnizor->denumire }}</option>
                                        @endforeach
                                    </select>
                                @elseif($necesitaComanda)
                                    <span class="danger">Fără furnizor mapat</span>
                                @else
                                    <span>—</span>
                                @endif
                            </td>
                            <td class="money">
                                @if($mapare)
                                    <strong>{{ number_format((float) $mapare->pret_achizitie_ultim, 4, '.', '') }}</strong>
                                    <small>EUR</small>
                                @else
                                    <span>Fără furnizor</span>
                                @endif
                            </td>
                            <td class="money">
                                <input form="{{ $formId }}" type="number" name="pret_vanzare_cu_tva" value="{{ number_format((float) $produs->pret_vanzare_cu_tva, 2, '.', '') }}" min="0" step="0.01" required>
                                <small>RON</small>
                            </td>
                            <td class="row-actions">
                                <button form="{{ $formId }}" type="submit">Salvează</button>
                                <a class="button-secondary" href="{{ route('produse.edit-detalii', $produs) }}">Detalii</a>
                                <button class="button-danger" form="{{ $deleteFormId }}" type="submit">Șterge</button>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <div class="pagination">{{ $produse->links() }}</div>
        @endif
    </section>
@endsection
