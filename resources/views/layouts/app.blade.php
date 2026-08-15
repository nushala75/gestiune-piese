<!doctype html>
<html lang="ro">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Gestiune Piese Kymco')</title>
    <style>
        :root { color-scheme: light; --navy:#17324d; --blue:#245b85; --sky:#eaf2f8; --line:#d9e2ea; --ink:#17212b; --muted:#667788; --green:#167a5b; --amber:#b46b12; }
        * { box-sizing: border-box; }
        body { margin:0; background:#f4f7fa; color:var(--ink); font:15px/1.5 Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
        a { color:inherit; }
        .shell { min-height:100vh; display:grid; grid-template-columns:250px 1fr; }
        .sidebar { background:var(--navy); color:#fff; padding:24px 18px; }
        .brand { display:flex; gap:12px; align-items:center; margin-bottom:30px; }
        .brand-mark { width:42px; height:42px; display:grid; place-items:center; border-radius:12px; background:#fff; color:var(--navy); font-weight:800; }
        .brand strong { display:block; font-size:16px; }
        .brand small { color:#b8c8d8; }
        .menu { display:grid; gap:6px; }
        .menu a, .menu span { display:flex; align-items:center; justify-content:space-between; padding:11px 12px; border-radius:9px; text-decoration:none; }
        .menu a:hover, .menu a.active { background:rgba(255,255,255,.13); }
        .menu span { color:#91a6ba; }
        .badge-soon { font-size:10px; padding:2px 6px; border:1px solid #718ba3; border-radius:999px; }
        main { min-width:0; }
        .topbar { height:72px; display:flex; justify-content:space-between; align-items:center; padding:0 32px; background:#fff; border-bottom:1px solid var(--line); }
        .topbar strong { font-size:18px; }
        .topbar-account { display:flex; align-items:center; gap:12px; }
        .company { color:var(--muted); font-size:13px; }
        .logout-button { padding:7px 10px; background:#eef3f7; color:var(--navy); font-size:12px; }
        .content { padding:22px 24px 40px; }
        .page-head { display:flex; justify-content:space-between; align-items:end; gap:20px; margin-bottom:24px; }
        h1 { margin:0 0 4px; font-size:28px; letter-spacing:-.02em; }
        .lead { margin:0; color:var(--muted); }
        .cards { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:18px; }
        .card, .panel { background:#fff; border:1px solid var(--line); border-radius:14px; box-shadow:0 8px 24px rgba(23,50,77,.05); }
        .card { padding:22px; }
        .card span { color:var(--muted); }
        .card strong { display:block; margin-top:6px; font-size:30px; }
        .panel { overflow:hidden; }
        .panel-head { padding:12px 14px; border-bottom:1px solid var(--line); display:flex; justify-content:space-between; align-items:center; }
        .panel-head h2 { margin:0; font-size:17px; }
        .quick { margin-top:20px; padding:20px; }
        .quick-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:12px; margin-top:14px; }
        .quick a, .quick span { padding:16px; border:1px solid var(--line); border-radius:10px; text-decoration:none; background:#fbfdff; }
        .quick span { color:var(--muted); }
        .search { display:flex; gap:8px; }
        .product-filters { margin-bottom:18px; padding:14px; display:flex; flex-wrap:wrap; align-items:end; gap:10px; background:#fff; border:1px solid var(--line); border-radius:12px; }
        .product-filters label { display:grid; gap:4px; color:#41566a; font-size:12px; font-weight:700; }
        .product-filters label:first-child { flex:1 1 300px; }
        .product-filters input, .product-filters select { min-width:180px; }
        .product-filters .button-secondary { width:auto; margin:0; padding:8px 12px; }
        input[type=search] { min-width:300px; border:1px solid #bdcbd7; border-radius:9px; padding:10px 12px; font:inherit; }
        button { border:0; border-radius:9px; padding:10px 16px; background:var(--blue); color:#fff; font:inherit; font-weight:700; cursor:pointer; }
        .button-danger { background:#b91c1c; }
        .button-secondary.button-danger { background:#b91c1c; color:#fff; border-color:#991b1b; }
        input, textarea, select { border:1px solid #bdcbd7; border-radius:8px; padding:8px 9px; background:#fff; color:var(--ink); font:inherit; }
        textarea { resize:vertical; }
        .table-wrap { overflow:auto; }
        table { width:100%; border-collapse:collapse; white-space:nowrap; font-size:12px; }
        th { padding:7px 8px; text-align:left; background:#edf3f8; color:#41566a; font-size:10px; text-transform:uppercase; letter-spacing:.03em; }
        td { padding:7px 8px; border-top:1px solid #e7edf2; vertical-align:middle; }
        td.name { white-space:normal; min-width:220px; }
        .product-summary strong, .product-summary small { display:block; }
        .product-summary small { margin-top:2px; color:var(--muted); font-size:11px; }
        td.money input { width:84px; padding:5px 6px; text-align:right; font-variant-numeric:tabular-nums; }
        td.money small { display:block; margin-top:3px; color:var(--muted); }
        .number-with-unit { display:flex; align-items:center; gap:6px; }
        .number-with-unit input { width:62px; padding:5px 6px; }
        .quantity-order-input { width:72px; padding:5px 6px; }
        .supplier-order-select { width:170px; padding:5px 6px; }
        .row-actions { min-width:126px; display:flex; gap:4px; }
        .row-actions button { width:auto; padding:6px 8px; border-radius:6px; font-size:11px; }
        .button-secondary { display:inline-block; margin-top:7px; width:100%; padding:8px 10px; border:1px solid #9eb2c4; border-radius:8px; color:var(--blue); background:#fff; text-align:center; text-decoration:none; font-weight:700; }
        .page-action { width:auto; margin:0; padding:9px 14px; }
        .row-actions .button-secondary { width:auto; margin:0; padding:5px 7px; border-radius:6px; font-size:11px; }
        .row-actions .button-danger { width:auto; margin:0; padding:5px 7px; border-radius:6px; font-size:11px; }
        .form-panel { max-width:900px; padding:22px; }
        .form-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:18px; }
        .form-grid label { display:block; }
        .form-grid label > span { display:block; margin-bottom:5px; color:#41566a; font-weight:700; }
        .form-grid input, .form-grid select { width:100%; }
        .form-grid textarea { width:100%; }
        .form-span-2 { grid-column:1 / -1; }
        .form-check { display:flex !important; align-items:center; gap:9px; padding-top:28px; }
        .form-check input { width:auto; }
        .form-check span { margin:0 !important; }
        .form-actions { display:flex; gap:10px; margin-top:22px; }
        .form-actions .button-secondary { width:auto; margin:0; padding:9px 16px; }
        code { padding:3px 6px; border-radius:5px; background:#eef3f7; color:#294b69; }
        .pill { display:inline-block; padding:3px 8px; border-radius:999px; font-size:12px; background:var(--sky); color:var(--blue); }
        .stock-positive { color:var(--green); font-weight:800; }
        .stock-zero { color:var(--amber); font-weight:800; }
        .money { text-align:right; font-variant-numeric:tabular-nums; }
        .empty { padding:40px; text-align:center; color:var(--muted); }
        .notice { margin-bottom:20px; padding:14px 16px; border-radius:10px; background:#fff6df; color:#765018; border:1px solid #f2d598; }
        .notice ul { margin:8px 0 0; }
        .success { margin-bottom:20px; padding:14px 16px; border-radius:10px; background:#e8f7f1; color:#126148; border:1px solid #abdcca; }
        .danger { color:#9b2c2c; font-weight:700; }
        .import-form { padding:20px; display:flex; flex-wrap:wrap; align-items:end; gap:12px; }
        .import-form label { display:grid; gap:5px; min-width:min(100%,420px); color:#41566a; font-weight:700; }
        .import-form input[type=file] { width:100%; }
        .invoice-summary { display:grid; grid-template-columns:repeat(5,minmax(0,1fr)); gap:10px; margin-bottom:18px; }
        .invoice-summary div { padding:12px; border:1px solid var(--line); border-radius:10px; background:#fff; }
        .invoice-summary small, .invoice-summary strong { display:block; }
        .invoice-summary small { color:var(--muted); }
        .preview-table input, .preview-table select { padding:5px 6px; font-size:11px; }
        .preview-table .code-input { width:142px; }
        .preview-table .description-input { width:260px; }
        .preview-table .quantity-input { width:58px; }
        .preview-table .amount-input { width:82px; text-align:right; }
        .preview-table .product-select { width:250px; }
        .row-warning { background:#fff8e8; }
        .status-mapped { color:var(--green); font-weight:800; }
        .status-unmapped { color:var(--amber); font-weight:800; }
        .price-confirm-inline { display:flex; align-items:center; gap:5px; margin-top:5px; white-space:nowrap; color:#b91c1c; }
        .price-confirm-inline input { width:78px; padding:4px 5px; font-size:11px; }
        .price-confirm-inline button { width:auto; margin:0; padding:4px 8px; font-size:11px; }
        .price-confirm-inline small { font-size:10px; }
        .confirm-bar { padding:16px 18px; border-top:1px solid var(--line); display:flex; flex-wrap:wrap; align-items:end; justify-content:space-between; gap:14px; }
        .confirm-bar label { display:grid; gap:5px; color:#41566a; font-weight:700; }
        nav.pagination { padding:16px 20px; border-top:1px solid var(--line); }
        nav.pagination svg { width:18px; }
        @media (max-width:900px) { .shell{grid-template-columns:1fr}.sidebar{padding:16px}.menu{grid-template-columns:repeat(2,1fr)}.topbar{padding:0 18px}.content{padding:22px 18px}.cards{grid-template-columns:1fr}.quick-grid{grid-template-columns:1fr}.page-head{align-items:stretch;flex-direction:column}.search{width:100%}input[type=search]{min-width:0;flex:1}.company{display:none}.form-grid{grid-template-columns:1fr} }
    </style>
</head>
<body>
<div class="shell">
    <aside class="sidebar">
        <div class="brand">
            <div class="brand-mark">K</div>
            <div><strong>Gestiune Kymco</strong><small>Piese și stoc</small></div>
        </div>
        <nav class="menu" aria-label="Meniu principal">
            <a href="{{ route('dashboard') }}" @class(['active' => request()->routeIs('dashboard')])>Panou principal</a>
            <a href="{{ route('produse.index') }}" @class(['active' => request()->routeIs('produse.*')])>Produse</a>
            <a href="{{ route('facturi-furnizori.index') }}" @class(['active' => request()->routeIs('facturi-furnizori.*')])>Facturi furnizori</a>
            <span>Recepții</span>
            <span>Stoc <b class="badge-soon">în curând</b></span>
            <span>Export SAGA <b class="badge-soon">în curând</b></span>
            <span>Export FGO <b class="badge-soon">în curând</b></span>
            <span>Jurnal audit <b class="badge-soon">în curând</b></span>
        </nav>
    </aside>
    <main>
        <header class="topbar">
            <strong>@yield('section', 'Panou principal')</strong>
            <div class="topbar-account">
                <div class="company">{{ auth()->user()->email }} · DESIGN MEDIA BUSINESS SRL · FIRMA</div>
                <form method="post" action="{{ route('logout') }}">
                    @csrf
                    <button class="logout-button" type="submit">Ieșire</button>
                </form>
            </div>
        </header>
        <div class="content">@yield('content')</div>
    </main>
</div>
</body>
</html>
