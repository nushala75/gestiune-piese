<!doctype html>
<html lang="ro">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Autentificare · Gestiune Piese Kymco')</title>
    <style>
        :root { color-scheme:light; --navy:#17324d; --blue:#245b85; --line:#d9e2ea; --ink:#17212b; --muted:#667788; }
        * { box-sizing:border-box; }
        body { margin:0; min-height:100vh; display:grid; place-items:center; padding:24px; background:#f4f7fa; color:var(--ink); font:15px/1.5 Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif; }
        .auth-card { width:min(100%,420px); padding:28px; background:#fff; border:1px solid var(--line); border-radius:16px; box-shadow:0 14px 40px rgba(23,50,77,.10); }
        .brand { display:flex; gap:12px; align-items:center; margin-bottom:24px; }
        .brand-mark { width:44px; height:44px; display:grid; place-items:center; border-radius:12px; background:var(--navy); color:#fff; font-weight:800; }
        .brand strong,.brand small { display:block; }
        .brand small,.lead { color:var(--muted); }
        h1 { margin:0 0 5px; font-size:25px; }
        .lead { margin:0 0 22px; }
        label { display:grid; gap:5px; margin-top:14px; color:#41566a; font-weight:700; }
        input { width:100%; border:1px solid #bdcbd7; border-radius:9px; padding:10px 11px; background:#fff; color:var(--ink); font:inherit; }
        input[readonly] { background:#eef3f7; }
        button { width:100%; margin-top:20px; border:0; border-radius:9px; padding:11px 16px; background:var(--blue); color:#fff; font:inherit; font-weight:700; cursor:pointer; }
        .notice { margin-bottom:18px; padding:12px 14px; border-radius:9px; background:#fff6df; color:#765018; border:1px solid #f2d598; }
        .notice ul { margin:7px 0 0; padding-left:20px; }
    </style>
</head>
<body>
<main class="auth-card">
    <div class="brand">
        <div class="brand-mark">K</div>
        <div><strong>Gestiune Kymco</strong><small>DESIGN MEDIA BUSINESS SRL</small></div>
    </div>
    @yield('content')
</main>
</body>
</html>
