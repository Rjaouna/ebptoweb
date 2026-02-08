<?php
// src/Security/Encryptor.php
namespace App\Security;

final class Encryptor
{
	private string $key; // 32 bytes

	public function __construct(string $appSecret)
	{
		// 32 bytes
		$this->key = hash('sha256', $appSecret, true);
	}

	public function encrypt(string $plain): string
	{
		$nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
		$cipher = sodium_crypto_secretbox($plain, $nonce, $this->key);
		return base64_encode($nonce . $cipher);
	}

	public function decrypt(string $enc): string
	{
		$raw = base64_decode($enc, true);
		if ($raw === false || strlen($raw) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
			throw new \RuntimeException("Invalid encrypted payload");
		}
		$nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
		$cipher = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

		$plain = sodium_crypto_secretbox_open($cipher, $nonce, $this->key);
		if ($plain === false) throw new \RuntimeException("Decrypt failed");
		return $plain;
	}
}
