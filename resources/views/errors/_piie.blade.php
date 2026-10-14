{{--
    Shared, self-contained error page. Deliberately NO database access, NO get_phrase(),
    NO auth()->user() assumptions and NO external assets: it must render even when the
    application or database is what failed. Never shows exception details.
    Inputs: $code, $heading, $message, $actions (label => url|'back'|'reload').
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $code }} — {{ $heading }} | {{ config('app.name', 'PIIE') }}</title>
    <style>
        :root { --ink: #101828; --muted: #475467; --line: #e4e7ec; --brand: #1a3a6b; --brand-ink: #ffffff; --bg: #f5f7fb; }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 24px 16px;
               background: var(--bg); color: var(--ink); font-family: system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif; }
        .card { width: 100%; max-width: 520px; background: #fff; border: 1px solid var(--line); border-radius: 14px; padding: 36px 32px; text-align: center;
                box-shadow: 0 10px 30px rgba(16, 24, 40, .06); }
        .brand { font-size: 13px; font-weight: 600; letter-spacing: .04em; color: var(--brand); text-transform: uppercase; margin-bottom: 18px; }
        .code { font-size: 64px; font-weight: 800; line-height: 1; color: var(--brand); margin: 0 0 12px; }
        h1 { font-size: 22px; margin: 0 0 10px; }
        p { color: var(--muted); font-size: 15px; line-height: 1.55; margin: 0 0 24px; }
        .actions { display: flex; flex-wrap: wrap; gap: 10px; justify-content: center; }
        .btn { display: inline-block; padding: 10px 18px; border-radius: 8px; font-size: 14px; font-weight: 600; text-decoration: none;
               border: 1px solid var(--brand); color: var(--brand); background: #fff; cursor: pointer; font-family: inherit; }
        .btn.primary { background: var(--brand); color: var(--brand-ink); }
        .btn:focus-visible { outline: 3px solid #93b4f5; outline-offset: 2px; }
        .ref { margin-top: 22px; font-size: 12px; color: #98a2b3; }
    </style>
@include('shared.typography')
</head>
<body>
    <main class="card" role="main">
        <div class="brand">{{ config('app.name', 'PIIE') }}</div>
        <div class="code" aria-hidden="true">{{ $code }}</div>
        <h1>{{ $heading }}</h1>
        <p>{{ $message }}</p>
        <div class="actions">
            @foreach ($actions as $label => $target)
                @if ($target === 'back')
                    <a class="btn" href="javascript:history.back()">{{ $label }}</a>
                @elseif ($target === 'reload')
                    <a class="btn primary" href="javascript:location.reload()">{{ $label }}</a>
                @else
                    <a class="btn {{ $loop->first ? 'primary' : '' }}" href="{{ $target }}">{{ $label }}</a>
                @endif
            @endforeach
        </div>
        <div class="ref">Error {{ $code }} · {{ now()->format('Y-m-d H:i') }}</div>
    </main>
</body>
</html>
