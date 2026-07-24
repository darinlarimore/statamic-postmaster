@extends('statamic::layout')
@section('title', $title)

@section('content')
    @include('postmaster::cp.partials.shell')

    <div class="es-wrap">
        <div class="es-card">
            <div class="es-header">
                <h2>{{ $subscriber->email }}</h2>
                <span class="es-badge {{ $subscriber->status }}">{{ $subscriber->status }}</span>
            </div>

            <form method="POST" action="{{ cp_route('postmaster.subscribers.update', $subscriber->getKey()) }}">
                @csrf
                <div class="es-form-grid">
                    <label class="es-field">
                        <span>{{ __('Email') }}</span>
                        <input class="es-input" type="email" name="email" value="{{ old('email', $subscriber->email) }}" required>
                    </label>
                    <label class="es-field">
                        <span>{{ __('First name') }}</span>
                        <input class="es-input" type="text" name="first_name" value="{{ old('first_name', $subscriber->first_name) }}">
                    </label>
                    <label class="es-field">
                        <span>{{ __('Last name') }}</span>
                        <input class="es-input" type="text" name="last_name" value="{{ old('last_name', $subscriber->last_name) }}">
                    </label>
                    <label class="es-field">
                        <span>{{ __('Status') }}</span>
                        <select class="es-select" name="sub_status">
                            @foreach ($statuses as $status)
                                <option value="{{ $status }}" @selected(old('sub_status', $subscriber->status) === $status)>{{ ucfirst($status) }}</option>
                            @endforeach
                        </select>
                    </label>
                    @foreach ($customFieldHandles as $fieldHandle)
                        <label class="es-field">
                            <span>{{ \Illuminate\Support\Str::headline($fieldHandle) }}</span>
                            <input class="es-input" type="text" name="{{ $fieldHandle }}" value="{{ old($fieldHandle, ($subscriber->fields ?? [])[$fieldHandle] ?? '') }}">
                        </label>
                    @endforeach
                </div>

                <div class="es-checks" style="margin-top: 8px;">
                    @foreach ($lists as $list)
                        <label>
                            <input type="checkbox" name="list_ids[]" value="{{ $list->getKey() }}" @checked(in_array((string) $list->getKey(), old('list_ids', $activeListKeys)))>
                            {{ $list->title }}
                        </label>
                    @endforeach
                </div>

                <div style="margin-top: 12px;">
                    <button type="submit" class="es-btn primary">{{ __('Save subscriber') }}</button>
                    <span class="es-muted" style="margin-left: 8px;">{{ __('Unchecking a list unsubscribes them from it.') }}</span>
                </div>
            </form>

            <table class="es-table" style="margin-top: 16px;">
                <tbody>
                    <tr><td class="es-muted">{{ __('Source') }}</td><td>{{ $subscriber->source ?? '—' }}</td></tr>
                    <tr><td class="es-muted">{{ __('Verified') }}</td><td>{{ $subscriber->verified_at?->format('M j, Y g:ia') ?? '—' }}</td></tr>
                    <tr><td class="es-muted">{{ __('Joined') }}</td><td>{{ $subscriber->created_at->format('M j, Y g:ia') }}</td></tr>
                </tbody>
            </table>

            <div style="margin-top: 16px;">
                <form method="POST" action="{{ cp_route('postmaster.subscribers.destroy', $subscriber->id) }}" onsubmit="return confirm('{{ __('Permanently delete this subscriber and their activity?') }}')">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="es-btn danger">{{ __('Delete subscriber') }}</button>
                </form>
            </div>
        </div>

        <div class="es-card">
            <h2>{{ __('Activity') }}</h2>
            @if ($events->isEmpty())
                <p class="es-muted">{{ __('No activity yet.') }}</p>
            @else
                <table class="es-table">
                    <tbody>
                        @foreach ($events as $event)
                            <tr>
                                <td><span class="es-badge">{{ $event->type }}</span></td>
                                <td>{{ $event->sendout?->title ?? '—' }}</td>
                                <td class="es-muted" style="max-width: 320px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">{{ $event->url ?? $event->detail }}</td>
                                <td class="es-muted">{{ $event->created_at->format('M j, Y g:ia') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </div>
@endsection
