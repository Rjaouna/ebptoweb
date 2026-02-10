<?php
// src/Service/FtpFileChecker.php
namespace App\Service;

final class FtpFileChecker
{
	public function existsRemote(array $cfg, string $remoteDir, string $fileName): array
	{
		if (!function_exists('ftp_connect')) {
			return ['ok' => false, 'message' => 'Extension FTP manquante', 'exists' => false];
		}

		$host = (string) $cfg['host'];
		$port = (int) ($cfg['port'] ?? 21);
		$user = (string) $cfg['username'];
		$pass = (string) $cfg['password'];
		$secure = (bool) ($cfg['secure'] ?? true);
		$timeoutMs = (int) ($cfg['timeoutMs'] ?? 20000);

		$timeoutSec = max(1, (int) ceil($timeoutMs / 1000));
		$fileName = basename($fileName);
		$remoteDir = trim($remoteDir) !== '' ? trim($remoteDir) : '/';

		$conn = null;

		try {
			$conn = $secure
				? @ftp_ssl_connect($host, $port, $timeoutSec)
				: @ftp_connect($host, $port, $timeoutSec);

			if (!$conn) {
				return ['ok' => false, 'message' => 'Connexion FTP impossible', 'exists' => false];
			}

			if (!@ftp_login($conn, $user, $pass)) {
				return ['ok' => false, 'message' => 'Login FTP incorrect', 'exists' => false];
			}

			@ftp_pasv($conn, true);

			if ($remoteDir !== '/' && !@ftp_chdir($conn, $remoteDir)) {
				return ['ok' => false, 'message' => "Dossier distant invalide: {$remoteDir}", 'exists' => false];
			}

			// ✅ check existence (ftp_size retourne -1 si absent)
			$size = @ftp_size($conn, $fileName);
			$exists = ($size !== -1);

			return [
				'ok' => true,
				'message' => $exists ? 'Fichier présent sur FTP' : 'Fichier absent sur FTP',
				'exists' => $exists,
				'remotePath' => rtrim($remoteDir, '/') . '/' . $fileName,
				'size' => $exists ? $size : null,
			];
		} catch (\Throwable $e) {
			return ['ok' => false, 'message' => 'Erreur: ' . $e->getMessage(), 'exists' => false];
		} finally {
			if ($conn) @ftp_close($conn);
		}
	}
}
