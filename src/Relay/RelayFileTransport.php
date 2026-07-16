<?php

declare(strict_types=1);

namespace DebugBundle\Relay;

final class RelayFileTransport
{
    private const LOCAL_EVENTS_DIRECTORY_MODE = 0700;
    private const LOCAL_EVENT_FILE_MODE = 0600;

    private int $sequence = 0;
    private bool $dirEnsured = false;

    public function __construct(
        private readonly string $eventsDir,
        string $serviceName,
    ) {
        $this->serviceName = self::sanitizeServiceName($serviceName);
    }

    private readonly string $serviceName;

    /** @param list<array<string, mixed>> $events */
    public function write(array $events): RelayFileWriteResult
    {
        if ($events === []) {
            return new RelayFileWriteResult(202);
        }

        try {
            if (!$this->dirEnsured) {
                if (!is_dir($this->eventsDir)) {
                    @mkdir($this->eventsDir, self::LOCAL_EVENTS_DIRECTORY_MODE, true);
                }
                $this->dirEnsured = true;
            }

            $timestamp = (int) floor(microtime(true) * 1000);
            $filename = sprintf('%d-%d-%s.events.json', $timestamp, ++$this->sequence, $this->serviceName);
            $finalPath = $this->eventsDir . DIRECTORY_SEPARATOR . $filename;
            $tmpPath = sprintf('%s.tmp-%s', $finalPath, bin2hex(random_bytes(8)));

            $this->assertNotSymlink($finalPath);
            $this->writeSecureTempFile($tmpPath, json_encode($events, JSON_THROW_ON_ERROR));
            rename($tmpPath, $finalPath);

            return new RelayFileWriteResult(202, $finalPath);
        } catch (\Throwable) {
            $this->cleanupTempFiles();
            return new RelayFileWriteResult(500);
        }
    }

    public static function resolveDefaultLocalEventsDir(string $cwd = ''): string
    {
        $base = $cwd !== '' ? $cwd : getcwd();
        return rtrim((string) $base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '.debugbundle' . DIRECTORY_SEPARATOR . 'local' . DIRECTORY_SEPARATOR . 'events';
    }

    public static function resolveDefaultRelaySpoolDir(string $cwd = ''): string
    {
        $base = $cwd !== '' ? $cwd : getcwd();
        return rtrim((string) $base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '.debugbundle' . DIRECTORY_SEPARATOR . 'local' . DIRECTORY_SEPARATOR . 'browser-relay-spool';
    }

    public static function markDelivered(string $writtenFilePath): void
    {
        try {
            file_put_contents($writtenFilePath . '.delivered', '');
        } catch (\Throwable) {
            return;
        }
    }

    private static function sanitizeServiceName(string $serviceName): string
    {
        $normalized = preg_replace('/[^A-Za-z0-9._-]+/', '-', trim($serviceName)) ?? 'service';
        $normalized = preg_replace('/-+/', '-', $normalized) ?? 'service';
        $normalized = trim($normalized, '-');

        return $normalized !== '' ? $normalized : 'service';
    }

    private function assertNotSymlink(string $targetPath): void
    {
        clearstatcache(true, $targetPath);
        if (is_link($targetPath)) {
            throw new \RuntimeException('symlink_path_rejected');
        }
    }

    private function writeSecureTempFile(string $tmpPath, string $payload): void
    {
        $handle = @fopen($tmpPath, 'xb');
        if ($handle === false) {
            throw new \RuntimeException('temp_file_create_failed');
        }

        chmod($tmpPath, self::LOCAL_EVENT_FILE_MODE);

        try {
            if (fwrite($handle, $payload) === false) {
                throw new \RuntimeException('temp_file_write_failed');
            }
        } finally {
            fclose($handle);
        }
    }

    private function cleanupTempFiles(): void
    {
        if (!is_dir($this->eventsDir)) {
            return;
        }

        $entries = scandir($this->eventsDir);
        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if (!str_contains($entry, '.tmp-')) {
                continue;
            }

            @unlink($this->eventsDir . DIRECTORY_SEPARATOR . $entry);
        }
    }
}
