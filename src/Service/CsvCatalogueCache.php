<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class CsvCatalogueCache
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {}

    public function getCachePath(): string
    {
        return $this->projectDir . '/var/catalogue_cache/items_cache.csv';
    }

    public function getSourcePath(): string
    {
        return $this->projectDir . '/var/catalogue_cache/source_url.txt';
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
        $dir = dirname($this->getSourcePath());
        if (!is_dir($dir)) @mkdir($dir, 0775, true);

        file_put_contents($this->getSourcePath(), $url);
    }

    public function updateCacheFromUrl(string $url, int $timeoutSeconds = 20): void
    {
        $this->assertUrlAllowed($url);

        $dir = dirname($this->getCachePath());
        if (!is_dir($dir)) @mkdir($dir, 0775, true);

        $response = $this->httpClient->request('GET', $url, [
            'timeout' => $timeoutSeconds,
            'max_redirects' => 3,
            'headers' => [
                'Accept' => 'text/csv, text/plain, */*',
            ],
        ]);

        $status = $response->getStatusCode();
        if ($status !== 200) {
            throw new \RuntimeException("Téléchargement CSV échoué (HTTP $status).");
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

        // petit check: fichier vide ?
        if (filesize($tmp) < 10) {
            @unlink($tmp);
            throw new \RuntimeException("CSV téléchargé mais semble vide.");
        }

        rename($tmp, $this->getCachePath());
    }

    private function assertUrlAllowed(string $url): void
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            throw new \InvalidArgumentException("URL invalide.");
        }
        $parts = parse_url($url);
        $scheme = $parts['scheme'] ?? '';
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new \InvalidArgumentException("Seules les URLs http/https sont autorisées.");
        }

        // Sécurité anti “SSRF” basique
        $host = strtolower($parts['host'] ?? '');
        if (in_array($host, ['localhost', '127.0.0.1'], true)) {
            throw new \InvalidArgumentException("Host interdit.");
        }
    }
}