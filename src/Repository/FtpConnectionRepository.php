<?php

namespace App\Repository;

use App\Security\Encryptor;
use App\Entity\FtpConnection;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;

/**
 * @extends ServiceEntityRepository<FtpConnection>
 */
class FtpConnectionRepository extends ServiceEntityRepository
{
	public function __construct(ManagerRegistry $registry)
	{
		parent::__construct($registry, FtpConnection::class);
	}

	/**
	 * Récupère la config FTP la plus récente (ou null si aucune).
	 */
	public function getLatest(): ?FtpConnection
	{
		return $this->createQueryBuilder('f')
			->orderBy('f.id', 'DESC')
			->setMaxResults(1)
			->getQuery()
			->getOneOrNullResult();
	}

	/**
	 * Teste la connexion (FTP/FTPS) + accès au dossier + présence du CSV.
	 * Met à jour lastTest* via touchTestResult().
	 *
	 * @return array{ok:bool,message:string}
	 */
	public function testConnection(FtpConnection $cfg, Encryptor $encryptor): array
	{
		try {
			$host = trim($cfg->getHost());
			$user = trim($cfg->getUsername());

			if ($host === '' || $user === '') {
				throw new \RuntimeException("Host/Username manquants.");
			}

			$port = (int) $cfg->getPort();
			$secure = (bool) $cfg->isSecure();
			$remoteDir = trim($cfg->getRemoteDir()) ?: '/';
			$csvName = trim($cfg->getCsvName()) ?: 'items.csv';
			$timeoutSec = max(3, (int) ceil(((int)$cfg->getTimeoutMs()) / 1000));

			$passEnc = $cfg->getPasswordEnc() ?? '';
			$pass = $passEnc !== '' ? $encryptor->decrypt($passEnc) : '';

			$conn = $this->openFtp($secure, $host, $port, $timeoutSec);
			try {
				$this->loginFtp($conn, $user, $pass, $timeoutSec);

				// Vérifie dossier
				if ($remoteDir !== '' && $remoteDir !== '/') {
					if (!@ftp_chdir($conn, $remoteDir)) {
						throw new \RuntimeException("Dossier distant introuvable: {$remoteDir}");
					}
				}

				// Vérifie présence du fichier CSV
				$list = @ftp_nlist($conn, ".");
				if (!is_array($list)) {
					throw new \RuntimeException("Impossible de lister le dossier distant.");
				}

				$found = false;
				foreach ($list as $item) {
					if (basename($item) === $csvName) {
						$found = true;
						break;
					}
				}
				if (!$found) {
					throw new \RuntimeException("CSV introuvable dans le dossier: {$csvName}");
				}
			} finally {
				@ftp_close($conn);
			}

			$cfg->touchTestResult(true, "Connexion OK. CSV trouvé: {$csvName}");
			$this->getEntityManager()->flush();

			return ['ok' => true, 'message' => "OK"];
		} catch (\Throwable $e) {
			$cfg->touchTestResult(false, $e->getMessage());
			$this->getEntityManager()->flush();

			return ['ok' => false, 'message' => $e->getMessage()];
		}
	}

	/**
	 * Télécharge le CSV en local (fichier temporaire) et renvoie le chemin du fichier.
	 * Tu dois ensuite lire le fichier et le supprimer.
	 *
	 * @throws \RuntimeException
	 */
	public function downloadCsvToTempFile(FtpConnection $cfg, Encryptor $encryptor): string
	{
		$host = trim($cfg->getHost());
		$user = trim($cfg->getUsername());

		if ($host === '' || $user === '') {
			throw new \RuntimeException("Configuration FTP invalide (host/username vides).");
		}

		$port = (int) $cfg->getPort();
		$secure = (bool) $cfg->isSecure();
		$remoteDir = trim($cfg->getRemoteDir()) ?: '/';
		$csvName = trim($cfg->getCsvName()) ?: 'items.csv';
		$timeoutSec = max(3, (int) ceil(((int)$cfg->getTimeoutMs()) / 1000));

		$passEnc = $cfg->getPasswordEnc() ?? '';
		$pass = $passEnc !== '' ? $encryptor->decrypt($passEnc) : '';

		$conn = $this->openFtp($secure, $host, $port, $timeoutSec);
		try {
			$this->loginFtp($conn, $user, $pass, $timeoutSec);

			if ($remoteDir !== '' && $remoteDir !== '/') {
				if (!@ftp_chdir($conn, $remoteDir)) {
					throw new \RuntimeException("Dossier distant introuvable: {$remoteDir}");
				}
			}

			$tmp = tempnam(sys_get_temp_dir(), 'ftp_csv_');
			if ($tmp === false) {
				throw new \RuntimeException("Impossible de créer un fichier temporaire.");
			}

			if (!@ftp_get($conn, $tmp, $csvName, FTP_BINARY)) {
				@unlink($tmp);
				throw new \RuntimeException("Téléchargement CSV impossible: {$csvName}");
			}

			return $tmp;
		} finally {
			@ftp_close($conn);
		}
	}

    // =========================
    // Helpers FTP internes
    // =========================

	/**
	 * @return resource
	 */
	private function openFtp(bool $secure, string $host, int $port, int $timeoutSec)
	{
		$conn = $secure ? @ftp_ssl_connect($host, $port, $timeoutSec) : @ftp_connect($host, $port, $timeoutSec);
		if (!$conn) {
			throw new \RuntimeException("Impossible de se connecter au serveur " . ($secure ? "FTPS" : "FTP") . ".");
		}
		return $conn;
	}

	/**
	 * @param resource $conn
	 */
	private function loginFtp($conn, string $user, string $pass, int $timeoutSec): void
	{
		@ftp_set_option($conn, FTP_TIMEOUT_SEC, $timeoutSec);

		if (!@ftp_login($conn, $user, $pass)) {
			throw new \RuntimeException("Login FTP incorrect (username/password).");
		}

		// Recommandé pour éviter les blocages NAT
		@ftp_pasv($conn, true);
	}
}
