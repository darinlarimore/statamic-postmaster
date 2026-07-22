@extends('email-subscribe::web.layout')

@section('title', __('Email preferences'))

@section('content')
    <h1>{{ __('Email preferences') }}</h1>
    <p>{{ $subscriber->email }}</p>

    @if (session('email_subscribe.success'))
        <div class="success">{{ session('email_subscribe.success') }}</div>
    @endif

    <form method="POST" action="{{ $actionUrl }}">
        @csrf

        <div class="field">
            <span>{{ __('First name') }}</span>
            <input type="text" name="first_name" value="{{ old('first_name', $subscriber->first_name) }}">
        </div>

        <div class="field">
            <span>{{ __('Last name') }}</span>
            <input type="text" name="last_name" value="{{ old('last_name', $subscriber->last_name) }}">
        </div>

        <div style="margin: 20px 0;">
            @foreach ($lists as $list)
                <label>
                    <input type="checkbox" name="lists[]" value="{{ $list->id }}" @checked(in_array($list->id, $activeListIds))>
                    {{ $list->title }}
                </label>
            @endforeach
        </div>

        <button type="submit" class="button">{{ __('Save preferences') }}</button>
    </form>
@endsection
