<?php

namespace Darinlarimore\Postmaster\Support;

use Illuminate\Support\Facades\Http;
use Spatie\Mjml\Mjml as SpatieMjml;

class Mjml
{
    /**
     * Compile MJML markup to email-safe HTML. Content that doesn't look
     * like MJML (no <mjml> root) passes through untouched so plain HTML
     * templates keep working.
     */
    public static function compile(string $content): string
    {
        if (! str_contains($content, '<mjml')) {
            return $content;
        }

        return match (config('postmaster.mjml.strategy', 'binary')) {
            'api' => static::compileViaApi($content),
            'none' => $content,
            default => static::compileViaBinary($content),
        };
    }

    protected static function compileViaBinary(string $content): string
    {
        return SpatieMjml::new()->minify()->toHtml($content);
    }

    protected static function compileViaApi(string $content): string
    {
        $response = Http::withBasicAuth(
            config('postmaster.mjml.api_id'),
            config('postmaster.mjml.api_secret'),
        )->post('https://api.mjml.io/v1/render', ['mjml' => $content]);

        $response->throw();

        return $response->json('html');
    }
}
