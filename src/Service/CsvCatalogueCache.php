<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

final class CsvCatalogueCache
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {}

    public function getCacheDir(): string
    {
        return $this->projectDir . '/var/catalogue_cache';
    }

    public function getCachePath(): string
    {
        return $this->getCacheDir() . '/items_cache.csv';
    }

    public function getSourcePath(): string
    {
        return $this->getCacheDir() . '/source_url.txt';
    }

    public function getStatusPath(): string
    {
        return $this->getCacheDir() . '/status.json';
    }

    public function ensureDir(): void
    {
        $dir = $this->getCacheDir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
    }

    public function getSavedUrl(): ?string
    {
        $p = $this->getSourcePath();
        if (!is_file($p)) return null;
        $url = trim((string) @file_get_contents($p));
        return $url !== '' ? $url : null;
    }

    public function saveUrl(string $url): void
    {
        $this->ensureDir();
        file_put_contents($this->getSourcePath(), $url);
    }

    public function getStatus(): array
    {
        $p = $this->getStatusPath();
        if (!is_file($p)) {
            return [
                'ok' => null,
                'last_try_at' => null,
                'last_ok_at' => null,
                'source' => null,
                'size' => null,
                'message' => null,
            ];
        }

        $data = json_decode((string) file_get_contents($p), true);
        if (!is_array($data)) $data = [];

        return array_merge([
            'ok' => null,
            'last_try_at' => null,
            'last_ok_at' => null,
            'source' => null,
            'size' => null,
            'message' => null,
        ], $data);
    }

    private function writeStatus(array $status): void
    {
        $this->ensureDir();
        file_put_contents(
            $this->getStatusPath(),
            json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
    }

    /**
     * Télécharge le CSV depuis une URL et l’écrit dans var/catalogue_cache/items_cache.csv
     */
    public function updateCacheFromUrl(string $url, int $timeoutSeconds = 20): void
    {
        $this->ensureDir();
        $this->assertUrlAllowed($url);

        $status = $this->getStatus();
        $status['last_try_at'] = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $status['source'] = $url;

        try {
            $response = $this->httpClient->request('GET', $url, [
    'timeout' => $timeoutSeconds,
    'max_redirects' => 3,
    'headers' => [
        'Accept' => 'text/csv, text/plain, */*',
        'User-Agent' => 'HopicCatalogue/1.0',
    ],
    'extra' => [
        'curl' => [
            \CURLOPT_IPRESOLVE => \CURL_IPRESOLVE_V4, // OK ✅
            // ⚠️ ne pas mettre CURLOPT_CONNECTTIMEOUT (interdit)
        ],
    ],
]);

            $httpCode = $response->getStatusCode();
            if ($httpCode !== 200) {
                throw new \RuntimeException("HTTP $httpCode (pas 200).");
            }

            $tmp = $this->getCachePath() . '.tmp';
            $h = fopen($tmp, 'wb');
            if (!$h) {
                throw new \RuntimeException("Impossible d’écrire dans $tmp");
            }

            foreach ($this->httpClient->stream($response) as $chunk) {
                fwrite($h, $chunk->getContent());
            }
            fclose($h);

            if (!is_file($tmp) || filesize($tmp) < 10) {
                @unlink($tmp);
                throw new \RuntimeException("CSV téléchargé mais vide (ou trop petit).");
            }

            rename($tmp, $this->getCachePath());

            $status['ok'] = true;
            $status['last_ok_at'] = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
            $status['size'] = filesize($this->getCachePath());
            $status['message'] = 'Téléchargement OK.';
            $this->writeStatus($status);
        } catch (TransportExceptionInterface $e) {
            $status['ok'] = false;
            $status['message'] = "Couldn't connect to server (réseau/prod). " . $e->getMessage();
            $this->writeStatus($status);
            throw new \RuntimeException($status['message']);
        } catch (\Throwable $e) {
            $status['ok'] = false;
            $status['message'] = $e->getMessage();
            $this->writeStatus($status);
            throw $e;
        }
    }

    /**
     * Utilise un fichier uploadé pour écraser le cache local.
     */
    public function updateCacheFromUploadedFile(string $tmpPath): void
    {
        $this->ensureDir();

        if (!is_file($tmpPath)) {
            throw new \RuntimeException("Fichier upload introuvable.");
        }

        $content = (string) file_get_contents($tmpPath);
        if (strlen($content) < 10) {
            throw new \RuntimeException("Fichier upload vide (ou trop petit).");
        }

        file_put_contents($this->getCachePath(), $content);

        $status = $this->getStatus();
        $status['ok'] = true;
        $status['last_try_at'] = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $status['last_ok_at'] = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $status['source'] = 'UPLOAD';
        $status['size'] = filesize($this->getCachePath());
        $status['message'] = 'Upload OK.';
        $this->writeStatus($status);
    }

    private function assertUrlAllowed(string $url): void
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            throw new \InvalidArgumentException("URL invalide.");
        }

        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new \InvalidArgumentException("Seules les URLs http/https sont autorisées.");
        }

        $host = strtolower($parts['host'] ?? '');
        if ($host === '' || in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            throw new \InvalidArgumentException("Host interdit.");
        }

        // Anti-SSRF basique: refuser IP privées/réservées si l’host résout en IPv4
        $ips = @gethostbynamel($host) ?: [];
        foreach ($ips as $ip) {
            $ok = filter_var(
                $ip,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            );
            if ($ok === false) {
                throw new \InvalidArgumentException("Host non autorisé (IP privée/réservée).");
            }
        }
    }
}