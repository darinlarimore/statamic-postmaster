<?php

use Darinlarimore\StatamicEmailSubscribe\Contracts\ListContract;
use Darinlarimore\StatamicEmailSubscribe\Contracts\SubscriberContract;
use Darinlarimore\StatamicEmailSubscribe\Tests\Support\Factories;
use Darinlarimore\StatamicEmailSubscribe\Tests\TestCase;

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
