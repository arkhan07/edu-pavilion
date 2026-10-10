<?php

namespace App\Services\Espay;

/**
 * Metode pembayaran Espay yang ditampilkan ke customer (dari Inquiry Merchant Info) + logo bank.
 *
 * Merchant Edu Pavilion memakai VA Static Open (tanpa Host to Host), sehingga yang ditawarkan hanya:
 *  - Virtual Account per bank (produk "...ATM", satu per kode bank)
 *  - QRIS (QR MPM)
 * Produk e-wallet redirect / kartu kredit / internet banking membutuhkan Host to Host dan disembunyikan.
 */
class EspayPaymentMethods
{
    public const LOGO_DIR = '/assets/default/img/espay';

    /** kode bank BI => [nama tampilan, file logo] */
    public const BANKS = [
        '014' => ['BCA', 'bca'],
        '009' => ['BNI', 'bni'],
        '002' => ['BRI', 'bri'],
        '008' => ['Mandiri', 'mandiri'],
        '013' => ['Permata', 'permata'],
        '022' => ['CIMB Niaga', 'cimb'],
        '011' => ['Danamon', 'danamon'],
        '016' => ['Maybank', 'maybank'],
        '451' => ['BSI', 'bsi'],
        '200' => ['BTN', 'btn'],
    ];

    /**
     * @return array{va: array<int, array>, qris: ?array}
     */
    public static function fromMerchantInfo(array $products): array
    {
        $va = [];
        $qris = null;

        foreach ($products as $product) {
            $bankCode = (string) ($product['bankCode'] ?? '');
            $code = strtoupper((string) ($product['productCode'] ?? ''));

            if ($bankCode === '' or $code === '') {
                continue;
            }

            if (EspayPaymentService::isQrisProduct($code)) {
                $qris ??= [
                    'bank_code' => $bankCode,
                    'code' => $product['productCode'],
                    'name' => 'QRIS',
                    'hint' => 'Scan dengan e-wallet atau m-banking apa pun',
                    'logo' => self::logoUrl('qris'),
                ];
                continue;
            }

            if (str_ends_with($code, 'ATM') and !isset($va[$bankCode])) {
                $va[$bankCode] = [
                    'bank_code' => $bankCode,
                    'code' => $product['productCode'],
                    'name' => self::bankName($bankCode, $product['productName'] ?? $code) . ' Virtual Account',
                    'hint' => 'Dicek otomatis',
                    'logo' => self::bankLogo($bankCode),
                ];
            }
        }

        // urutan bank yang paling umum dipakai lebih dulu
        $order = array_keys(self::BANKS);
        uksort($va, function ($a, $b) use ($order) {
            $ia = array_search($a, $order, true);
            $ib = array_search($b, $order, true);

            return ($ia === false ? 99 : $ia) <=> ($ib === false ? 99 : $ib);
        });

        return ['va' => array_values($va), 'qris' => $qris];
    }

    /**
     * Produk yang dipilih customer (hanya VA / QRIS yang ditawarkan).
     */
    public static function find(array $products, string $bankCode, string $productCode): ?array
    {
        $methods = self::fromMerchantInfo($products);

        foreach (array_merge($methods['va'], array_filter([$methods['qris']])) as $method) {
            if ($method['bank_code'] === $bankCode and $method['code'] === $productCode) {
                return $method;
            }
        }

        return null;
    }

    public static function bankName(?string $bankCode, ?string $fallback = null): string
    {
        return self::BANKS[(string) $bankCode][0] ?? trim(preg_replace('/\s*(VA|Virtual Account)(\s*Online)?$/i', '', (string) $fallback)) ?: 'Bank';
    }

    public static function bankLogo(?string $bankCode): string
    {
        return self::logoUrl(self::BANKS[(string) $bankCode][1] ?? 'bank');
    }

    /**
     * Deretan logo untuk kartu "Bayar via Espay" di halaman pilih payment gateway.
     */
    public static function showcase(): array
    {
        return array_map(fn ($file) => ['name' => strtoupper($file), 'logo' => self::logoUrl($file)], ['qris', 'bca', 'mandiri', 'bri', 'bni', 'permata']);
    }

    private static function logoUrl(string $file): string
    {
        return self::LOGO_DIR . '/' . $file . '.svg';
    }
}
