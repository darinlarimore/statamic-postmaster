<?php

use Darinlarimore\Postmaster\Contracts\ListContract;
use Darinlarimore\Postmaster\Contracts\SubscriberContract;
use Darinlarimore\Postmaster\Tests\Support\Factories;
use Darinlarimore\Postmaster\Tests\TestCase;

uses(TestCase::class)->in(__DIR__);

/**
 * @param  array<string, mixed>  $attributes
 */
function makeList(array $attributes = []): ListContract
{
    return Factories::makeList($attributes);
}

/**
 * @param  array<string, mixed>  $attributes
 * @param  array<int, ListContract|string>  $lists
 */
function makeSubscriber(array $attributes = [], array $lists = []): SubscriberContract
{
    return Factories::makeSubscriber($attributes, $lists);
}

function attachToList(SubscriberContract $subscriber, ListContract|string $list): void
{
    Factories::attachToList($subscriber, $list);
}
