<?php

use App\Support\AppVersion;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Inertia\Testing\AssertableInertia as Assert;

it('returns the configured release version and release tag without invoking git', function () {
    config(['app.version' => '  v0.2.2  ']);
    Process::fake();

    $version = new AppVersion;

    expect($version->current())->toBe('v0.2.2')
        ->and($version->releaseTag())->toBe('v0.2.2');
    Process::assertNothingRan();
});

it('returns a null release tag when configured app version is not an exact release tag', function (string $configured) {
    config(['app.version' => $configured]);
    Process::fake();

    $version = new AppVersion;

    expect($version->current())->toBe($configured)
        ->and($version->releaseTag())->toBeNull();
    Process::assertNothingRan();
})->with([
    'commits past tag' => ['v0.1.0-21-g2bfdf95'],
    'dirty tag' => ['v0.2.2-dirty'],
    'edge build' => ['edge-2bfdf95'],
    'dev default' => ['dev'],
]);

it('falls back to git describe and returns a null release tag for commits past a tag', function () {
    config(['app.version' => null]);

    Process::fake([
        '*' => Process::result(output: "v0.1.0-21-g2bfdf95\n"),
    ]);

    $version = new AppVersion(base_path());

    expect($version->current())->toBe('v0.1.0-21-g2bfdf95')
        ->and($version->releaseTag())->toBeNull();
    Process::assertRan(fn ($process): bool => $process->command === ['git', 'describe', '--tags', '--always', '--dirty']);
});

it('resolves an exact release tag from git when checked out cleanly on a release tag', function () {
    config(['app.version' => null]);

    Process::fake(function (PendingProcess $process) {
        return match ($process->command) {
            ['git', 'describe', '--tags', '--always', '--dirty'] => Process::result(output: "v0.2.2\n"),
            ['git', 'describe', '--tags', '--exact-match'] => Process::result(output: "v0.2.2\n"),
            default => Process::result(exitCode: 1),
        };
    });

    $version = new AppVersion(base_path());

    expect($version->current())->toBe('v0.2.2')
        ->and($version->releaseTag())->toBe('v0.2.2');
    Process::assertRan(fn (PendingProcess $process): bool => $process->command === ['git', 'describe', '--tags', '--exact-match']);
});

it('caches git version resolution across instances so local dev avoids repeated git calls', function () {
    config(['app.version' => null]);

    Process::fake([
        '*' => Process::result(output: "v0.1.0-21-g2bfdf95-dirty\n"),
    ]);

    $first = new AppVersion(base_path());
    $second = new AppVersion(base_path());

    expect($first->current())->toBe('v0.1.0-21-g2bfdf95-dirty')
        ->and($second->current())->toBe('v0.1.0-21-g2bfdf95-dirty');
    Process::assertRanTimes(fn ($process): bool => $process->command === ['git', 'describe', '--tags', '--always', '--dirty'], 1);
});

it('falls back to dev with no release tag when no git metadata is present', function () {
    config(['app.version' => '']);
    Process::fake();

    $tempDir = sys_get_temp_dir().'/splitline-no-git-'.uniqid('', true);
    mkdir($tempDir, 0755, true);

    try {
        $version = new AppVersion($tempDir);

        expect($version->current())->toBe(AppVersion::DEFAULT_VERSION)
            ->and($version->releaseTag())->toBeNull();
        Process::assertNothingRan();
    } finally {
        rmdir($tempDir);
    }
});

it('falls back to dev with no release tag when git describe fails', function () {
    config(['app.version' => null]);

    Process::fake([
        '*' => Process::result(output: '', errorOutput: 'fatal: not a git repository', exitCode: 128),
    ]);

    $version = new AppVersion(base_path());

    expect($version->current())->toBe(AppVersion::DEFAULT_VERSION)
        ->and($version->releaseTag())->toBeNull();
});

it('shares appVersion and releaseTag with inertia pages', function () {
    config(['app.version' => 'v0.3.0']);

    $this->get(route('settings.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/Index')
            ->where('appVersion', 'v0.3.0')
            ->where('releaseTag', 'v0.3.0'),
        );

    config(['app.version' => 'v0.1.0-21-g2bfdf95']);

    $this->get(route('settings.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/Index')
            ->where('appVersion', 'v0.1.0-21-g2bfdf95')
            ->where('releaseTag', null),
        );
});
