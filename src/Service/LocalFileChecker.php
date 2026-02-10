<?php
// src/Service/LocalFileChecker.php
namespace App\Service;

final class LocalFileChecker
{
	public function exists(string $baseDir, string $fileName): array
	{
		$baseDir = rtrim($baseDir, "\\/");

		// Sécurité basique : empêche ../../
		$fileName = basename($fileName);

		$fullPath = $baseDir . DIRECTORY_SEPARATOR . $fileName;

		return [
			'exists' => is_file($fullPath),
			'readable' => is_readable($fullPath),
			'path' => $fullPath,
			'size' => is_file($fullPath) ? filesize($fullPath) : null,
			'modifiedAt' => is_file($fullPath) ? date('Y-m-d H:i:s', filemtime($fullPath)) : null,
		];
	}
}
