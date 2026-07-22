<?php

namespace Darinlarimore\StatamicEmailSubscribe\Http\Controllers\Cp;

use Darinlarimore\StatamicEmailSubscribe\Models\MailingList;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Statamic\Http\Controllers\CP\CpController;

class ListsController extends CpController
{
    public function index()
    {
        return view('email-subscribe::cp.lists', [
            'title' => 'Mailing Lists',
            'lists' => MailingList::withCount(['activeSubscribers'])->orderBy('title')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'handle' => ['nullable', 'string', 'max:255', 'unique:email_subscribe_lists,handle'],
            'double_opt_in' => ['nullable', 'boolean'],
        ]);

        MailingList::create([
            'title' => $validated['title'],
            'handle' => $validated['handle'] ?: Str::slug($validated['title'], '_'),
            'double_opt_in' => isset($validated['double_opt_in']) ? (bool) $validated['double_opt_in'] : null,
        ]);

        return back()->with('success', __('List created'));
    }

    public function update(Request $request, MailingList $list)
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'handle' => ['required', 'string', 'max:255', 'unique:email_subscribe_lists,handle,'.$list->id],
            'double_opt_in' => ['nullable', 'boolean'],
        ]);

        $list->update([
            'title' => $validated['title'],
            'handle' => $validated['handle'],
            'double_opt_in' => isset($validated['double_opt_in']) ? (bool) $validated['double_opt_in'] : null,
        ]);

        return back()->with('success', __('List updated'));
    }

    public function destroy(MailingList $list)
    {
        $list->delete();

        return back()->with('success', __('List deleted'));
    }
}
