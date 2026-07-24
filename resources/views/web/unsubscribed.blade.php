@extends('postmaster::web.layout')

@section('title', __('Unsubscribed'))

@section('content')
    <h1>{{ __('You\'ve been unsubscribed') }}</h1>
    <p>{{ __(':email will no longer receive these emails.', ['email' => $subscriber->email]) }}</p>
    <p>
        {{ __('Unsubscribed by mistake, or want to choose which emails you get?') }}
        <a href="{{ $preferencesUrl }}">{{ __('Manage your preferences') }}</a>
    </p>
@endsection
