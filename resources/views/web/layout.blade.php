<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>@yield('title')</title>
    <style>
        body { margin: 0; padding: 48px 16px; background: #f4f4f5; font-family: -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; color: #18181b; }
        .card { max-width: 480px; margin: 0 auto; background: #fff; border-radius: 10px; padding: 40px; box-shadow: 0 1px 3px rgba(0,0,0,.08); }
        h1 { font-size: 22px; margin: 0 0 12px; }
        p { font-size: 15px; line-height: 1.6; color: #3f3f46; margin: 0 0 16px; }
        a { color: #18181b; }
        .button { display: inline-block; background: #18181b; color: #fff; font-size: 15px; font-weight: 600; text-decoration: none; padding: 10px 24px; border-radius: 6px; border: 0; cursor: pointer; }
        label { display: flex; gap: 10px; align-items: center; font-size: 15px; padding: 10px 0; border-bottom: 1px solid #f4f4f5; }
        input[type="text"] { width: 100%; box-sizing: border-box; font-size: 15px; padding: 8px 10px; border: 1px solid #d4d4d8; border-radius: 6px; }
        .field { margin-bottom: 14px; }
        .field span { display: block; font-size: 13px; color: #71717a; margin-bottom: 4px; }
        .success { background: #ecfdf5; color: #065f46; border-radius: 6px; padding: 10px 14px; font-size: 14px; margin-bottom: 16px; }
    </style>
</head>
<body>
    <div class="card">
        @yield('content')
    </div>
</body>
</html>
