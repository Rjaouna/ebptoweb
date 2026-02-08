<?php

namespace App\Service;

final class FtpTester
{
	public function test(array $cfg): array
	{
		$host = (string) ($cfg['host'] ?? '');
		$port = (int) ($cfg['port'] ?? 21);
		$user = (string) ($cfg['username'] ?? '');
		$pass = (string) ($cfg['password'] ?? '');
		$secure = (bool) ($cfg['secure'] ?? false);
		$timeoutMs = (int) ($cfg['timeoutMs'] ?? 20000);
		$remoteDir = (string) ($cfg['remoteDir'] ?? '/');

		if ($host === '' || $user === '') {
			return ['ok' => false, 'message' => 'Host et username sont obligatoires.'];
		}

		if (!function_exists('ftp_connect')) {
			return ['ok' => false, 'message' => 'Extension PHP ftp manquante (php_ftp).'];
		}

		$timeoutSec = max(1, (int) ceil($timeoutMs / 1000));

		$conn = null;
		try {
			$conn = $secure
				? @ftp_ssl_connect($host, $port, $timeoutSec)
				: @ftp_connect($host, $port, $timeoutSec);

			if (!$conn) {
				return ['ok' => false, 'message' => 'Impossible de se connecter au serveur (host/port/FTPS).'];
			}

			if (!@ftp_login($conn, $user, $pass)) {
				return ['ok' => false, 'message' => 'Login FTP incorrect (username/password).'];
			}

			// Passif (souvent nécessaire)
			@ftp_pasv($conn, true);

			if ($remoteDir !== '' && $remoteDir !== '/' && !@ftp_chdir($conn, $remoteDir)) {
				return ['ok' => false, 'message' => "Connexion OK, mais dossier distant invalide: {$remoteDir}"];
			}

			// mini-check
			@ftp_nlist($conn, ".");

			return ['ok' => true, 'message' => 'Connexion FTP OK ✅'];
		} catch (\Throwable $e) {
			return ['ok' => false, 'message' => 'Erreur: ' . $e->getMessage()];
		} finally {
			if ($conn) {
				@ftp_close($conn);
			}
		}
	}
}
