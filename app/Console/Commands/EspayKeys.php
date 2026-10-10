<?php

namespace App\Console\Commands;

use App\Services\Espay\EspayClient;
use Illuminate\Console\Command;

/**
 * Membuat pasangan kunci RSA 2048 (PKCS#1) merchant untuk SNAP Espay.
 * Private key disimpan di server, public key dikirim ke tim Espay.
 */
class EspayKeys extends Command
{
    protected $signature = 'espay:keys
                            {env=sandbox : sandbox | production}
                            {--force : Timpa kunci yang sudah ada}';

    protected $description = 'Generate private/public key RSA merchant untuk Espay SNAP';

    public function handle()
    {
        $env = $this->argument('env');

        if (!in_array($env, ['sandbox', 'production'], true)) {
            $this->error('env harus sandbox atau production.');
            return self::FAILURE;
        }

        $dir = storage_path("app/espay/{$env}");
        $privatePath = "{$dir}/private.pem";
        $publicPath = "{$dir}/public.pem";

        if (is_file($privatePath) and !$this->option('force')) {
            $this->error("Kunci sudah ada: {$privatePath} (pakai --force untuk menimpa).");
            return self::FAILURE;
        }

        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ] + EspayClient::opensslConfig());

        if ($key === false or !openssl_pkey_export($key, $privatePem, null, EspayClient::opensslConfig())) {
            $this->error('Gagal membuat kunci: ' . openssl_error_string());
            return self::FAILURE;
        }

        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        file_put_contents($privatePath, $privatePem);
        file_put_contents($publicPath, openssl_pkey_get_details($key)['key']);

        $this->info("Private key : {$privatePath} (JANGAN dibagikan)");
        $this->info("Public key  : {$publicPath} (kirim ke tim Espay)");
        $this->line('Simpan public key Espay di: ' . config("espay.{$env}.espay_public_key"));

        return self::SUCCESS;
    }

}
