<?php

namespace App\Service;

class FtpCsvSyncer
{
    /**
     * Télécharge un CSV depuis FTP/FTPS vers un chemin local.
     * - Crée le dossier si besoin
     * - Écriture atomique (tmp puis rename)
     */
    public function download(array $conn, string $remoteDir, string $csvName, string $localPath): array
    {
        $host = (string)($conn['host'] ?? '');
        $port = (int)($conn['port'] ?? 21);
        $user = (string)($conn['username'] ?? '');
        $pass = (string)($conn['password'] ?? '');
        $secure = (bool)($conn['secure'] ?? true);
        $timeoutMs = (int)($conn['timeoutMs'] ?? 20000);

        if ($host === '' || $user === '' || $csvName === '') {
            return ['ok' => false, 'message' => 'Paramètres FTP invalides.'];
        }

        $timeoutSec = max(3, (int)ceil($timeoutMs / 1000));

        // ✅ crée le dossier local
        $dir = \dirname($localPath);
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
                return ['ok' => false, 'message' => 'Impossible de créer le dossier: ' . $dir];
            }
        }

        // ✅ fichier temp dans le même dossier (rename atomique)
        $tmp = $localPath . '.tmp_' . bin2hex(random_bytes(4));

        $connId = $secure ? @ftp_ssl_connect($host, $port, $timeoutSec) : @ftp_connect($host, $port, $timeoutSec);
        if (!$connId) {
            return ['ok' => false, 'message' => 'Connexion FTP/FTPS impossible.'];
        }

        try {
            @ftp_set_option($connId, FTP_TIMEOUT_SEC, $timeoutSec);

            if (!@ftp_login($connId, $user, $pass)) {
                return ['ok' => false, 'message' => 'Login FTP incorrect (username/password).'];
            }

            @ftp_pasv($connId, true);

            $remoteDir = trim((string)$remoteDir);
            if ($remoteDir !== '' && $remoteDir !== '/') {
                if (!@ftp_chdir($connId, $remoteDir)) {
                    return ['ok' => false, 'message' => 'Dossier distant introuvable: ' . $remoteDir];
                }
            }

            // ✅ download
            if (!@ftp_get($connId, $tmp, $csvName, FTP_BINARY)) {
                @unlink($tmp);
                return ['ok' => false, 'message' => 'Téléchargement FTP impossible: ' . $csvName];
            }

            // ✅ move atomique
            @rename($tmp, $localPath);

            return [
                'ok' => true,
                'message' => 'CSV synchronisé.',
                'localPath' => $localPath,
                'bytes' => @filesize($localPath) ?: 0,
                'mtime' => @filemtime($localPath) ?: null,
            ];
        } catch (\Throwable $e) {
            @unlink($tmp);
            return ['ok' => false, 'message' => 'Erreur sync: ' . $e->getMessage()];
        } finally {
            @ftp_close($connId);
        }
    }
}
