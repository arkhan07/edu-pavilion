<?php

namespace App\Services\Espay;

use App\Models\PaymentChannel;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Kredensial Espay yang diatur dari Admin > Settings > Payment Channels > Espay.
 * Disimpan di payment_channels.credentials (JSON); private key dienkripsi dengan APP_KEY.
 * Nilai yang kosong di panel admin memakai fallback dari .env (config/espay.php).
 */
class EspaySettings
{
    public const ENVIRONMENTS = ['sandbox', 'production'];
    public const FIELDS = ['merchant_code', 'api_key', 'signature_key', 'password', 'private_key', 'espay_public_key',
        'disbursement_partner_id', 'disbursement_source_account', 'disbursement_source_bank_code'];

    private static ?array $cache = null;

    /**
     * @return array{mode: ?string, sandbox: array, production: array}
     */
    public static function current(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $settings = ['mode' => null, 'sandbox' => [], 'production' => []];

        try {
            $channel = PaymentChannel::query()->where('class_name', EspayPaymentService::GATEWAY)->first();
            $stored = $channel?->credentials;
        } catch (\Throwable $e) {
            $stored = null;
        }

        if (is_array($stored)) {
            $settings['mode'] = in_array($stored['mode'] ?? null, self::ENVIRONMENTS, true) ? $stored['mode'] : null;

            foreach (self::ENVIRONMENTS as $env) {
                foreach (self::FIELDS as $field) {
                    $value = $stored[$env][$field] ?? null;

                    if ($field === 'private_key' and !empty($value)) {
                        $value = self::decrypt($value);
                    }

                    if (is_string($value) and trim($value) !== '') {
                        $settings[$env][$field] = trim($value);
                    }
                }
            }
        }

        return self::$cache = $settings;
    }

    public static function flush(): void
    {
        self::$cache = null;
    }

    /**
     * Menyiapkan input form admin untuk disimpan: private key dienkripsi, field rahasia yang
     * dikosongkan tetap memakai nilai lama, dan opsi "generate kunci baru" dijalankan.
     */
    public static function prepareForSave(array $input, ?array $existing): array
    {
        $existing = is_array($existing) ? $existing : [];
        $result = [
            'mode' => in_array($input['mode'] ?? null, self::ENVIRONMENTS, true) ? $input['mode'] : 'sandbox',
        ];

        foreach (self::ENVIRONMENTS as $env) {
            $envInput = is_array($input[$env] ?? null) ? $input[$env] : [];
            $envExisting = is_array($existing[$env] ?? null) ? $existing[$env] : [];

            $result[$env] = [
                'merchant_code' => trim((string) ($envInput['merchant_code'] ?? '')),
                'api_key' => trim((string) ($envInput['api_key'] ?? '')),
                'signature_key' => trim((string) ($envInput['signature_key'] ?? '')),
                'password' => trim((string) ($envInput['password'] ?? '')),
                'espay_public_key' => self::normalizePem($envInput['espay_public_key'] ?? ''),
                'private_key' => $envExisting['private_key'] ?? null,
                'disbursement_partner_id' => trim((string) ($envInput['disbursement_partner_id'] ?? '')),
                // model Deposit memakai "PTPLUS", model Direct memakai nomor rekening
                'disbursement_source_account' => preg_replace('/[^A-Za-z0-9]/', '', (string) ($envInput['disbursement_source_account'] ?? '')),
                'disbursement_source_bank_code' => preg_replace('/\D/', '', (string) ($envInput['disbursement_source_bank_code'] ?? '')),
            ];

            if (!empty($result[$env]['espay_public_key']) and !openssl_pkey_get_public($result[$env]['espay_public_key'])) {
                throw new RuntimeException('Public key Espay (' . $env . ') tidak valid. Gunakan format PEM "-----BEGIN PUBLIC KEY-----".');
            }

            $newPrivateKey = self::normalizePem($envInput['private_key'] ?? '');

            if (!empty($envInput['generate_key'])) {
                $newPrivateKey = self::generatePrivateKey();
            }

            if (!empty($newPrivateKey)) {
                if (!openssl_pkey_get_private($newPrivateKey)) {
                    throw new RuntimeException('Private key merchant (' . $env . ') tidak valid. Gunakan format PEM "-----BEGIN (RSA) PRIVATE KEY-----".');
                }

                $result[$env]['private_key'] = Crypt::encryptString($newPrivateKey);
            }

            if (!empty($envInput['remove_private_key'])) {
                $result[$env]['private_key'] = null;
            }
        }

        self::flush();

        return $result;
    }

    /**
     * Public key merchant (dari private key yang tersimpan) untuk dikirim ke tim Espay.
     */
    public static function merchantPublicKey(string $env): ?string
    {
        $privateKey = self::current()[$env]['private_key'] ?? null;

        // belum ada key di panel -> pakai file dari .env (hasil php artisan espay:keys)
        if (empty($privateKey)) {
            $path = config("espay.{$env}.private_key");
            $path = (!empty($path) and !is_file($path)) ? base_path($path) : $path;
            $privateKey = (!empty($path) and is_file($path)) ? file_get_contents($path) : null;
        }

        $key = !empty($privateKey) ? openssl_pkey_get_private($privateKey) : false;

        return $key ? (openssl_pkey_get_details($key)['key'] ?? null) : null;
    }

    public static function generatePrivateKey(): string
    {
        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ] + EspayClient::opensslConfig());

        if ($key === false or !openssl_pkey_export($key, $pem, null, EspayClient::opensslConfig())) {
            throw new RuntimeException('Gagal membuat private key: ' . openssl_error_string());
        }

        return $pem;
    }

    private static function normalizePem($value): string
    {
        return trim(str_replace(["\r\n", '\n'], "\n", (string) $value));
    }

    private static function decrypt(string $value): ?string
    {
        try {
            return Crypt::decryptString($value);
        } catch (\Throwable $e) {
            Log::error('[Espay] private key di database tidak bisa didekripsi (APP_KEY berubah?)');
            return null;
        }
    }
}
