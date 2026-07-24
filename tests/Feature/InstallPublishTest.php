<?php

use Illuminate\Support\Facades\File;
use Statamic\Statamic;

afterEach(function () {
    File::deleteDirectory(resource_path('views/vendor/postmaster'));
    File::deleteDirectory(base_path('.claude/skills/postmaster'));
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

    expect($spy->calls)->toContain(['vendor:publish', ['--tag' => 'postmaster-views']]);

    foreach ($spy->calls as [$command, $parameters]) {
        if ($command === 'vendor:publish' && ($parameters['--tag'] ?? null) === 'postmaster-views') {
            expect($parameters)->not->toHaveKey('--force');
        }
    }
});

test('publishing the views copies the bundled templates', function () {
    $this->artisan('vendor:publish', ['--tag' => 'postmaster-views']);

    expect(File::exists(resource_path('views/vendor/postmaster/emails/default.antlers.html')))->toBeTrue();
    expect(File::exists(resource_path('views/vendor/postmaster/emails/blocks/heading.antlers.html')))->toBeTrue();
});

test('publishing the views keeps an existing customized template', function () {
    $target = resource_path('views/vendor/postmaster/emails/default.antlers.html');
    File::ensureDirectoryExists(dirname($target));
    File::put($target, 'customized');

    $this->artisan('vendor:publish', ['--tag' => 'postmaster-views']);

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

    expect($spy->calls)->toContain(['vendor:publish', ['--tag' => 'postmaster-skill']]);

    foreach ($spy->calls as [$command, $parameters]) {
        if ($command === 'vendor:publish' && ($parameters['--tag'] ?? null) === 'postmaster-skill') {
            expect($parameters)->not->toHaveKey('--force');
        }
    }
});

test('publishing the skill copies it into the project .claude/skills directory', function () {
    $this->artisan('vendor:publish', ['--tag' => 'postmaster-skill']);

    expect(File::exists(base_path('.claude/skills/postmaster/SKILL.md')))->toBeTrue();
});
