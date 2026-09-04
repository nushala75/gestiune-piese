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
            <button type="submit">Actualizare stoc și prețuri</button>
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
            <button type="submit">Încarcă manual și previzualizează</button>
        </form>
    </section>

    <section class="panel quick" style="margin-top:18px">
        <h2>Reguli de import</h2>
        <ul>
            <li>Produsele sunt identificate numai prin codul din coloana „Cod - Referinta Prestashop”.</li>
            <li>Produsele lipsă nu sunt create și sunt afișate separat în previzualizare.</li>
            <li>Preț final RON = „Preț cu TVA” din fișier × cursul BNR EUR afișat la import.</li>
            <li>Stocul și prețul final sunt actualizate împreună după confirmarea previzualizării.</li>
        </ul>
    </section>
@endsection
