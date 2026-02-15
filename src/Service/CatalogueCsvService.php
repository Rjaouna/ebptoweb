<?php

namespace App\Service;

final class CatalogueCsvService
{
    public function __construct(private string $csvPath) {}

    /** @return array<string,array> uid => row */
    public function loadByUid(): array
    {
        if (!is_file($this->csvPath)) return [];

        $h = fopen($this->csvPath, 'rb');
        if (!$h) return [];

        $header = null;
        $map = [];

        while (($row = fgetcsv($h, 0, ';')) !== false) {
            if ($header === null) {
                $header = array_map(fn($x) => trim((string)$x), $row);
                continue;
            }
            if (!$row) continue;

            $assoc = [];
            foreach ($header as $i => $key) {
                $assoc[$key] = isset($row[$i]) ? trim((string)$row[$i]) : '';
            }

            $uid = $assoc['UniqueId'] ?? $assoc['Id'] ?? $assoc['ID'] ?? $assoc['id'] ?? null;
            $uid = $uid ? trim((string)$uid) : '';
            if ($uid !== '') $map[$uid] = $assoc;
        }

        fclose($h);
        return $map;
    }

    public function getStock(array $row): ?int
    {
        $raw = $row['RealStock'] ?? null;
        if ($raw === null) return null;

        $s = str_replace(',', '.', preg_replace('/[^0-9,\.\-]/', '', (string)$raw));
        $n = (int)floor((float)$s);
        return max(0, $n);
    }

    public function getName(array $row): string
    {
        return (string)($row['DesComClear'] ?? $row['Designation'] ?? 'Produit');
    }

    public function getUnitPriceTtc(array $row): ?float
    {
        $raw = $row['SalePriceVatIncluded'] ?? null;
        if ($raw === null) return null;
        $s = str_replace(',', '.', preg_replace('/[^0-9,\.\-]/', '', (string)$raw));
        $n = (float)$s;
        return is_finite($n) ? $n : null;
    }

    public function getUnitPriceHt(array $row): ?float
    {
        $raw = $row['SalePriceVatExcluded'] ?? null;
        if ($raw === null) return null;
        $s = str_replace(',', '.', preg_replace('/[^0-9,\.\-]/', '', (string)$raw));
        $n = (float)$s;
        return is_finite($n) ? $n : null;
    }
}
