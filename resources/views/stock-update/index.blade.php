@extends('layouts.app')

@section('title', 'Actualizare stoc · Gestiune Piese Kymco')
@section('section', 'Actualizare stoc')

@section('content')
    @if(session('status'))
        <div class="success">{{ session('status') }}</div>
    @endif
    @if($errors->any())
        <div class="notice">
            <strong>Registrul nu a fost acceptat.</strong>
            <ul>@foreach($errors->all() as $eroare)<li>{{ $eroare }}</li>@endforeach</ul>
        </div>
    @endif

    <div class="page-head">
        <div>
            <h1>Actualizare din registrul Excel</h1>
            <p class="lead">Aplicația citește registrul prestabilit și generează o previzualizare înainte de aplicare.</p>
        </div>
    </div>

    <section class="panel form-panel">
        <div class="panel-head"><h2>Registru prestabilit</h2></div>
        <form class="import-form" method="post" action="{{ route('stock-update.prepare') }}">
            @csrf
            <label><span>Fișier folosit</span><code>{{ config('stock-register.path') }}</code></label>
            <label>
                <span>Curs EUR/RON</span>
                <input type="text" name="exchange_rate" value="{{ old('exchange_rate', $defaultExchangeRate) }}" inputmode="decimal" pattern="[0-9]+([\.,][0-9]{1,4})?" required>
                <small>Valoare implicită: 1 EUR = {{ number_format((float) $defaultExchangeRate, 2, ',', '.') }} lei. Poate fi modificată înainte de previzualizare.</small>
            </label>
            <button type="submit">Previzualizează actualizarea</button>
        </form>
    </section>

    <section class="panel form-panel" style="margin-top:18px">
        <div class="panel-head"><h2>Încărcare manuală</h2></div>
        <form class="import-form" method="post" action="{{ route('stock-update.upload') }}" enctype="multipart/form-data">
            @csrf
            <label>
                <span>Alege alt registru .xlsx</span>
                <input type="file" name="registru" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required>
                <small>Fișierul prestabilit nu este înlocuit. Fișierul ales este folosit numai pentru această actualizare.</small>
            </label>
            <label>
                <span>Curs EUR/RON</span>
                <input type="text" name="exchange_rate" value="{{ old('exchange_rate', $defaultExchangeRate) }}" inputmode="decimal" pattern="[0-9]+([\.,][0-9]{1,4})?" required>
            </label>
            <button type="submit">Încarcă manual și previzualizează</button>
        </form>
    </section>

    <section class="panel quick" style="margin-top:18px">
        <h2>Reguli de import</h2>
        <ul>
            <li>Produsele sunt identificate numai prin codul din coloana „Cod - Referinta Prestashop”.</li>
            <li>Produsele lipsă nu sunt create automat; sunt afișate separat și pot fi create manual, maximum 10 într-o sesiune.</li>
            <li>Preț final RON = „Preț cu TVA” din fișier × cursul EUR/RON introdus înainte de import.</li>
            <li>Se actualizează stocul, prețul final, greutatea, numele în engleză, denumirea în română și cantitatea de comandat.</li>
            <li>Celulele goale din „Nr. produse de comandat” și stocurile negative devin 0.</li>
            <li>Nicio modificare nu se aplică înainte de confirmarea previzualizării.</li>
        </ul>
    </section>
@endsection
