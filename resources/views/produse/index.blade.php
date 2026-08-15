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
        <form class="search" method="get" action="{{ route('produse.index') }}">
            <input type="search" name="q" value="{{ $cautare }}" placeholder="Cod FGO, cod produs sau denumire" aria-label="Caută produse">
            <button type="submit">Caută</button>
        </form>
    </div>

    <section class="panel">
        <div class="panel-head">
            <h2>{{ $produse->total() }} produse</h2>
            @if($cautare !== '')<span class="pill">Filtru: {{ $cautare }}</span>@endif
        </div>
        @if($produse->isEmpty())
            <div class="empty">Nu există produse pentru criteriul selectat.</div>
        @else
            <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th>Cod FGO</th><th>Produs</th><th>Categorie</th><th>Stoc</th><th>Intrare EUR</th><th>Vânzare cu TVA</th><th>Acțiuni</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($produse as $produs)
                        @php
                            $mapare = $produs->furnizori->sortByDesc('data_ultimei_achizitii')->first();
                            $soldFirma = $produs->solduriStoc->first(fn ($sold) => $sold->gestiune?->cod === 'FIRMA');
                            $stoc = (int) ($soldFirma?->cantitate_fizica ?? 0);
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
