@extends('layouts.app')

@section('title', 'Produse · Gestiune Piese Kymco')
@section('section', 'Produse')

@section('content')
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
                        <th>Cod FGO</th><th>Produs</th><th>Categorie</th><th>Stoc</th><th>Intrare EUR</th><th>Vânzare fără TVA</th><th>Vânzare cu TVA</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($produse as $produs)
                        @php
                            $mapare = $produs->furnizori->sortByDesc('data_ultimei_achizitii')->first();
                            $stoc = (float) $produs->solduriStoc->sum(fn ($sold) => (float) $sold->cantitate_fizica);
                        @endphp
                        <tr>
                            <td><code>{{ $produs->cod_fgo }}</code></td>
                            <td class="name"><strong>{{ $produs->cod_produs }} {{ $produs->denumire_engleza }}</strong><br><small>{{ $produs->descriere_romana ?: 'Fără descriere în română' }}</small></td>
                            <td><span class="pill">{{ $produs->categorie->denumire }}</span></td>
                            <td class="{{ $stoc > 0 ? 'stock-positive' : 'stock-zero' }}">{{ number_format($stoc, 3, ',', '.') }} {{ $produs->unitateMasura->cod }}</td>
                            <td class="money">{{ $mapare?->pret_achizitie_ultim !== null ? number_format((float) $mapare->pret_achizitie_ultim, 4, ',', '.') : '—' }}</td>
                            <td class="money">{{ number_format((float) $produs->pret_vanzare_fara_tva, 4, ',', '.') }} RON</td>
                            <td class="money">{{ number_format((float) $produs->pret_vanzare_cu_tva, 2, ',', '.') }} RON</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <div class="pagination">{{ $produse->links() }}</div>
        @endif
    </section>
@endsection
