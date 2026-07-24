<?php

namespace Darinlarimore\Postmaster\Tags;

use Statamic\Tags\Tags;

class PostmasterTag extends Tags
{
    protected static $handle = 'postmaster';

    /**
     * {{ postmaster:form list="newsletter" }} ... {{ /postmaster:form }}
     *
     * Renders the wrapping <form> with CSRF, honeypot, and list fields.
     * Inside the pair, add your own email/first_name/last_name inputs.
     * Accepts list="handle" or lists="handle|other" and an optional
     * redirect="/thanks".
     */
    public function form()
    {
        $lists = $this->params->get('lists') ?? $this->params->get('list') ?? 'newsletter';

        $attributes = collect([
            'class' => $this->params->get('class'),
            'id' => $this->params->get('id'),
        ])
            ->filter()
            ->map(fn ($value, $attribute) => $attribute.'="'.e($value).'"')
            ->implode(' ');

        $html = '<form method="POST" action="'.route('postmaster.subscribe').'" '.$attributes.'>';
        $html .= csrf_field();
        $html .= '<input type="hidden" name="lists" value="'.e($lists).'">';
        $html .= '<input type="text" name="es_website" value="" tabindex="-1" autocomplete="off" style="position:absolute;left:-9999px;" aria-hidden="true">';

        if ($redirect = $this->params->get('redirect')) {
            $html .= '<input type="hidden" name="_redirect" value="'.e($redirect).'">';
        }

        $html .= $this->parse([
            'success' => session('postmaster.success'),
            'errors' => session()->get('errors')?->getBag('default')->all() ?? [],
        ]);

        $html .= '</form>';

        return $html;
    }

    /**
     * {{ postmaster:success }} - flash message after subscribing.
     */
    public function success()
    {
        return session('postmaster.success');
    }

    /**
     * {{ postmaster:errors }} ... {{ /postmaster:errors }}
     */
    public function errors()
    {
        $errors = session()->get('errors')?->getBag('default')->all() ?? [];

        if (! $errors) {
            return '';
        }

        return $this->parseLoop(collect($errors)->map(fn ($error) => ['error' => $error])->all());
    }
}
