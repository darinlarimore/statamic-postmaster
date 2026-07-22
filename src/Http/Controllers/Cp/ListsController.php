<?php

namespace Darinlarimore\StatamicEmailSubscribe\Http\Controllers\Cp;

use Darinlarimore\StatamicEmailSubscribe\Contracts\ListContract;
use Darinlarimore\StatamicEmailSubscribe\Contracts\ListRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Statamic\Http\Controllers\CP\CpController;

class ListsController extends CpController
{
    public function index()
    {
        $repository = app(ListRepository::class);

        $lists = $repository->all()
            ->sortBy(fn (ListContract $list) => $list->title)
            ->values()
            ->map(function (ListContract $list) use ($repository): ListContract {
                $list->active_subscribers_count = $repository->countActiveSubscribers($list->getKey());

                return $list;
            });

        return view('email-subscribe::cp.lists', [
            'title' => 'Mailing Lists',
            'lists' => $lists,
        ]);
    }

    public function store(Request $request)
    {
        $repository = app(ListRepository::class);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'handle' => ['nullable', 'string', 'max:255', $this->uniqueHandleRule($repository)],
            'double_opt_in' => ['nullable', 'boolean'],
        ]);

        $repository->create([
            'title' => $validated['title'],
            'handle' => $validated['handle'] ?: Str::slug($validated['title'], '_'),
            'double_opt_in' => isset($validated['double_opt_in']) ? (bool) $validated['double_opt_in'] : null,
        ]);

        return back()->with('success', __('List created'));
    }

    public function update(Request $request, ListContract $list)
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'double_opt_in' => ['nullable', 'boolean'],
        ]);

        app(ListRepository::class)->update($list->getKey(), [
            'title' => $validated['title'],
            'double_opt_in' => isset($validated['double_opt_in']) ? (bool) $validated['double_opt_in'] : null,
        ]);

        return back()->with('success', __('List updated'));
    }

    public function destroy(ListContract $list)
    {
        app(ListRepository::class)->delete($list->getKey());

        return back()->with('success', __('List deleted'));
    }

    protected function uniqueHandleRule(ListRepository $repository, ?string $exceptKey = null): callable
    {
        return function (string $attribute, mixed $value, callable $fail) use ($repository, $exceptKey): void {
            if ($value && $repository->handleExists($value, $exceptKey)) {
                $fail(__('That handle is already in use.'));
            }
        };
    }
}
