<?php

namespace App\Console\Commands;

use App\Models\SubscribeTemplate;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SyncSingBoxRuleSets extends Command
{
    protected $signature = 'sing-box:sync-rules
        {--min-bytes=1024 : Minimum accepted downloaded file size}
        {--source-geosite=https://raw.githubusercontent.com/SagerNet/sing-geosite/rule-set/geosite-cn.srs : Source URL for geosite-cn.srs}
        {--source-geoip=https://raw.githubusercontent.com/SagerNet/sing-geoip/rule-set/geoip-cn.srs : Source URL for geoip-cn.srs}
        {--refresh-template : Publish resources/rules/default.sing-box.json into the database template}';

    protected $description = 'Mirror Sing-box CN rule sets for subscription templates';

    private const TARGETS = [
        'geosite-cn.srs' => 'source-geosite',
        'geoip-cn.srs' => 'source-geoip',
    ];

    public function handle(): int
    {
        $directory = storage_path('app/rules/sing-box');
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            $this->error("Unable to create rule directory: {$directory}");
            return self::FAILURE;
        }

        $minBytes = max(1, (int) $this->option('min-bytes'));
        $failed = false;

        if ((bool) $this->option('refresh-template')) {
            $this->refreshTemplate();
        }

        foreach (self::TARGETS as $filename => $sourceOption) {
            $sourceUrl = trim((string) $this->option($sourceOption));
            $targetPath = $directory . DIRECTORY_SEPARATOR . $filename;

            try {
                $bytes = $this->downloadRuleSet($sourceUrl, $minBytes);
                $tmpPath = $targetPath . '.tmp.' . getmypid();
                file_put_contents($tmpPath, $bytes, LOCK_EX);
                rename($tmpPath, $targetPath);

                $metadata = [
                    'source' => $sourceUrl,
                    'filename' => $filename,
                    'bytes' => strlen($bytes),
                    'sha256' => hash('sha256', $bytes),
                    'synced_at' => now()->toIso8601String(),
                ];
                file_put_contents($targetPath . '.json', json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

                $this->info("Mirrored {$filename}: {$metadata['bytes']} bytes {$metadata['sha256']}");
            } catch (\Throwable $exception) {
                $failed = true;
                $message = "Failed to mirror {$filename}: {$exception->getMessage()}";
                $this->error($message);
                Log::warning('[SingBoxRules] ' . $message, ['source' => $sourceUrl]);

                if (is_file($targetPath)) {
                    $this->warn("Keeping existing {$filename}: " . filesize($targetPath) . ' bytes');
                }
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function refreshTemplate(): void
    {
        $path = base_path('resources/rules/default.sing-box.json');
        $content = file_get_contents($path);
        if ($content === false || trim($content) === '') {
            throw new \RuntimeException('default Sing-box template is empty or unreadable');
        }

        json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        SubscribeTemplate::setContent('singbox', $content);
        $this->info('Published default Sing-box template to database');
    }

    private function downloadRuleSet(string $sourceUrl, int $minBytes): string
    {
        if ($sourceUrl === '' || !filter_var($sourceUrl, FILTER_VALIDATE_URL)) {
            throw new \RuntimeException('invalid source URL');
        }

        $response = Http::timeout(30)
            ->connectTimeout(10)
            ->retry(2, 1000)
            ->withHeaders(['Accept' => 'application/octet-stream,*/*'])
            ->get($sourceUrl);

        if (!$response->successful()) {
            throw new \RuntimeException('HTTP ' . $response->status());
        }

        $body = $response->body();
        $length = strlen($body);
        if ($length < $minBytes) {
            throw new \RuntimeException("download too small ({$length} bytes)");
        }

        $prefix = strtolower(substr(ltrim($body), 0, 32));
        if (str_starts_with($prefix, '<!doctype') || str_starts_with($prefix, '<html')) {
            throw new \RuntimeException('download looks like HTML, refusing to replace rule set');
        }

        return $body;
    }
}
