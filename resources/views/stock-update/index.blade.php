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
            <p class="lead">Poți actualiza aplicația din registru sau poți rescrie registrul local cu datele curente din aplicație.</p>
        </div>
    </div>

    <section class="panel form-panel">
        <div class="panel-head"><h2>Actualizare din aplicație în fișierul local</h2></div>
        <form id="local-register-update-form" class="import-form" method="post" action="{{ route('stock-update.local-file') }}">
            @csrf
            <label>
                <span>Curs EUR/RON</span>
                <input type="text" name="exchange_rate" value="{{ old('exchange_rate', $defaultExchangeRate) }}" inputmode="decimal" pattern="[0-9]+([\.,][0-9]{1,4})?" required>
                <small>Prețul fără TVA din aplicație se împarte la acest curs pentru coloana „Preț fără TVA” din Excel.</small>
            </label>
            <button id="local-register-update-button" type="button">Actualizare din aplicație în local</button>
            <small>Alege registrul din <code>K:\Codex\piese-kymco\registru-produse-kymco.xlsx</code>. Fișierul selectat este înlocuit numai după o actualizare reușită.</small>
        </form>
        <div id="local-register-update-status" class="notice" style="display:none; margin:0 20px 20px" role="status"></div>
    </section>

    <section class="panel form-panel" style="margin-top:18px">
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

    <script>
        (() => {
            const form = document.getElementById('local-register-update-form');
            const button = document.getElementById('local-register-update-button');
            const status = document.getElementById('local-register-update-status');

            const showStatus = (message, success = false) => {
                status.textContent = message;
                status.className = success ? 'success' : 'notice';
                status.style.display = 'block';
                status.style.margin = '0 20px 20px';
            };

            button.addEventListener('click', async () => {
                if (!window.showOpenFilePicker) {
                    showStatus('Actualizarea directă a fișierului local necesită Google Chrome sau Microsoft Edge actualizat.');
                    return;
                }

                const exchangeRate = form.querySelector('[name="exchange_rate"]');
                if (!exchangeRate.reportValidity()) {
                    return;
                }

                button.disabled = true;
                button.textContent = 'Se actualizează…';
                status.style.display = 'none';

                try {
                    const [handle] = await window.showOpenFilePicker({
                        id: 'registru-produse-kymco',
                        multiple: false,
                        types: [{
                            description: 'Registru produse Kymco (.xlsx)',
                            accept: {
                                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet': ['.xlsx'],
                            },
                        }],
                    });
                    const file = await handle.getFile();
                    const data = new FormData();
                    data.append('_token', form.querySelector('[name="_token"]').value);
                    data.append('exchange_rate', exchangeRate.value);
                    data.append('registru', file, file.name);

                    const response = await fetch(form.action, {
                        method: 'POST',
                        headers: { 'Accept': 'application/json' },
                        body: data,
                    });
                    if (!response.ok) {
                        const result = await response.json().catch(() => ({}));
                        const messages = Object.values(result.errors || {}).flat();
                        throw new Error(messages.join(' ') || result.message || 'Registrul nu a putut fi actualizat.');
                    }

                    const updatedWorkbook = await response.blob();
                    const currentFile = await handle.getFile();
                    if (currentFile.lastModified !== file.lastModified || currentFile.size !== file.size) {
                        throw new Error('Fișierul local s-a modificat în timpul actualizării. Repetă operația pentru a evita suprascrierea unor schimbări noi.');
                    }
                    const writable = await handle.createWritable();
                    await writable.write(updatedWorkbook);
                    await writable.close();

                    const count = response.headers.get('X-Kymco-Updated-Products');
                    showStatus(`Registrul local a fost actualizat cu ${count || 'toate'} produsele găsite în aplicație.`, true);
                } catch (error) {
                    if (error.name !== 'AbortError') {
                        const message = error.name === 'NoModificationAllowedError'
                            ? 'Fișierul este deschis sau blocat. Închide Excel/LibreOffice și încearcă din nou.'
                            : error.message;
                        showStatus(message || 'Registrul nu a putut fi actualizat.');
                    }
                } finally {
                    button.disabled = false;
                    button.textContent = 'Actualizare din aplicație în local';
                }
            });
        })();
    </script>
@endsection
