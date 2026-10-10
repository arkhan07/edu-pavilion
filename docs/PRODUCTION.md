# Tutorial Pindah ke Production - edupavilion.com

Panduan ini untuk memindahkan integrasi Espay dari **staging (sandbox)** ke **server production Edu Pavilion**.
Ikuti urutannya: server disiapkan dulu (supaya IP dan URL callback sudah pasti dan bisa diakses), baru follow-up ke Espay.

**Data server production**

| Item | Nilai |
|---|---|
| Domain | `https://edupavilion.com` |
| IP publik server | `103.245.39.23` |
| Panel & web server | Webmin/Virtualmin, Apache |
| Folder aplikasi | `/home/edupavilion/public_html/lms` |
| User pemilik aplikasi | `edupavilion` |
| Email / WhatsApp Customer Service | `info@edupavilion.com` / +62 852-8145-5797 |

## 0. Apa saja yang berubah?

| Item | Staging (sandbox) | Production | Siapa yang mengubah |
|---|---|---|---|
| Domain / URL callback | `https://work.ayoo.web.id/...` | `https://edupavilion.com/...` | Kita kirim ke Espay, Espay mendaftarkan |
| IP server (whitelist di Espay) | `72.61.210.19` | `103.245.39.23` | Kita kirim, Espay whitelist |
| Merchant code, API key, signature key, password | kredensial sandbox | kredensial production (dari Espay) | Espay memberi, kita isi di Admin Panel |
| Private/public key merchant (RSA) | sample key dokumentasi Espay | **pasangan kunci baru** | Kita buat, public key dikirim ke Espay |
| Public key Espay | key sandbox | key production Espay | Espay memberi |
| Host API | `sandbox-api.espay.id` | `api-merchant.espay.id` / `api.espay.id` | Konfirmasi ke Espay |
| IP Espay yang memanggil callback | `117.54.40.34`, `117.54.40.35`, `139.255.73.91` | `139.255.109.146` (konfirmasi ke Espay) | Kita izinkan di firewall |
| Sumber dana disbursement | `PTPLUS` / bank `022`, deposit sandbox Rp100 jt | sourceAccountNo & bank code production, deposit asli | Espay memberi, kita top up deposit |
| Fee disbursement | diatur Espay di sandbox | harus diatur ulang di production | Espay |

> **Penting:** jangan pernah memakai pasangan key sandbox di production. Key sandbox adalah sample key dari dokumentasi Espay (publik).

---

## 1. Siapkan server production

Semua perintah dijalankan di folder aplikasi sebagai user `edupavilion` (bukan root):
```bash
cd /home/edupavilion/public_html/lms
```

### 1.1 Deploy kode
1. Salin **seluruh** isi repo ini ke `/home/edupavilion/public_html/lms` dengan struktur folder yang sama
   (jangan satu per satu: file CSS/JS/gambar di `public/` sering terlewat).
2. Dependensi: `barryvdh/laravel-dompdf` (sudah ada di `composer.json` Rocket LMS), lalu `composer install --no-dev -o`.
3. Cek migrasi yang belum jalan, lalu jalankan **hanya migrasi Espay** dengan `--path` (supaya migrasi lain yang tertunda tidak ikut jalan):
   ```bash
   php artisan migrate:status | grep -i "2026_10"
   php artisan migrate --force --path=database/migrations/2026_10_05_000001_add_provider_columns_to_payouts_table.php
   php artisan migrate --force --path=database/migrations/2026_10_05_000002_create_espay_virtual_accounts_tables.php
   php artisan migrate --force --path=database/migrations/2026_10_06_000001_add_payment_ref_to_espay_va_payments.php
   php artisan migrate --force --path=database/migrations/2026_10_10_000001_add_espay_payment_channel.php
   ```
   Migrasi terakhir mendaftarkan channel **Espay** di Admin > Pengaturan > Keuangan > Payment Gateways (status nonaktif).
4. Bersihkan cache:
   ```bash
   php artisan optimize:clear
   ```
5. Pastikan aset ikut ter-upload (harus `200` dengan tipe `text/css` / `image/svg+xml`, bukan `text/html`):
   ```bash
   curl -s -o /dev/null -w "%{http_code} %{content_type}\n" https://edupavilion.com/assets/default/css/espay-checkout.css
   curl -s -o /dev/null -w "%{http_code} %{content_type}\n" https://edupavilion.com/assets/default/img/payment/espay.svg
   ```

### 1.2 `.env`
- `APP_URL=https://edupavilion.com` (dipakai untuk URL callback & link).
- `APP_ENV=production`, `APP_DEBUG=false`.
- Variabel Espay: lihat [env.espay.example](env.espay.example). Disarankan mengisi kredensial lewat **Admin Panel** (langkah 4), `.env` cukup `ESPAY_IS_PRODUCTION=true`.

### 1.3 Permission folder (penyebab error 500 di staging)
Apache/PHP berjalan sebagai user `edupavilion`, jadi folder log & cache harus milik user itu:
```bash
chown -R edupavilion:edupavilion storage bootstrap/cache
chmod -R ug+rwX storage bootstrap/cache
```
Jangan menjalankan `php artisan` sebagai **root**: file log harian yang dibuat root tidak bisa ditulis aplikasi,
dan **semua callback Espay akan error 500** (pernah terjadi di staging).

### 1.4 Cron (wajib)
Antrean deposit, cek status payout dan cek pembayaran VA berjalan dari scheduler Laravel.
Di **Webmin > System > Scheduled Cron Jobs > Create a new scheduled cron job**:

| Field | Isi |
|---|---|
| Execute cron job as | `edupavilion` |
| Command | `cd /home/edupavilion/public_html/lms && php artisan schedule:run >> /dev/null 2>&1` |
| When to execute | **Times and dates selected below**, lalu Minutes/Hours/Days/Months/Weekdays semuanya **All** (setiap menit) |

Cek: `php artisan schedule:list` harus memuat `espay:va-sync` (5 menit) dan `espay:payout-sync` (10 menit).
Bila PHP default bukan 8.1+, ganti `php` dengan path lengkap (mis. `/usr/bin/php8.2`).

### 1.5 Apache / .htaccess
- Pastikan request ke `/payments/espay/...` diteruskan ke Laravel (`public/.htaccess` bawaan Laravel) dan **tidak** ada
  `ErrorDocument 404` yang mengganti respons JSON 404 dari callback (mis. `4042412`) menjadi halaman HTML.
- Header `X-SIGNATURE`, `X-TIMESTAMP`, `X-PARTNER-ID`, `X-EXTERNAL-ID`, `CHANNEL-ID` harus sampai ke PHP (bawaan Apache sudah meneruskan).
- Bila memakai ModSecurity, buat pengecualian untuk path `/payments/espay/` agar callback Espay tidak terblokir.

### 1.6 Firewall & SSL
- Port 443 harus terbuka untuk IP Espay production (konfirmasi IP-nya ke Espay). Pastikan fail2ban / firewall (Webmin > Networking) tidak memblokir.
- Sertifikat SSL `edupavilion.com` harus valid (Espay tidak memanggil URL dengan sertifikat tidak valid).

### 1.7 Cek koneksi server ke Espay
```bash
# IP publik IPv4 server (aplikasi memaksa koneksi IPv4 ke Espay) - harus 103.245.39.23
curl -4 -s ifconfig.me ; echo
curl -4 -s -o /dev/null -w "api-merchant: %{http_code}\n" https://api-merchant.espay.id
curl -4 -s -o /dev/null -w "api: %{http_code}\n" https://api.espay.id
```
Bila IP keluar (outbound) berbeda dari `103.245.39.23`, kirim IP hasil perintah di atas ke Espay untuk whitelist.

### 1.8 Pastikan URL callback bisa diakses dari internet
Request dengan signature salah harus dijawab **JSON 401** (bukan HTML, bukan 404/500):
```bash
D=https://edupavilion.com
curl -s -X POST $D/payments/espay/v1.0/transfer-va/payment -H "Content-Type: application/json" -H "X-TIMESTAMP: 2026-01-01T00:00:00+07:00" -H "X-SIGNATURE: tes" -H "X-PARTNER-ID: tes" -H "X-EXTERNAL-ID: 123" -H "CHANNEL-ID: ESPAY" -d '{}' -w "\nHTTP %{http_code}\n"
curl -s -X POST $D/payments/espay/v1.0/transfer/confirmation -H "Content-Type: application/json" -H "X-TIMESTAMP: 2026-01-01T00:00:00+07:00" -H "X-SIGNATURE: tes" -H "X-PARTNER-ID: tes" -H "X-EXTERNAL-ID: 123" -H "CHANNEL-ID: ESPAY" -d '{}' -w "\nHTTP %{http_code}\n"
```
Status 10-10-2026: keempat endpoint (`transfer-va/inquiry`, `transfer-va/payment`, `transfer/confirmation`, `transfer/notification`)
sudah menjawab JSON 401 `Unauthorized Signature`, artinya siap didaftarkan ke Espay.
Setiap request masuk tercatat di `storage/logs/espay-YYYY-MM-DD.log` sebagai `[SNAP IN]`.

---

## 2. Buat pasangan key production & kumpulkan data

1. Login Admin > **Pengaturan > Keuangan > Payment Gateways > Espay > Edit**.
2. Di bagian **Production**, centang **Generate pasangan kunci baru saat disimpan (RSA 2048)**, lalu **Simpan**. Private key tersimpan terenkripsi di database.
   (Alternatif CLI: `php artisan espay:keys production` -> `storage/app/espay/production/`.)
3. Salin **Public Key Merchant (production)** yang muncul di halaman itu.
4. Data yang dikirim ke Espay:

| Data | Nilai |
|---|---|
| IP publik server production | `103.245.39.23` |
| Public key merchant production | hasil langkah 2.3 |
| URL Inquiry | `https://edupavilion.com/payments/espay/v1.0/transfer-va/inquiry` |
| URL Payment Notification | `https://edupavilion.com/payments/espay/v1.0/transfer-va/payment` |
| URL Transfer Confirmation | `https://edupavilion.com/payments/espay/v1.0/transfer/confirmation` |
| URL Transfer Notification | `https://edupavilion.com/payments/espay/v1.0/transfer/notification` |
| Email Customer Service | `info@edupavilion.com` |

## 3. Follow-up ke Espay

Kirim email memakai template di **[FOLLOWUP-ESPAY.md](FOLLOWUP-ESPAY.md)**. Isinya: data yang kita kirim (langkah 2) dan data yang kita minta (kredensial production, public key Espay, IP callback, host API, sumber dana disbursement, pengaturan fee, aktivasi service).

## 4. Isi kredensial production di Admin Panel

Admin > Pengaturan > Keuangan > Payment Gateways > Espay > Edit, bagian **Production**:

| Field | Isi dari |
|---|---|
| Merchant Code | commcode production dari Espay |
| API Key | API key production |
| Signature Key | signature key (non-SNAP) production |
| Password Notifikasi | password notifikasi production |
| Public Key Espay | public key production dari Espay (format `-----BEGIN PUBLIC KEY-----`) |
| Partner ID Disbursement | kosongkan bila sama dengan merchant code |
| Rekening Sumber Dana | sourceAccountNo production (model deposit biasanya `PTPLUS`) |
| Kode Bank Sumber | sourceBankCode production dari Espay |

Lalu ubah **Mode aktif** ke **Production**, simpan, dan pastikan channel Espay aktif.
Untuk rollback cukup ubah Mode kembali ke Sandbox.

## 5. Cek koneksi (tanpa uang bergerak)

1. Halaman checkout (atau Panel > Keuangan > Top up) menampilkan daftar bank VA dan QRIS -> **Merchant Info** berhasil.
2. Admin > Payout > Permintaan menampilkan **Saldo deposit Espay** -> **Balance Inquiry** berhasil.
   Bila muncul "Tidak dapat dicek": cek IP whitelist, public key, dan log `espay-*.log`.

## 6. Uji transaksi asli (nominal kecil)

| Uji | Langkah | Hasil yang diharapkan |
|---|---|---|
| VA | Beli kursus Rp10.000, bayar VA dari m-banking | Order lunas otomatis, bukti bayar tampil, log `[SNAP IN] .../transfer-va/payment` 200 |
| QRIS | Beli kursus Rp10.000, scan QR dari e-wallet | Order lunas otomatis |
| Withdraw | Top up deposit Espay dulu, lalu guru menarik Rp10.000 | Status Diproses -> Selesai dalam +/- 1 menit, dana masuk rekening |
| Deposit kurang (opsional) | Tarik nominal > saldo deposit | Payout "Menunggu deposit Espay", admin dapat notifikasi |

## 7. Setelah go-live

- Pantau `storage/logs/espay-YYYY-MM-DD.log` pada hari-hari pertama.
- Jaga saldo deposit Espay (lihat kartu di Admin > Payout). Biaya BI-FAST ikut memotong deposit.
- Perintah `espay:simulate*` dan `espay:uat-*` otomatis menolak berjalan di mode production.

## 8. Troubleshooting (kasus yang pernah terjadi di sandbox)

| Gejala / kode | Penyebab | Solusi |
|---|---|---|
| `4014701 IP Address rejected` | IP server belum di-whitelist | Kirim IP server ke Espay |
| `5002601 Cannot map server service` | Public key merchant / service belum didaftarkan | Kirim public key, minta aktivasi service |
| `4051801` / `4051701 undefined transaction fee` | Fee disbursement belum diatur, atau sourceBankCode salah | Minta Espay atur fee; cek kode bank sumber |
| `4011801` / `4011701 Invalid Transfer Confirmation` | Espay tidak bisa memanggil URL Transfer Confirmation | Cek URL terdaftar, firewall, log `[SNAP IN]` |
| `4000000 Bad Request` saat membuat QRIS | Callback Inquiry kita gagal / tidak terjangkau | Cek URL Inquiry & log |
| Notifikasi VA tidak masuk, transaksi "Suspect" di Espay | Request Espay tidak sampai ke server | `tcpdump -ni any 'tcp port 443 and host <IP-Espay>'`, cek firewall; sementara itu `espay:va-sync` mengambil status |
| Respons callback berupa HTML 404 | `ErrorDocument 404` Apache / `error_page 404` nginx | Langkah 1.5 |
| Semua request 500 (termasuk callback) | File log tidak bisa ditulis web server | Langkah 1.3 |
| Inquiry Status disbursement HTTP 504 | Gangguan di sisi Espay | Laporkan ke Espay dengan log request |
| Popup checkout berantakan (logo bank sangat besar) | `public/assets/default/css/espay-checkout.css` belum ter-upload | Langkah 1.1 no. 5 |
| Espay tidak muncul di Payment Gateways | Baris channel belum ada di tabel `payment_channels` | Migrasi `2026_10_10_000001_add_espay_payment_channel.php` |
