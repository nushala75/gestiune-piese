@extends('layouts.auth')

@section('title', 'Autentificare · Gestiune Piese Kymco')

@section('content')
    <h1>Autentificare</h1>
    <p class="lead">Introdu datele administratorului pentru a continua.</p>

    @if($errors->any())
        <div class="notice">
            <strong>Autentificarea nu a reușit.</strong>
            <ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif

    <form method="post" action="{{ route('login.store') }}">
        @csrf
        <label>
            <span>E-mail</span>
            <input type="email" name="email" value="{{ old('email', 'nusescu@gmail.com') }}" autocomplete="username" required autofocus>
        </label>
        <label>
            <span>Parolă</span>
            <input type="password" name="password" autocomplete="current-password" required>
        </label>
        <button type="submit">Intră în aplicație</button>
    </form>
@endsection
