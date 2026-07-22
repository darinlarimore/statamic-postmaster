@extends('statamic::layout')
@section('title', $title)

@section('content')
    @include('email-subscribe::cp.partials.shell')

    <div class="es-wrap">
        <div class="es-card">
            <form method="GET" class="es-filters">
                <input class="es-input" style="max-width: 240px;" type="search" name="search" value="{{ request('search') }}" placeholder="{{ __('Search email or name…') }}">
                <select class="es-select" style="max-width: 170px;" name="status">
                    <option value="">{{ __('All statuses') }}</option>
                    @foreach ($statuses as $status)
                        <option value="{{ $status }}" @selected(request('status') === $status)>{{ ucfirst($status) }}</option>
                    @endforeach
                </select>
                <select class="es-select" style="max-width: 200px;" name="list">
                    <option value="">{{ __('All lists') }}</option>
                    @foreach ($lists as $list)
                        <option value="{{ $list->id }}" @selected(request('list') == $list->id)>{{ $list->title }}</option>
                    @endforeach
                </select>
                <button type="submit" class="es-btn">{{ __('Filter') }}</button>
                <a class="es-btn" href="{{ cp_route('email-subscribe.subscribers.export', request()->only('status')) }}">{{ __('Export CSV') }}</a>
            </form>

            <table class="es-table">
                <thead>
                    <tr>
                        <th>{{ __('Email') }}</th>
                        <th>{{ __('Name') }}</th>
                        <th>{{ __('Status') }}</th>
                        <th>{{ __('Lists') }}</th>
                        <th>{{ __('Joined') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($subscribers as $subscriber)
                        <tr>
                            <td><a href="{{ cp_route('email-subscribe.subscribers.show', $subscriber->id) }}">{{ $subscriber->email }}</a></td>
                            <td>{{ $subscriber->fullName() }}</td>
                            <td><span class="es-badge {{ $subscriber->status }}">{{ $subscriber->status }}</span></td>
                            <td class="es-muted">{{ $subscriber->lists->pluck('title')->implode(', ') }}</td>
                            <td class="es-muted">{{ $subscriber->created_at->format('M j, Y') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="es-muted">{{ __('No subscribers found.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>

            <div class="es-pagination">
                {{ $subscribers->links('email-subscribe::cp.partials.pagination') }}
            </div>
        </div>
    </div>
@endsection
