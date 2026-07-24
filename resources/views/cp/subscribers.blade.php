@extends('statamic::layout')
@section('title', $title)

@section('content')
    @include('email-subscribe::cp.partials.shell')

    <div class="es-wrap">
        <div class="es-card">
            <div class="es-header">
                <h2>{{ __('Subscribers') }}</h2>
                <div class="es-actions">
                    <details class="es-menu">
                        <summary aria-label="{{ __('More actions') }}">&#8943;</summary>
                        <div class="es-menu-panel">
                            <button type="button" onclick="this.closest('details').removeAttribute('open'); document.getElementById('es-import-subscribers').showModal()">{{ __('Import CSV') }}</button>
                            <a href="{{ cp_route('email-subscribe.subscribers.import-template') }}">{{ __('Download the CSV template') }}</a>
                        </div>
                    </details>
                    <button type="button" class="es-btn primary" onclick="document.getElementById('es-add-subscriber').showModal()">{{ __('Add subscriber') }}</button>
                </div>
            </div>

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
                            <td><a href="{{ $entryEditUrls[$subscriber->getKey()] ?? cp_route('email-subscribe.subscribers.show', $subscriber->id) }}">{{ $subscriber->email }}</a></td>
                            <td>{{ $subscriber->fullName() }}</td>
                            <td><span class="es-badge {{ $subscriber->status }}">{{ $subscriber->status }}</span></td>
                            <td class="es-muted">{{ $subscriber->listsForView()->pluck('title')->implode(', ') }}</td>
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

        {{-- Server-side `open` re-shows a dialog when its form came back with errors. --}}
        <dialog id="es-add-subscriber" class="es-modal" @if ($errors->hasAny(['email', 'first_name', 'last_name', 'opt_in', 'list_ids'])) open @endif>
            <h2>{{ __('Add a subscriber') }}</h2>
            <form method="POST" action="{{ cp_route('email-subscribe.subscribers.store') }}">
                @csrf
                <div class="es-form-grid">
                    <label class="es-field">
                        <span>{{ __('Email') }}</span>
                        <input class="es-input" type="email" name="email" value="{{ old('email') }}" required>
                    </label>
                    <label class="es-field">
                        <span>{{ __('First name') }}</span>
                        <input class="es-input" type="text" name="first_name" value="{{ old('first_name') }}">
                    </label>
                    <label class="es-field">
                        <span>{{ __('Last name') }}</span>
                        <input class="es-input" type="text" name="last_name" value="{{ old('last_name') }}">
                    </label>
                    @foreach ($customFieldHandles as $fieldHandle)
                        <label class="es-field">
                            <span>{{ \Illuminate\Support\Str::headline($fieldHandle) }}</span>
                            <input class="es-input" type="text" name="{{ $fieldHandle }}" value="{{ old($fieldHandle) }}">
                        </label>
                    @endforeach
                </div>

                @if ($errors->has('email'))
                    <p class="es-flash error">{{ $errors->first('email') }}</p>
                @endif

                <div class="es-checks" style="margin-top: 8px;">
                    @foreach ($lists as $list)
                        <label>
                            <input type="checkbox" name="list_ids[]" value="{{ $list->id }}" @checked(in_array($list->id, old('list_ids', [])))>
                            {{ $list->title }}
                        </label>
                    @endforeach
                </div>

                <div class="es-checks" style="margin-top: 8px;">
                    <label>
                        <input type="radio" name="opt_in" value="subscribed" @checked(old('opt_in', 'subscribed') === 'subscribed')>
                        {{ __('Add as subscribed - they already gave consent, no email is sent') }}
                    </label>
                    <label>
                        <input type="radio" name="opt_in" value="confirm" @checked(old('opt_in') === 'confirm')>
                        {{ __('Send an opt-in confirmation email - pending until they confirm') }}
                    </label>
                </div>

                <div class="es-actions" style="margin-top: 12px;">
                    <button type="submit" class="es-btn primary">{{ __('Add subscriber') }}</button>
                    <button type="button" class="es-btn" onclick="this.closest('dialog').close()">{{ __('Cancel') }}</button>
                </div>
            </form>
        </dialog>

        <dialog id="es-import-subscribers" class="es-modal" @if ($errors->hasAny(['file', 'list', 'subscribed'])) open @endif>
            <h2>{{ __('Import subscribers') }}</h2>
            <form method="POST" action="{{ cp_route('email-subscribe.subscribers.import') }}" enctype="multipart/form-data">
                @csrf
                <label class="es-field">
                    <span>{{ __('CSV file') }}</span>
                    <input class="es-input" type="file" name="file" accept=".csv,text/csv" required>
                    <small>{{ __('Needs an email column; first_name, last_name, and custom field columns are picked up when present.') }}</small>
                </label>

                <label class="es-field">
                    <span>{{ __('Subscribe imports to') }}</span>
                    <select class="es-select" name="list">
                        <option value="">{{ __('No list') }}</option>
                        @foreach ($lists as $list)
                            <option value="{{ $list->id }}">{{ $list->title }}</option>
                        @endforeach
                    </select>
                </label>

                <div class="es-checks">
                    <label>
                        <input type="checkbox" name="subscribed" value="1" checked>
                        {{ __('Already confirmed (skip double opt-in)') }}
                    </label>
                </div>

                @if ($errors->has('file') || $errors->has('list'))
                    <p class="es-flash error">{{ $errors->first('file') ?: $errors->first('list') }}</p>
                @endif

                <div class="es-actions" style="margin-top: 12px;">
                    <button type="submit" class="es-btn primary">{{ __('Import') }}</button>
                    <a class="es-btn" href="{{ cp_route('email-subscribe.subscribers.import-template') }}">{{ __('Download the CSV template') }}</a>
                    <button type="button" class="es-btn" onclick="this.closest('dialog').close()">{{ __('Cancel') }}</button>
                </div>
            </form>
        </dialog>
    </div>
@endsection
