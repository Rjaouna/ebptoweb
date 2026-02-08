<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class FtpConnection
{
	#[ORM\Id]
	#[ORM\GeneratedValue]
	#[ORM\Column]
	private ?int $id = null;

	#[ORM\Column(length: 255)]
	private string $host = '';

	#[ORM\Column]
	private int $port = 21;

	#[ORM\Column(length: 255)]
	private string $username = '';

	// Stocké chiffré (via Encryptor)
	#[ORM\Column(type: 'text', nullable: true)]
	private ?string $passwordEnc = null;

	// FTPS (TLS)
	#[ORM\Column]
	private bool $secure = true;

	#[ORM\Column(length: 255, options: ['default' => '/'])]
	private string $remoteDir = '/';

	#[ORM\Column]
	private int $timeoutMs = 20000;

	#[ORM\Column(length: 255)]
	private string $csvName = 'items.csv';

	// Dernier état de test
	#[ORM\Column(nullable: true)]
	private ?bool $lastTestOk = null;

	#[ORM\Column(nullable: true)]
	private ?\DateTimeImmutable $lastTestAt = null;

	#[ORM\Column(type: 'text', nullable: true)]
	private ?string $lastTestMessage = null;

	// =========================
	// Getters / Setters
	// =========================

	public function getId(): ?int
	{
		return $this->id;
	}

	public function getHost(): string
	{
		return $this->host;
	}

	/**
	 * Tolère null pour éviter "Expected string, null given".
	 * La validation NotBlank se fait côté Form/Validator.
	 */
	public function setHost(?string $host): self
	{
		$host = trim((string) $host);
		$this->host = $host; // peut être '' si vide
		return $this;
	}

	public function getPort(): int
	{
		return $this->port;
	}

	public function setPort(int $port): self
	{
		$this->port = $port;
		return $this;
	}

	public function getUsername(): string
	{
		return $this->username;
	}

	public function setUsername(?string $username): self
	{
		$username = trim((string) $username);
		$this->username = $username; // peut être '' si vide
		return $this;
	}

	public function getPasswordEnc(): ?string
	{
		return $this->passwordEnc;
	}

	public function setPasswordEnc(?string $passwordEnc): self
	{
		$this->passwordEnc = $passwordEnc;
		return $this;
	}

	public function isSecure(): bool
	{
		return $this->secure;
	}

	public function setSecure(bool $secure): self
	{
		$this->secure = $secure;
		return $this;
	}

	public function getRemoteDir(): string
	{
		return $this->remoteDir;
	}

	public function setRemoteDir(?string $remoteDir): self
	{
		$remoteDir = trim((string) $remoteDir);
		$this->remoteDir = $remoteDir !== '' ? $remoteDir : '/';
		return $this;
	}

	public function getTimeoutMs(): int
	{
		return $this->timeoutMs;
	}

	public function setTimeoutMs(int $timeoutMs): self
	{
		$this->timeoutMs = $timeoutMs;
		return $this;
	}

	public function getCsvName(): string
	{
		return $this->csvName;
	}

	public function setCsvName(?string $csvName): self
	{
		$csvName = trim((string) $csvName);
		$this->csvName = $csvName !== '' ? $csvName : 'items.csv';
		return $this;
	}

	public function getLastTestOk(): ?bool
	{
		return $this->lastTestOk;
	}

	public function setLastTestOk(?bool $lastTestOk): self
	{
		$this->lastTestOk = $lastTestOk;
		return $this;
	}

	public function getLastTestAt(): ?\DateTimeImmutable
	{
		return $this->lastTestAt;
	}

	public function setLastTestAt(?\DateTimeImmutable $lastTestAt): self
	{
		$this->lastTestAt = $lastTestAt;
		return $this;
	}

	public function getLastTestMessage(): ?string
	{
		return $this->lastTestMessage;
	}

	public function setLastTestMessage(?string $lastTestMessage): self
	{
		$this->lastTestMessage = $lastTestMessage;
		return $this;
	}

	// =========================
	// Helpers "pro"
	// =========================

	public function touchTestResult(bool $ok, string $message, ?\DateTimeImmutable $at = null): self
	{
		$this->lastTestOk = $ok;
		$this->lastTestMessage = $message;
		$this->lastTestAt = $at ?? new \DateTimeImmutable();
		return $this;
	}

	public function getConnectionStatusLabel(): string
	{
		if ($this->lastTestOk === null) {
			return 'Jamais testé';
		}
		return $this->lastTestOk ? 'OK' : 'KO';
	}
}
