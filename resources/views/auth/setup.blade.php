@extends('layouts.auth')

@section('title', 'Configurare administrator · Gestiune Piese Kymco')

@section('content')
    <h1>Configurare administrator</h1>
    <p class="lead">Această pagină se dezactivează automat după crearea administratorului.</p>

    @if(!$schemaDisponibila)
        <div class="notice">Aplică mai întâi migrarea <strong>009_autentificare_admin</strong>, apoi reîncarcă pagina.</div>
    @else
        @if($errors->any())
            <div class="notice">
                <strong>Administratorul nu a fost creat.</strong>
                <ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
            </div>
        @endif

        <form method="post" action="{{ route('admin.setup.store') }}">
            @csrf
            <label>
                <span>E-mail administrator</span>
                <input type="email" value="{{ $adminEmail }}" readonly>
            </label>
            <label>
                <span>Parolă nouă</span>
                <input type="password" name="password" minlength="12" autocomplete="new-password" required autofocus>
            </label>
            <label>
                <span>Confirmă parola</span>
                <input type="password" name="password_confirmation" minlength="12" autocomplete="new-password" required>
            </label>
            <button type="submit">Creează administratorul</button>
        </form>
    @endif
@endsection
