@extends('statamic::layout')
@section('title', $title)

@section('content')
    @include('postmaster::cp.partials.shell')

    <div class="es-wrap">
        <div class="es-card">
            <div class="es-header">
                <div>
                    <h2 style="margin-bottom: 4px;">{{ $sendout->title }}</h2>
                    <div class="es-muted">{{ $sendout->subject }}</div>
                    @php($recipientLists = $sendout->lists())
                    @php($recipientTotal = $sendout->recipient_count > 0 ? $sendout->recipient_count : $sendout->recipientCount())
                    <div class="es-muted" style="margin-top: 2px;">
                        @if ($recipientLists->isNotEmpty())
                            {{ __('To:') }} {{ $recipientLists->pluck('title')->implode(', ') }}
                            &middot; {{ trans_choice(':count recipient|:count recipients', $recipientTotal, ['count' => number_format($recipientTotal)]) }}
                        @else
                            {{ __('No lists selected - pick them in the entry\'s "Send to lists" field.') }}
                        @endif
                    </div>
                </div>
                <span class="es-badge {{ $sendout->statusBadgeClass() }}" style="font-size: 14px;">{{ $sendout->statusLabel() }}</span>
            </div>

            @if ($sendout->recipient_count > 0)
                <div class="es-progress" style="margin-bottom: 8px;">
                    <div style="width: {{ min(100, round($sendout->sent_count / max(1, $sendout->recipient_count) * 100)) }}%;"></div>
                </div>
                <p class="es-muted">
                    {{ number_format($sendout->sent_count) }} / {{ number_format($sendout->recipient_count) }} {{ __('sent') }}
                    @if ($sendout->fail_count) &middot; {{ number_format($sendout->fail_count) }} {{ __('failed') }} @endif
                    @if ($sendout->sent_at) &middot; {{ __('completed') }} {{ $sendout->sent_at->format('M j, Y g:ia') }} @endif
                </p>
            @endif

            @if ($sendout->isScheduled())
                <p class="es-muted">{{ __('Scheduled for :time', ['time' => $sendout->send_at->format('M j, Y g:ia')]) }}</p>
            @endif

            @if ($sendout->error)
                <div class="es-flash error">{{ $sendout->error }}</div>
            @endif

            <div class="es-actions" style="margin-top: 12px;">
                @if (in_array($sendout->status, ['draft', 'cancelled']))
                    <form method="POST" action="{{ cp_route('postmaster.sendouts.start', $sendout->id) }}" onsubmit="return confirm('{{ __('Send to all recipients now?') }}')" class="es-actions">
                        @csrf
                        <button type="submit" class="es-btn primary">{{ __('Send now') }}</button>
                    </form>
                    <form method="POST" action="{{ cp_route('postmaster.sendouts.start', $sendout->id) }}" class="es-actions">
                        @csrf
                        <input class="es-input" style="width: auto;" type="datetime-local" name="send_at" required>
                        <button type="submit" class="es-btn">{{ __('Schedule') }}</button>
                    </form>
                    @if ($entryEditUrl = $sendout->entry()?->editUrl())
                        <a class="es-btn" href="{{ $entryEditUrl }}">{{ __('Edit entry') }}</a>
                    @endif
                @endif

                @if (in_array($sendout->status, ['pending', 'sending']))
                    <form method="POST" action="{{ cp_route('postmaster.sendouts.pause', $sendout->id) }}">
                        @csrf
                        <button type="submit" class="es-btn">{{ __('Pause') }}</button>
                    </form>
                @endif

                @if (in_array($sendout->status, ['paused', 'failed']))
                    <form method="POST" action="{{ cp_route('postmaster.sendouts.resume', $sendout->id) }}">
                        @csrf
                        <button type="submit" class="es-btn primary">{{ __('Resume') }}</button>
                    </form>
                @endif

                @if (! in_array($sendout->status, ['sent', 'draft', 'cancelled']))
                    <form method="POST" action="{{ cp_route('postmaster.sendouts.cancel', $sendout->id) }}" onsubmit="return confirm('{{ __('Cancel this sendout?') }}')">
                        @csrf
                        <button type="submit" class="es-btn danger">{{ __('Cancel sendout') }}</button>
                    </form>
                @endif

                <a class="es-btn" href="{{ cp_route('postmaster.sendouts.preview', $sendout->id) }}" target="_blank">{{ __('Preview') }}</a>

                @if (in_array($sendout->status, ['draft', 'cancelled', 'sent']))
                    <form method="POST" action="{{ cp_route('postmaster.sendouts.destroy', $sendout->id) }}" onsubmit="return confirm('{{ __('Delete this sendout and its stats?') }}')">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="es-btn danger">{{ __('Delete') }}</button>
                    </form>
                @endif
            </div>
        </div>

        <div class="es-card">
            <h2>{{ __('Send a test') }}</h2>
            <form method="POST" action="{{ cp_route('postmaster.sendouts.test', $sendout->id) }}" class="es-actions">
                @csrf
                <input class="es-input" style="max-width: 380px;" type="text" name="emails" placeholder="you@example.com, teammate@example.com" required>
                <button type="submit" class="es-btn">{{ __('Send test email') }}</button>
                <span class="es-muted">{{ __('Comma separated. Sends immediately — no queue, no tracking.') }}</span>
            </form>
        </div>

        <div class="es-card">
            <h2>{{ __('Results') }}</h2>
            <div class="es-stats">
                <div class="es-stat"><div class="es-num">{{ number_format($stats['sent']) }}</div><div class="es-label">{{ __('Sent') }}</div></div>
                <div class="es-stat">
                    <div class="es-num">{{ number_format($stats['opens']) }}</div>
                    <div class="es-label">{{ __('Unique opens') }}
                        @if ($stats['sent']) ({{ round($stats['opens'] / $stats['sent'] * 100) }}%) @endif
                    </div>
                </div>
                <div class="es-stat">
                    <div class="es-num">{{ number_format($stats['clicks']) }}</div>
                    <div class="es-label">{{ __('Unique clicks') }}
                        @if ($stats['sent']) ({{ round($stats['clicks'] / $stats['sent'] * 100) }}%) @endif
                    </div>
                </div>
                <div class="es-stat"><div class="es-num">{{ number_format($stats['unsubscribes']) }}</div><div class="es-label">{{ __('Unsubscribes') }}</div></div>
                <div class="es-stat"><div class="es-num">{{ number_format($stats['bounces']) }}</div><div class="es-label">{{ __('Bounces') }}</div></div>
                <div class="es-stat"><div class="es-num">{{ number_format($stats['complaints']) }}</div><div class="es-label">{{ __('Complaints') }}</div></div>
            </div>

            @if ($topLinks->isNotEmpty())
                <h2>{{ __('Top links') }}</h2>
                <table class="es-table">
                    <tbody>
                        @foreach ($topLinks as $link)
                            <tr>
                                <td style="max-width: 480px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;"><a href="{{ $link->url }}" target="_blank" rel="noopener">{{ $link->url }}</a></td>
                                <td>{{ number_format($link->clicks) }} {{ __('clicks') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </div>
@endsection
