<?php

namespace Darinlarimore\StatamicEmailSubscribe\Tags;

use Statamic\Tags\Tags;

class EmailSubscribeTag extends Tags
{
    protected static $handle = 'email_subscribe';

    /**
     * {{ email_subscribe:form list="newsletter" }} ... {{ /email_subscribe:form }}
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

        $html = '<form method="POST" action="'.route('email-subscribe.subscribe').'" '.$attributes.'>';
        $html .= csrf_field();
        $html .= '<input type="hidden" name="lists" value="'.e($lists).'">';
        $html .= '<input type="text" name="es_website" value="" tabindex="-1" autocomplete="off" style="position:absolute;left:-9999px;" aria-hidden="true">';

        if ($redirect = $this->params->get('redirect')) {
            $html .= '<input type="hidden" name="_redirect" value="'.e($redirect).'">';
        }

        $html .= $this->parse([
            'success' => session('email_subscribe.success'),
            'errors' => session()->get('errors')?->getBag('default')->all() ?? [],
        ]);

        $html .= '</form>';

        return $html;
    }

    /**
     * {{ email_subscribe:success }} - flash message after subscribing.
     */
    public function success()
    {
        return session('email_subscribe.success');
    }

    /**
     * {{ email_subscribe:errors }} ... {{ /email_subscribe:errors }}
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
