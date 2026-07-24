<?php

use Illuminate\Support\Facades\File;
use Statamic\Statamic;

afterEach(function () {
    File::deleteDirectory(resource_path('views/vendor/email-subscribe'));
    File::deleteDirectory(base_path('.claude/skills/email-subscribe'));
});

test('the after-install callback publishes the email views without forcing', function () {
    $spy = new class
    {
        public array $calls = [];

        public function call(string $command, array $parameters = []): int
        {
            $this->calls[] = [$command, $parameters];

            return 0;
        }
    };

    Statamic::runAfterInstalledCallbacks($spy);

    expect($spy->calls)->toContain(['vendor:publish', ['--tag' => 'email-subscribe-views']]);

    foreach ($spy->calls as [$command, $parameters]) {
        if ($command === 'vendor:publish' && ($parameters['--tag'] ?? null) === 'email-subscribe-views') {
            expect($parameters)->not->toHaveKey('--force');
        }
    }
});

test('publishing the views copies the bundled templates', function () {
    $this->artisan('vendor:publish', ['--tag' => 'email-subscribe-views']);

    expect(File::exists(resource_path('views/vendor/email-subscribe/emails/default.antlers.html')))->toBeTrue();
    expect(File::exists(resource_path('views/vendor/email-subscribe/emails/blocks/heading.antlers.html')))->toBeTrue();
});

test('publishing the views keeps an existing customized template', function () {
    $target = resource_path('views/vendor/email-subscribe/emails/default.antlers.html');
    File::ensureDirectoryExists(dirname($target));
    File::put($target, 'customized');

    $this->artisan('vendor:publish', ['--tag' => 'email-subscribe-views']);

    expect(File::get($target))->toBe('customized');
});

test('the after-install callback also publishes the skill without forcing', function () {
    $spy = new class
    {
        public array $calls = [];

        public function call(string $command, array $parameters = []): int
        {
            $this->calls[] = [$command, $parameters];

            return 0;
        }
    };

    Statamic::runAfterInstalledCallbacks($spy);

    expect($spy->calls)->toContain(['vendor:publish', ['--tag' => 'email-subscribe-skill']]);

    foreach ($spy->calls as [$command, $parameters]) {
        if ($command === 'vendor:publish' && ($parameters['--tag'] ?? null) === 'email-subscribe-skill') {
            expect($parameters)->not->toHaveKey('--force');
        }
    }
});

test('publishing the skill copies it into the project .claude/skills directory', function () {
    $this->artisan('vendor:publish', ['--tag' => 'email-subscribe-skill']);

    expect(File::exists(base_path('.claude/skills/email-subscribe/SKILL.md')))->toBeTrue();
});
