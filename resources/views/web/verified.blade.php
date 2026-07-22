@extends('email-subscribe::web.layout')

@section('title', __('Subscription confirmed'))

@section('content')
    <h1>{{ __('Subscription confirmed') }}</h1>
    <p>{{ __('Thanks! :email is now subscribed.', ['email' => $subscriber->email]) }}</p>
@endsection
