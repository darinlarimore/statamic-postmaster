@extends('statamic::layout')
@section('title', $title)

@section('content')
    @include('email-subscribe::cp.partials.shell')

    <div class="es-wrap">
        <div class="es-card">
            <h2>{{ $subscriber->email }}</h2>
            <table class="es-table">
                <tbody>
                    <tr><td class="es-muted">{{ __('Name') }}</td><td>{{ $subscriber->fullName() ?? '—' }}</td></tr>
                    <tr><td class="es-muted">{{ __('Status') }}</td><td><span class="es-badge {{ $subscriber->status }}">{{ $subscriber->status }}</span></td></tr>
                    <tr>
                        <td class="es-muted">{{ __('Lists') }}</td>
                        <td>
                            @forelse ($subscriber->lists as $list)
                                {{ $list->title }}@if($list->pivot->unsubscribed_at) <span class="es-muted">({{ __('unsubscribed') }})</span>@endif{{ !$loop->last ? ',' : '' }}
                            @empty
                                —
                            @endforelse
                        </td>
                    </tr>
                    <tr><td class="es-muted">{{ __('Source') }}</td><td>{{ $subscriber->source ?? '—' }}</td></tr>
                    <tr><td class="es-muted">{{ __('Verified') }}</td><td>{{ $subscriber->verified_at?->format('M j, Y g:ia') ?? '—' }}</td></tr>
                    <tr><td class="es-muted">{{ __('Joined') }}</td><td>{{ $subscriber->created_at->format('M j, Y g:ia') }}</td></tr>
                </tbody>
            </table>

            <div style="margin-top: 16px;">
                <form method="POST" action="{{ cp_route('email-subscribe.subscribers.destroy', $subscriber->id) }}" onsubmit="return confirm('{{ __('Permanently delete this subscriber and their activity?') }}')">
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
