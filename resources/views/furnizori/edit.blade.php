@extends('layouts.app')

@section('title', 'Editează furnizor · Gestiune Piese Kymco')
@section('section', 'Furnizori')

@section('content')
    <div class="page-head"><div><h1>Editează furnizor</h1><p class="lead">{{ $furnizor->denumire }}</p></div></div>
    <form class="panel form-panel" method="post" action="{{ route('furnizori.update', $furnizor) }}">
        @csrf
        @method('PATCH')
        @include('furnizori._form', ['furnizor' => $furnizor])
        <div class="form-actions"><button type="submit">Salvează modificările</button><a class="button-secondary" href="{{ route('furnizori.index') }}">Renunță</a></div>
    </form>
@endsection
