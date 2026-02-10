<?php

namespace App\Service;

use App\Entity\FtpConnection;
use App\Security\Encryptor;

final class FtpCsvReader
{
	public function __construct(
		private readonly Encryptor $encryptor
	) {}

	/**
	 * Lit le CSV depuis FTP, applique filtres, retourne pagination + infos.
	 *
	 * @param array<string, string> $filters ex: ['xx_Marque' => 'TOYOTA', 'q' => '123']
	 * @return array{
	 *  ok:bool,
	 *  message:string,
	 *  headers:string[],
	 *  rows:array<int, array<string, string|null>>,
	 *  total:int,
	 *  page:int,
	 *  limit:int,
	 *  remotePath:string|null,
	 *  filterOptions:array<string, string[]>
	 * }
	 */
	public function list(
		FtpConnection $cfg,
		array $filters = [],
		int $page = 1,
		int $limit = 50,
		string $delimiter = ';'
	): array {
		$page = max(1, $page);
		$limit = max(10, min(200, $limit));

		if (!function_exists('ftp_connect')) {
			return $this->ko('Extension PHP ftp manquante (php_ftp).');
		}

		$host = trim($cfg->getHost());
		$port = (int) $cfg->getPort();
		$user = trim($cfg->getUsername());
		$secure = (bool) $cfg->isSecure();
		$timeoutSec = max(1, (int) ceil($cfg->getTimeoutMs() / 1000));
		$remoteDir = trim($cfg->getRemoteDir()) ?: '/';
		$fileName = basename($cfg->getCsvName());

		$password = $cfg->getPasswordEnc()
			? $this->encryptor->decrypt($cfg->getPasswordEnc())
			: '';

		$conn = null;
		$tmpFile = null;

		try {
			$conn = $secure
				? @ftp_ssl_connect($host, $port, $timeoutSec)
				: @ftp_connect($host, $port, $timeoutSec);

			if (!$conn) return $this->ko("Connexion FTP impossible ({$host}:{$port}).");

			if (!@ftp_login($conn, $user, $password)) {
				return $this->ko('Login FTP incorrect (username/password).');
			}

			@ftp_pasv($conn, true);

			if ($remoteDir !== '/' && !@ftp_chdir($conn, $remoteDir)) {
				return $this->ko("Dossier distant invalide: {$remoteDir}");
			}

			$size = @ftp_size($conn, $fileName);
			if ($size === -1) {
				return $this->ko("CSV introuvable sur FTP: {$fileName}");
			}

			$tmpFile = tempnam(sys_get_temp_dir(), 'csv_');
			if (!$tmpFile) return $this->ko('Impossible de créer un fichier temporaire.');

			$fp = fopen($tmpFile, 'wb');
			if (!$fp) return $this->ko('Impossible d’ouvrir un fichier temporaire.');
			$ok = @ftp_fget($conn, $fp, $fileName, FTP_BINARY, 0);
			fclose($fp);

			if (!$ok) return $this->ko('Téléchargement CSV impossible.');

			// Parse CSV
			$fh = fopen($tmpFile, 'rb');
			if (!$fh) return $this->ko('Impossible de relire le fichier CSV.');

			// BOM UTF-8
			$b = fread($fh, 3);
			if ($b !== "\xEF\xBB\xBF") rewind($fh);

			$headers = fgetcsv($fh, 0, $delimiter);
			if (!is_array($headers) || count($headers) === 0) {
				fclose($fh);
				return $this->ko("Header illisible. Vérifie le séparateur '{$delimiter}'.");
			}
			$headers = array_map(fn($h) => trim((string) $h), $headers);

			// --- Choix colonnes "filtrables" ---
			// On propose q (recherche globale) + listes sur quelques colonnes courantes si elles existent.
			$candidate = ['xx_Marque', 'xx_Vehicule', 'xx_Annee', 'FamilyName', 'xx_Position'];
			$filterCols = array_values(array_intersect($candidate, $headers));

			// Options filtres (valeurs uniques) sur un échantillon pour éviter de tuer le navigateur.
			$maxForOptions = 2000; // lignes max scannées pour générer les options
			$options = [];
			foreach ($filterCols as $c) $options[$c] = [];

			$q = trim((string)($filters['q'] ?? ''));
			unset($filters['q']);

			// Pagination
			$offsetWanted = ($page - 1) * $limit;
			$rows = [];
			$totalMatched = 0;
			$scanned = 0;

			while (($line = fgetcsv($fh, 0, $delimiter)) !== false) {
				if (!is_array($line)) continue;

				$assoc = [];
				foreach ($headers as $i => $name) {
					$assoc[$name] = array_key_exists($i, $line) ? $line[$i] : null;
				}

				// build filter options (échantillon)
				if ($scanned < $maxForOptions) {
					foreach ($filterCols as $col) {
						$val = trim((string)($assoc[$col] ?? ''));
						if ($val !== '') {
							$options[$col][$val] = true;
						}
					}
				}

				$scanned++;

				// Apply filters
				if ($q !== '') {
					$hay = implode(' ', array_map(fn($v) => (string)$v, $assoc));
					if (stripos($hay, $q) === false) {
						continue;
					}
				}

				$pass = true;
				foreach ($filters as $col => $value) {
					$value = trim((string)$value);
					if ($value === '' || !in_array($col, $headers, true)) continue;

					$cell = trim((string)($assoc[$col] ?? ''));
					if ($cell !== $value) {
						$pass = false;
						break;
					}
				}
				if (!$pass) continue;

				// Matched
				$totalMatched++;

				if ($totalMatched <= $offsetWanted) {
					continue; // skip until page start
				}

				if (count($rows) < $limit) {
					$rows[] = $assoc;
				} else {
					// on a déjà rempli la page, mais on continue pour compter totalMatched
					// (si CSV énorme, on optimisera avec un "estimation mode")
				}
			}

			fclose($fh);

			// format options
			$filterOptions = [];
			foreach ($options as $col => $vals) {
				$list = array_keys($vals);
				sort($list, SORT_NATURAL | SORT_FLAG_CASE);
				$filterOptions[$col] = array_slice($list, 0, 300); // limite UI
			}

			return [
				'ok' => true,
				'message' => 'OK',
				'headers' => $headers,
				'rows' => $rows,
				'total' => $totalMatched,
				'page' => $page,
				'limit' => $limit,
				'remotePath' => rtrim($remoteDir, '/') . '/' . $fileName,
				'filterOptions' => $filterOptions,
			];
		} catch (\Throwable $e) {
			return $this->ko('Erreur: ' . $e->getMessage());
		} finally {
			if ($conn) @ftp_close($conn);
			if ($tmpFile && is_file($tmpFile)) @unlink($tmpFile);
		}
	}

	private function ko(string $msg): array
	{
		return [
			'ok' => false,
			'message' => $msg,
			'headers' => [],
			'rows' => [],
			'total' => 0,
			'page' => 1,
			'limit' => 50,
			'remotePath' => null,
			'filterOptions' => [],
		];
	}
}
