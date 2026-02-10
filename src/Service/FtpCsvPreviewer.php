<?php

namespace App\Service;

use App\Entity\FtpConnection;
use App\Security\Encryptor;

final class FtpCsvPreviewer
{
	public function __construct(
		private readonly Encryptor $encryptor
	) {}

	/**
	 * @return array{ok:bool,message:string,headers:array,rows:array,delimiter:string,remotePath:string|null,size:int|null}
	 */
	public function preview(FtpConnection $cfg, int $maxRows = 5, string $delimiter = ';'): array
	{
		if (!function_exists('ftp_connect')) {
			return ['ok' => false, 'message' => 'Extension PHP ftp manquante (php_ftp).', 'headers' => [], 'rows' => [], 'delimiter' => $delimiter, 'remotePath' => null, 'size' => null];
		}

		$host = trim($cfg->getHost());
		$port = (int) $cfg->getPort();
		$user = trim($cfg->getUsername());
		$secure = (bool) $cfg->isSecure();
		$timeoutSec = max(1, (int) ceil($cfg->getTimeoutMs() / 1000));
		$remoteDir = trim($cfg->getRemoteDir()) ?: '/';
		$fileName = basename($cfg->getCsvName());

		$password = '';
		if ($cfg->getPasswordEnc()) {
			$password = $this->encryptor->decrypt($cfg->getPasswordEnc());
		}

		$conn = null;
		$tmpFile = null;

		try {
			// 1) Connect
			$conn = $secure
				? @ftp_ssl_connect($host, $port, $timeoutSec)
				: @ftp_connect($host, $port, $timeoutSec);

			if (!$conn) {
				return ['ok' => false, 'message' => "Connexion FTP impossible ({$host}:{$port}).", 'headers' => [], 'rows' => [], 'delimiter' => $delimiter, 'remotePath' => null, 'size' => null];
			}

			if (!@ftp_login($conn, $user, $password)) {
				return ['ok' => false, 'message' => 'Login FTP incorrect (username/password).', 'headers' => [], 'rows' => [], 'delimiter' => $delimiter, 'remotePath' => null, 'size' => null];
			}

			@ftp_pasv($conn, true);

			// 2) cd remote dir
			if ($remoteDir !== '/' && !@ftp_chdir($conn, $remoteDir)) {
				return ['ok' => false, 'message' => "Dossier distant invalide: {$remoteDir}", 'headers' => [], 'rows' => [], 'delimiter' => $delimiter, 'remotePath' => null, 'size' => null];
			}

			// 3) check file exists
			$size = @ftp_size($conn, $fileName);
			if ($size === -1) {
				return [
					'ok' => false,
					'message' => "Fichier CSV introuvable sur FTP: {$fileName}",
					'headers' => [],
					'rows' => [],
					'delimiter' => $delimiter,
					'remotePath' => rtrim($remoteDir, '/') . '/' . $fileName,
					'size' => null,
				];
			}

			// 4) download to temp file
			$tmpFile = tempnam(sys_get_temp_dir(), 'csv_');
			if (!$tmpFile) {
				return ['ok' => false, 'message' => 'Impossible de créer un fichier temporaire.', 'headers' => [], 'rows' => [], 'delimiter' => $delimiter, 'remotePath' => null, 'size' => null];
			}

			$fp = fopen($tmpFile, 'wb');
			if (!$fp) {
				return ['ok' => false, 'message' => 'Impossible d’ouvrir le fichier temporaire.', 'headers' => [], 'rows' => [], 'delimiter' => $delimiter, 'remotePath' => null, 'size' => null];
			}

			// ⚠️ ftp_fget télécharge le fichier (FTP ne permet pas de lire “juste 5 lignes” facilement)
			$ok = @ftp_fget($conn, $fp, $fileName, FTP_BINARY, 0);
			fclose($fp);

			if (!$ok) {
				return ['ok' => false, 'message' => 'Téléchargement FTP du CSV impossible.', 'headers' => [], 'rows' => [], 'delimiter' => $delimiter, 'remotePath' => null, 'size' => null];
			}

			// 5) parse header + first rows
			$fh = fopen($tmpFile, 'rb');
			if (!$fh) {
				return ['ok' => false, 'message' => 'Impossible de relire le fichier temporaire.', 'headers' => [], 'rows' => [], 'delimiter' => $delimiter, 'remotePath' => null, 'size' => null];
			}

			// BOM UTF-8
			$b = fread($fh, 3);
			if ($b !== "\xEF\xBB\xBF") rewind($fh);

			$headers = fgetcsv($fh, 0, $delimiter);
			if (!is_array($headers) || count($headers) === 0) {
				fclose($fh);
				return ['ok' => false, 'message' => "Header illisible. Vérifie le séparateur (actuel: '{$delimiter}').", 'headers' => [], 'rows' => [], 'delimiter' => $delimiter, 'remotePath' => null, 'size' => $size];
			}

			$headers = array_map(fn($h) => trim((string)$h), $headers);

			$rows = [];
			$maxRows = max(1, min($maxRows, 50));

			while (count($rows) < $maxRows && ($line = fgetcsv($fh, 0, $delimiter)) !== false) {
				if (!is_array($line)) continue;

				$assoc = [];
				foreach ($headers as $i => $name) {
					$assoc[$name] = array_key_exists($i, $line) ? $line[$i] : null;
				}
				$rows[] = $assoc;
			}

			fclose($fh);

			return [
				'ok' => true,
				'message' => 'Preview CSV OK',
				'headers' => $headers,
				'rows' => $rows,
				'delimiter' => $delimiter,
				'remotePath' => rtrim($remoteDir, '/') . '/' . $fileName,
				'size' => $size,
			];
		} catch (\Throwable $e) {
			return ['ok' => false, 'message' => 'Erreur: ' . $e->getMessage(), 'headers' => [], 'rows' => [], 'delimiter' => $delimiter, 'remotePath' => null, 'size' => null];
		} finally {
			if ($conn) @ftp_close($conn);
			if ($tmpFile && is_file($tmpFile)) @unlink($tmpFile);
		}
	}
}
