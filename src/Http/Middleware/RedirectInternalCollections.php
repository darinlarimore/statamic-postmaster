<?php

namespace Darinlarimore\Postmaster\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * One guarded screen: creating a subscriber as a raw CP entry would bypass
 * the sha1(email) keying the addon relies on, so entry-create redirects to
 * the addon's Add form. Everything else about the storage collections -
 * their native entry tables, entry editing - is left alone.
 */
class RedirectInternalCollections
{
    public function handle(Request $request, Closure $next)
    {
        if (! $request->isMethod('GET') || config('postmaster.storage.driver', 'content') !== 'content') {
            return $next($request);
        }

        $routeName = $request->route()?->getName();
        $collection = $request->route('collection');
        $handle = is_object($collection) ? $collection->handle() : (string) $collection;

        $subscribers = config('postmaster.storage.collections.subscribers', 'postmaster_subscribers');

        if ($handle === $subscribers && $routeName === 'statamic.cp.collections.entries.create') {
            return redirect(cp_route('postmaster.subscribers.index'));
        }

        return $next($request);
    }
}
