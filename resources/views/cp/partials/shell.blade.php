{{-- Shared chrome for Postmaster CP pages. Styles ship as a compiled,
     prefixed Tailwind stylesheet (resources/css/cp.css) loaded via the
     addon's Vite entry, so they apply outside the CP's Vue-mounted body. --}}

<div class="es-wrap">
    <div class="es-header">
        <h1>{{ $title }}</h1>
        <div>{{ $headerActions ?? '' }}</div>
    </div>

    <div class="es-tabs">
        @php
            $tabs = [
                ['route' => 'postmaster.index', 'pattern' => 'statamic.cp.postmaster.index', 'label' => __('Dashboard')],
                ['route' => 'postmaster.sendouts.index', 'pattern' => 'statamic.cp.postmaster.sendouts.*', 'label' => __('Sendouts')],
                ['route' => 'postmaster.subscribers.index', 'pattern' => 'statamic.cp.postmaster.subscribers.*', 'label' => __('Subscribers')],
                ['route' => 'postmaster.lists.index', 'pattern' => 'statamic.cp.postmaster.lists.*', 'label' => __('Lists')],
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
