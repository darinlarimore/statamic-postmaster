@extends('statamic::layout')
@section('title', $title)

@section('content')
    @include('email-subscribe::cp.partials.shell')

    <div class="es-wrap">
        <div class="es-card">
            <div class="es-header">
                <h2>{{ __('Sendouts') }}</h2>
                <a class="es-btn primary" href="{{ cp_route('email-subscribe.sendouts.create') }}">{{ __('Create sendout') }}</a>
            </div>

            @if ($sendouts->isEmpty())
                <p class="es-muted">{{ __('No sendouts yet.') }}</p>
            @else
                <table class="es-table">
                    <thead>
                        <tr>
                            <th>{{ __('Title') }}</th>
                            <th>{{ __('Subject') }}</th>
                            <th>{{ __('Status') }}</th>
                            <th>{{ __('Progress') }}</th>
                            <th>{{ __('Created') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($sendouts as $sendout)
                            <tr>
                                <td><a href="{{ cp_route('email-subscribe.sendouts.show', $sendout->id) }}">{{ $sendout->title }}</a></td>
                                <td class="es-muted">{{ $sendout->subject }}</td>
                                <td>
                                    <span class="es-badge {{ $sendout->status }}">{{ $sendout->status }}</span>
                                    @if ($sendout->send_at && $sendout->status === 'pending')
                                        <span class="es-muted">{{ $sendout->send_at->format('M j g:ia') }}</span>
                                    @endif
                                </td>
                                <td style="min-width: 140px;">
                                    @if ($sendout->recipient_count > 0)
                                        <div class="es-progress"><div style="width: {{ min(100, round($sendout->sent_count / $sendout->recipient_count * 100)) }}%;"></div></div>
                                        <span class="es-muted">{{ number_format($sendout->sent_count) }} / {{ number_format($sendout->recipient_count) }}</span>
                                    @else
                                        <span class="es-muted">—</span>
                                    @endif
                                </td>
                                <td class="es-muted">{{ $sendout->created_at->format('M j, Y') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>

                <div class="es-pagination">
                    {{ $sendouts->links('email-subscribe::cp.partials.pagination') }}
                </div>
            @endif
        </div>
    </div>
@endsection
