{{-- Shared chrome for Email Subscribe CP pages. Self-contained styles so
     the pages look right on both Statamic 5 and 6 control panels. --}}
<style>
    .es-wrap { max-width: 1100px; margin: 0 auto; }
    .es-header { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 20px; flex-wrap: wrap; }
    .es-header h1 { font-size: 22px; font-weight: 700; margin: 0; }
    .es-tabs { display: flex; gap: 4px; margin-bottom: 20px; border-bottom: 1px solid rgba(128,128,128,.25); flex-wrap: wrap; }
    .es-tabs a { padding: 8px 14px; font-size: 14px; text-decoration: none; color: inherit; opacity: .7; border-bottom: 2px solid transparent; margin-bottom: -1px; }
    .es-tabs a:hover { opacity: 1; }
    .es-tabs a.active { opacity: 1; font-weight: 600; border-bottom-color: currentColor; }
    .es-card { background: var(--color-card, #fff); border: 1px solid rgba(128,128,128,.2); border-radius: 8px; padding: 20px; margin-bottom: 20px; }
    .dark .es-card { background: rgba(255,255,255,.03); }
    .es-card h2 { font-size: 15px; font-weight: 600; margin: 0 0 12px; }
    .es-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 12px; margin-bottom: 20px; }
    .es-stat { border: 1px solid rgba(128,128,128,.2); border-radius: 8px; padding: 14px 16px; }
    .es-stat .es-num { font-size: 24px; font-weight: 700; }
    .es-stat .es-label { font-size: 12px; opacity: .65; text-transform: uppercase; letter-spacing: .04em; }
    .es-table { width: 100%; border-collapse: collapse; font-size: 14px; }
    .es-table th { text-align: left; font-size: 12px; text-transform: uppercase; letter-spacing: .04em; opacity: .6; padding: 8px 10px; border-bottom: 1px solid rgba(128,128,128,.25); }
    .es-table td { padding: 10px; border-bottom: 1px solid rgba(128,128,128,.12); }
    .es-table tr:last-child td { border-bottom: 0; }
    .es-badge { display: inline-block; font-size: 12px; font-weight: 600; border-radius: 99px; padding: 2px 10px; background: rgba(128,128,128,.15); }
    .es-badge.subscribed, .es-badge.sent { background: #d1fae5; color: #065f46; }
    .es-badge.pending, .es-badge.paused, .es-badge.draft { background: #fef3c7; color: #92400e; }
    .es-badge.sending { background: #dbeafe; color: #1e40af; }
    .es-badge.unsubscribed, .es-badge.cancelled { background: rgba(128,128,128,.15); }
    .es-badge.bounced, .es-badge.complained, .es-badge.failed { background: #fee2e2; color: #991b1b; }
    .es-btn { display: inline-block; font-size: 13px; font-weight: 600; padding: 7px 14px; border-radius: 6px; border: 1px solid rgba(128,128,128,.35); background: transparent; color: inherit; cursor: pointer; text-decoration: none; }
    .es-btn:hover { border-color: rgba(128,128,128,.6); }
    .es-btn.primary { background: #18181b; border-color: #18181b; color: #fff; }
    .es-btn.danger { color: #b91c1c; border-color: rgba(185,28,28,.4); }
    .es-form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 14px; }
    .es-field { margin-bottom: 14px; }
    .es-field > span { display: block; font-size: 13px; font-weight: 600; margin-bottom: 4px; }
    .es-field small { display: block; font-size: 12px; opacity: .6; margin-top: 3px; }
    .es-input, .es-select { width: 100%; box-sizing: border-box; font-size: 14px; padding: 8px 10px; border: 1px solid rgba(128,128,128,.35); border-radius: 6px; background: transparent; color: inherit; }
    .es-checks label { display: flex; gap: 8px; align-items: center; font-size: 14px; padding: 4px 0; }
    .es-flash { border-radius: 6px; padding: 10px 14px; font-size: 14px; margin-bottom: 16px; }
    .es-flash.success { background: #ecfdf5; color: #065f46; }
    .es-flash.error { background: #fef2f2; color: #991b1b; }
    .es-actions { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
    .es-progress { height: 8px; border-radius: 99px; background: rgba(128,128,128,.15); overflow: hidden; }
    .es-progress > div { height: 100%; background: #10b981; }
    .es-muted { opacity: .6; font-size: 13px; }
    .es-filters { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 14px; }
    .es-pagination { display: flex; gap: 6px; margin-top: 14px; font-size: 14px; }
    .es-pagination a, .es-pagination span { padding: 4px 10px; border-radius: 6px; border: 1px solid rgba(128,128,128,.25); text-decoration: none; color: inherit; }
</style>

<div class="es-wrap">
    <div class="es-header">
        <h1>{{ $title }}</h1>
        <div>{{ $headerActions ?? '' }}</div>
    </div>

    <div class="es-tabs">
        @php
            $tabs = [
                ['route' => 'email-subscribe.index', 'pattern' => 'statamic.cp.email-subscribe.index', 'label' => __('Dashboard')],
                ['route' => 'email-subscribe.sendouts.index', 'pattern' => 'statamic.cp.email-subscribe.sendouts.*', 'label' => __('Sendouts')],
                ['route' => 'email-subscribe.subscribers.index', 'pattern' => 'statamic.cp.email-subscribe.subscribers.*', 'label' => __('Subscribers')],
                ['route' => 'email-subscribe.lists.index', 'pattern' => 'statamic.cp.email-subscribe.lists.*', 'label' => __('Lists')],
            ];
        @endphp
        @foreach ($tabs as $tab)
            <a href="{{ cp_route($tab['route']) }}" @class(['active' => request()->routeIs($tab['pattern'])])>
                {{ $tab['label'] }}
            </a>
        @endforeach
    </div>

    @if (session('success'))
        <div class="es-flash success">{{ session('success') }}</div>
    @endif

    @if ($errors->any())
        <div class="es-flash error">
            @foreach ($errors->all() as $error)
                {{ $error }}<br>
            @endforeach
        </div>
    @endif
</div>
