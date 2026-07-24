@extends('statamic::layout')
@section('title', $title)

@section('content')
    @include('email-subscribe::cp.partials.shell')

    <div class="es-wrap">
        <div class="es-card">
            <div class="es-header">
                <h2>{{ __('Mailing lists') }}</h2>
                @if ($createEntryUrl)
                    <a class="es-btn primary" href="{{ $createEntryUrl }}">{{ __('New list') }}</a>
                @endif
            </div>
            @if ($lists->isEmpty())
                <p class="es-muted">{{ __('No lists yet — create one below.') }}</p>
            @else
                <table class="es-table">
                    <thead>
                        <tr>
                            <th>{{ __('Title') }}</th>
                            <th>{{ __('Handle') }}</th>
                            <th>{{ __('Active subscribers') }}</th>
                            <th>{{ __('Double opt-in') }}</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($lists as $list)
                            <tr>
                                <td>
                                    @if ($entryUrl = $entryEditUrls[$list->id] ?? null)
                                        <a href="{{ $entryUrl }}">{{ $list->title }}</a>
                                    @else
                                        {{ $list->title }}
                                    @endif
                                </td>
                                <td><code>{{ $list->handle }}</code></td>
                                <td><a href="{{ cp_route('email-subscribe.subscribers.index', ['list' => $list->id]) }}">{{ number_format($list->active_subscribers_count) }}</a></td>
                                <td class="es-muted">{{ $list->double_opt_in === null ? __('Default') : ($list->double_opt_in ? __('Yes') : __('No')) }}</td>
                                <td style="text-align: right;">
                                    <form method="POST" action="{{ cp_route('email-subscribe.lists.destroy', $list->id) }}" onsubmit="return confirm('{{ __('Delete this list? Subscribers themselves are kept.') }}')">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="es-btn danger">{{ __('Delete') }}</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>

        @unless ($createEntryUrl)
        <div class="es-card">
            <h2>{{ __('Create list') }}</h2>
            <form method="POST" action="{{ cp_route('email-subscribe.lists.store') }}">
                @csrf
                <div class="es-form-grid">
                    <label class="es-field">
                        <span>{{ __('Title') }}</span>
                        <input class="es-input" type="text" name="title" required>
                    </label>
                    <label class="es-field">
                        <span>{{ __('Handle') }}</span>
                        <input class="es-input" type="text" name="handle" placeholder="{{ __('auto from title') }}">
                        <small>{{ __('Used in the form tag: list="handle"') }}</small>
                    </label>
                    <label class="es-field">
                        <span>{{ __('Double opt-in') }}</span>
                        <select class="es-select" name="double_opt_in">
                            <option value="">{{ __('Use default') }}</option>
                            <option value="1">{{ __('Yes') }}</option>
                            <option value="0">{{ __('No') }}</option>
                        </select>
                    </label>
                </div>
                <button type="submit" class="es-btn primary">{{ __('Create list') }}</button>
            </form>
        </div>
        @endunless
    </div>
@endsection
