<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Throwable;

class AppVersion
{
    public const string DEFAULT_VERSION = 'dev';

    private const int GIT_CACHE_TTL_SECONDS = 60;

    private const string RELEASE_TAG_PATTERN = '/^v\d+\.\d+\.\d+$/';

    /** @var array{version: string, release_tag: ?string}|null */
    private ?array $gitMemo = null;

    public function __construct(
        private readonly ?string $repositoryPath = null,
    ) {}

    public function current(): string
    {
        return $this->resolve()['version'];
    }

    public function releaseTag(): ?string
    {
        return $this->resolve()['release_tag'];
    }

    public function flushMemo(): void
    {
        $this->gitMemo = null;
    }

    /**
     * @return array{version: string, release_tag: ?string}
     */
    private function resolve(): array
    {
        $configured = $this->configuredVersion();

        if ($configured !== null) {
            return [
                'version' => $configured,
                'release_tag' => $this->isReleaseTag($configured) ? $configured : null,
            ];
        }

        if ($this->gitMemo !== null) {
            return $this->gitMemo;
        }

        return $this->gitMemo = $this->resolveFromGitCached();
    }

    private function configuredVersion(): ?string
    {
        $configured = config('app.version');

        if (! is_string($configured)) {
            return null;
        }

        $trimmed = trim($configured);

        return $trimmed !== '' ? $trimmed : null;
    }

    /**
     * @return array{version: string, release_tag: ?string}
     */
    private function resolveFromGitCached(): array
    {
        $path = $this->repositoryPath ?? base_path();

        if (! file_exists($path.'/.git')) {
            return [
                'version' => self::DEFAULT_VERSION,
                'release_tag' => null,
            ];
        }

        $cacheKey = 'app_version.git.'.md5($path);

        try {
            /** @var array{version: string, release_tag: ?string} $resolved */
            $resolved = Cache::remember(
                $cacheKey,
                self::GIT_CACHE_TTL_SECONDS,
                fn (): array => $this->resolveFromGit($path),
            );

            return $resolved;
        } catch (Throwable) {
            return $this->resolveFromGit($path);
        }
    }

    /**
     * @return array{version: string, release_tag: ?string}
     */
    private function resolveFromGit(string $path): array
    {
        $version = $this->runGit($path, ['git', 'describe', '--tags', '--always', '--dirty'])
            ?? self::DEFAULT_VERSION;

        if (! $this->isReleaseTag($version)) {
            return [
                'version' => $version,
                'release_tag' => null,
            ];
        }

        $exactTag = $this->runGit($path, ['git', 'describe', '--tags', '--exact-match']);

        return [
            'version' => $version,
            'release_tag' => $exactTag !== null && $exactTag === $version ? $exactTag : null,
        ];
    }

    /**
     * @param  list<string>  $command
     */
    private function runGit(string $path, array $command): ?string
    {
        try {
            $result = Process::path($path)
                ->timeout(2)
                ->run($command);

            if (! $result->successful()) {
                return null;
            }

            $output = trim($result->output());

            return $output !== '' ? $output : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function isReleaseTag(string $value): bool
    {
        return preg_match(self::RELEASE_TAG_PATTERN, $value) === 1;
    }
}
