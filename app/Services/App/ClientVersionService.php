<?php

declare(strict_types=1);

namespace App\Services\App;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class ClientVersionService
{
    /**
     * @var array<int, string>
     */
    private const PLATFORMS = ['windows', 'macos', 'android', 'ios', 'linux'];

    /**
     * @return array<string, mixed>
     */
    public function catalog(?string $platform = null, ?string $userAgent = null): array
    {
        $platforms = [];
        foreach (self::PLATFORMS as $item) {
            $platforms[$item] = $this->versionForPlatform($item);
        }

        $selectedPlatform = $this->normalizePlatform($platform) ?? $this->detectPlatform($userAgent) ?? 'android';

        return [
            'selected_platform' => $selectedPlatform,
            'latest' => $platforms[$selectedPlatform] ?? $this->emptyVersion($selectedPlatform),
            'platforms' => $platforms,
            'generated_at' => time(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function catalogForRequest(Request $request): array
    {
        return $this->catalog(
            $request->query('platform') ? (string) $request->query('platform') : null,
            $request->headers->get('user-agent')
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function githubCompatibleReleasesForRequest(Request $request): array
    {
        $catalog = $this->catalogForRequest($request);
        $latest = is_array($catalog['latest'] ?? null) ? $catalog['latest'] : $this->emptyVersion('android');

        return [$this->githubCompatibleRelease($latest)];
    }

    /**
     * @return array<string, mixed>
     */
    public function githubCompatibleLatestForRequest(Request $request): array
    {
        return $this->githubCompatibleReleasesForRequest($request)[0];
    }

    public function appcastXml(): string
    {
        $items = [];
        foreach (self::PLATFORMS as $platform) {
            $version = $this->versionForPlatform($platform);
            $versionNumber = (string) ($version['version'] ?? '');
            $downloadUrl = (string) ($version['download_url'] ?? '');

            if ($versionNumber === '' || $downloadUrl === '') {
                continue;
            }

            $title = $this->xml('Version ' . $versionNumber . ' for ' . $platform);
            $pubDate = gmdate(DATE_RSS, (int) ($version['published_at'] ?? time()));
            $url = $this->xml($downloadUrl);
            $sparkleVersion = $this->xml($versionNumber);
            $sparkleOs = $this->xml($platform);

            $items[] = <<<XML
        <item>
            <title>{$title}</title>
            <pubDate>{$pubDate}</pubDate>
            <enclosure url="{$url}" sparkle:version="{$sparkleVersion}" sparkle:os="{$sparkleOs}" />
        </item>
XML;
        }

        $itemsXml = implode("\n", $items);

        return <<<XML
<?xml version="1.0" encoding="utf-8"?>
<rss version="2.0" xmlns:sparkle="http://www.andymatuschak.org/xml-namespaces/sparkle">
    <channel>
        <title>蝴蝶加速客户端更新</title>
{$itemsXml}
    </channel>
</rss>
XML;
    }

    /**
     * @return array<string, mixed>
     */
    private function versionForPlatform(string $platform): array
    {
        $rawVersion = trim((string) admin_setting($platform . '_version', ''));
        $downloadUrl = trim((string) admin_setting($platform . '_download_url', ''));
        [$version, $buildNumber] = $this->splitVersion($rawVersion);

        return [
            'platform' => $platform,
            'version' => $version,
            'raw_version' => $rawVersion,
            'build_number' => $buildNumber,
            'release_tag' => $version !== '' ? 'v' . $rawVersion : '',
            'download_url' => $downloadUrl,
            'force_update' => false,
            'changelog' => '',
            'published_at' => time(),
        ];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function splitVersion(string $rawVersion): array
    {
        if ($rawVersion === '') {
            return ['0.0.0', ''];
        }

        $parts = explode('+', $rawVersion, 2);

        return [trim($parts[0]) !== '' ? trim($parts[0]) : '0.0.0', trim($parts[1] ?? '')];
    }

    private function normalizePlatform(?string $platform): ?string
    {
        if ($platform === null || trim($platform) === '') {
            return null;
        }

        $normalized = Str::lower(trim($platform));
        $aliases = [
            'win' => 'windows',
            'win32' => 'windows',
            'win64' => 'windows',
            'windows' => 'windows',
            'mac' => 'macos',
            'osx' => 'macos',
            'darwin' => 'macos',
            'macos' => 'macos',
            'android' => 'android',
            'ios' => 'ios',
            'iphone' => 'ios',
            'ipad' => 'ios',
            'linux' => 'linux',
        ];

        return $aliases[$normalized] ?? null;
    }

    private function detectPlatform(?string $userAgent): ?string
    {
        $normalized = Str::lower((string) $userAgent);
        if ($normalized === '') {
            return null;
        }

        if (str_contains($normalized, 'windows') || str_contains($normalized, 'win64') || str_contains($normalized, 'win32')) {
            return 'windows';
        }

        if (str_contains($normalized, 'macos') || str_contains($normalized, 'mac os') || str_contains($normalized, 'darwin')) {
            return 'macos';
        }

        if (str_contains($normalized, 'android')) {
            return 'android';
        }

        if (str_contains($normalized, 'ios') || str_contains($normalized, 'iphone') || str_contains($normalized, 'ipad')) {
            return 'ios';
        }

        if (str_contains($normalized, 'linux')) {
            return 'linux';
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function githubCompatibleRelease(array $version): array
    {
        $versionNumber = (string) ($version['version'] ?? '0.0.0');
        $rawVersion = (string) ($version['raw_version'] ?? $versionNumber);
        $tag = (string) ($version['release_tag'] ?? '');
        $downloadUrl = (string) ($version['download_url'] ?? '');

        return [
            'tag_name' => $tag !== '' ? $tag : 'v' . $versionNumber,
            'name' => '蝴蝶加速 ' . $versionNumber,
            'prerelease' => false,
            'published_at' => gmdate(DATE_ATOM, (int) ($version['published_at'] ?? time())),
            'html_url' => $downloadUrl !== '' ? $downloadUrl : (string) admin_setting('app_url', ''),
            'body' => (string) ($version['changelog'] ?? ''),
            'assets' => $downloadUrl !== '' ? [[
                'name' => '蝴蝶加速-' . ((string) ($version['platform'] ?? 'client')) . '-' . $rawVersion,
                'browser_download_url' => $downloadUrl,
            ]] : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyVersion(string $platform): array
    {
        return [
            'platform' => $platform,
            'version' => '0.0.0',
            'raw_version' => '',
            'build_number' => '',
            'release_tag' => 'v0.0.0',
            'download_url' => '',
            'force_update' => false,
            'changelog' => '',
            'published_at' => time(),
        ];
    }

    private function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
