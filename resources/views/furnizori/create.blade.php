@extends('layouts.app')

@section('title', 'Adaugă furnizor · Gestiune Piese Kymco')
@section('section', 'Furnizori')

@section('content')
    <div class="page-head"><div><h1>Adaugă furnizor</h1><p class="lead">Completează datele de identificare și moneda folosită implicit.</p></div></div>
    <form class="panel form-panel" method="post" action="{{ route('furnizori.store') }}">
        @csrf
        @include('furnizori._form', ['furnizor' => null])
        <div class="form-actions"><button type="submit">Salvează furnizorul</button><a class="button-secondary" href="{{ route('furnizori.index') }}">Renunță</a></div>
    </form>
@endsection
