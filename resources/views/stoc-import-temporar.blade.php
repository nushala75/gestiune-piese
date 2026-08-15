<!doctype html>
<html lang="ro">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Actualizare stoc temporară</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 900px; margin: 40px auto; padding: 0 20px; color: #222; }
        .card { border: 1px solid #ddd; border-radius: 10px; padding: 24px; margin-bottom: 20px; }
        .ok { background: #eef9ee; border-color: #9ac69a; }
        .err { background: #fff0f0; border-color: #d99; }
        .warn { background: #fff9e8; border-color: #e0c36d; }
        button { padding: 10px 16px; cursor: pointer; }
        ul { max-height: 260px; overflow: auto; }
        code { background: #f4f4f4; padding: 2px 4px; }
    </style>
</head>
<body>
<h1>Actualizare stoc — import unic</h1>

@if (session('status'))
    <div class="card ok">{{ session('status') }}</div>
@endif

@if ($errors->any())
    <div class="card err">
        <strong>Importul nu a fost aplicat.</strong>
        <ul>
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

@if ($finalizat)
    <div class="card ok">
        <strong>Importul unic este deja finalizat.</strong>
        <p>Ruta este blocată și nu mai acceptă alte actualizări.</p>
    </div>
@else
    <div class="card">
        <h2>1. Încarcă CSV</h2>
        <p>Se folosesc numai primele două coloane, care trebuie să fie <code>Cod</code> și <code>Quantity</code>.</p>
        <p>Stocurile negative devin 0. Produsele din catalog care nu apar în CSV devin 0.</p>
        <p><code>91202-KNBN-92A</code> și <code>91201-KNBN-92A</code> sunt forțate la 1 buc.</p>
        <form method="post" action="{{ route('stoc-import-temporar.preview') }}" enctype="multipart/form-data">
            @csrf
            <input type="file" name="stoc_csv" accept=".csv,text/csv,text/plain" required>
            <button type="submit">Verifică fișierul</button>
        </form>
    </div>

    @if (is_array($preview))
        <div class="card {{ !empty($preview['ambiguous_codes']) ? 'err' : 'warn' }}">
            <h2>2. Previzualizare</h2>
            <p>Coduri distincte din CSV: <strong>{{ $preview['rows'] }}</strong></p>
            <p>Coduri găsite în catalog: <strong>{{ $preview['matched_codes'] }}</strong></p>
            <p>Coduri cu stoc pozitiv în CSV: <strong>{{ $preview['positive_codes'] }}</strong></p>
            <p>Coduri cu stoc 0 după normalizare: <strong>{{ $preview['zero_codes'] }}</strong></p>
            <p>Coduri negăsite în catalog: <strong>{{ count($preview['unmatched_codes'] ?? []) }}</strong></p>

            @if (!empty($preview['unmatched_codes']))
                <details>
                    <summary>Vezi codurile negăsite</summary>
                    <ul>
                        @foreach ($preview['unmatched_codes'] as $code)
                            <li><code>{{ $code }}</code></li>
                        @endforeach
                    </ul>
                </details>
            @endif

            @if (!empty($preview['ambiguous_codes']))
                <p><strong>Importul este blocat deoarece următoarele coduri au stoc pozitiv și corespund mai multor produse:</strong></p>
                <ul>
                    @foreach ($preview['ambiguous_codes'] as $code)
                        <li><code>{{ $code }}</code></li>
                    @endforeach
                </ul>
            @else
                <form method="post" action="{{ route('stoc-import-temporar.apply') }}">
                    @csrf
                    <label>
                        <input type="checkbox" name="confirmare" value="1" required>
                        Confirm înlocuirea completă a stocurilor curente conform regulilor afișate.
                    </label>
                    <p><button type="submit">Aplică definitiv și blochează importul</button></p>
                </form>
            @endif
        </div>
    @endif
@endif
</body>
</html>
