@extends('statamic::layout')
@section('title', $title)

@section('content')
    @include('postmaster::cp.partials.shell')

    <div class="es-wrap">
        <div class="es-stats">
            <div class="es-stat">
                <div class="es-num">{{ number_format($subscriberCount) }}</div>
                <div class="es-label">{{ __('Subscribed') }}</div>
            </div>
            <div class="es-stat">
                <div class="es-num">{{ number_format($pendingCount) }}</div>
                <div class="es-label">{{ __('Pending opt-in') }}</div>
            </div>
            <div class="es-stat">
                <div class="es-num">{{ number_format($listCount) }}</div>
                <div class="es-label">{{ __('Lists') }}</div>
            </div>
        </div>

        <div class="es-card">
            <h2>{{ __('Recent sendouts') }}</h2>
            @if ($recentSendouts->isEmpty())
                <p class="es-muted">{{ __('No sendouts yet.') }} {{ __('Sendouts are created from a newsletter entry - open the entry and choose "Create Sendout" from its actions menu.') }}</p>
            @else
                <table class="es-table">
                    <thead>
                        <tr><th>{{ __('Title') }}</th><th>{{ __('Status') }}</th><th>{{ __('Sent') }}</th><th>{{ __('Date') }}</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($recentSendouts as $sendout)
                            <tr>
                                <td><a href="{{ cp_route('postmaster.sendouts.show', $sendout->id) }}">{{ $sendout->title }}</a></td>
                                <td><span class="es-badge {{ $sendout->statusBadgeClass() }}">{{ $sendout->statusLabel() }}</span></td>
                                <td>{{ number_format($sendout->sent_count) }} / {{ number_format($sendout->recipient_count) }}</td>
                                <td class="es-muted">{{ $sendout->created_at->diffForHumans() }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>

        <div class="es-card">
            <h2>{{ __('Newest subscribers') }}</h2>
            @if ($recentSubscribers->isEmpty())
                <p class="es-muted">{{ __('No subscribers yet. Add the subscribe form tag to your site to start collecting.') }}</p>
            @else
                <table class="es-table">
                    <tbody>
                        @foreach ($recentSubscribers as $subscriber)
                            <tr>
                                <td><a href="{{ cp_route('postmaster.subscribers.show', $subscriber->id) }}">{{ $subscriber->email }}</a></td>
                                <td><span class="es-badge {{ $subscriber->status }}">{{ $subscriber->status }}</span></td>
                                <td class="es-muted">{{ $subscriber->created_at->diffForHumans() }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </div>
@endsection
