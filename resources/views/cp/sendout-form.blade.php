@extends('statamic::layout')
@section('title', $title)

@section('content')
    @include('email-subscribe::cp.partials.shell')

    <div class="es-wrap">
        <form method="POST" action="{{ $action }}">
            @csrf

            <div class="es-card">
                <h2>{{ __('Content') }}</h2>
                <div class="es-form-grid">
                    <label class="es-field">
                        <span>{{ __('Title') }}</span>
                        <input class="es-input" type="text" name="title" value="{{ old('title', $sendout->title) }}" required>
                        <small>{{ __('Internal name, not shown to subscribers.') }}</small>
                    </label>
                    <label class="es-field">
                        <span>{{ __('Subject') }}</span>
                        <input class="es-input" type="text" name="subject" value="{{ old('subject', $sendout->subject) }}" required>
                    </label>
                    <label class="es-field">
                        <span>{{ __('Preheader') }}</span>
                        <input class="es-input" type="text" name="preheader" value="{{ old('preheader', $sendout->preheader) }}">
                        <small>{{ __('Preview text shown after the subject in inboxes.') }}</small>
                    </label>
                </div>

                <div class="es-form-grid">
                    <label class="es-field">
                        <span>{{ __('Content entry ID') }}</span>
                        <input class="es-input" type="text" name="entry_id" value="{{ old('entry_id', $sendout->entry_id) }}" placeholder="{{ __('optional') }}">
                        <small>{{ __('A Statamic entry whose content fills the template (available as "entry" in the view).') }}</small>
                    </label>
                    <label class="es-field">
                        <span>{{ __('Template') }}</span>
                        <input class="es-input" type="text" name="template" value="{{ old('template', $sendout->template) }}" placeholder="email-subscribe::emails.default">
                        <small>{{ __('View name of the MJML/HTML template. Blank uses the built-in default.') }}</small>
                    </label>
                </div>
            </div>

            <div class="es-card">
                <h2>{{ __('Recipients') }}</h2>
                <div class="es-checks">
                    @foreach ($lists as $list)
                        <label>
                            <input type="checkbox" name="list_ids[]" value="{{ $list->id }}" @checked(in_array($list->id, old('list_ids', $sendout->list_ids ?? [])))>
                            {{ $list->title }}
                        </label>
                    @endforeach
                </div>
                @if ($lists->isEmpty())
                    <p class="es-muted">{{ __('Create a mailing list first.') }}</p>
                @endif
            </div>

            <div class="es-card">
                <h2>{{ __('Sender') }}</h2>
                <div class="es-form-grid">
                    <label class="es-field">
                        <span>{{ __('From email') }}</span>
                        <input class="es-input" type="email" name="from_email" value="{{ old('from_email', $sendout->from_email) }}" placeholder="{{ config('email_subscribe.from.email') }}">
                    </label>
                    <label class="es-field">
                        <span>{{ __('From name') }}</span>
                        <input class="es-input" type="text" name="from_name" value="{{ old('from_name', $sendout->from_name) }}" placeholder="{{ config('email_subscribe.from.name') }}">
                    </label>
                    <label class="es-field">
                        <span>{{ __('Reply to') }}</span>
                        <input class="es-input" type="email" name="reply_to" value="{{ old('reply_to', $sendout->reply_to) }}">
                    </label>
                </div>
            </div>

            <div class="es-actions">
                <button type="submit" class="es-btn primary">{{ __('Save draft') }}</button>
                <a class="es-btn" href="{{ $sendout->exists ? cp_route('email-subscribe.sendouts.show', $sendout->id) : cp_route('email-subscribe.sendouts.index') }}">{{ __('Cancel') }}</a>
            </div>
        </form>
    </div>
@endsection
