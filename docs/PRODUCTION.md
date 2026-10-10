# Tutorial Pindah ke Production (Server & IP Baru)

Panduan ini untuk memindahkan integrasi Espay dari **staging (sandbox)** ke **server production** yang domain dan IP-nya berbeda.
Ikuti urutannya: server disiapkan dulu (supaya IP dan URL callback sudah pasti dan bisa diakses), baru follow-up ke Espay.

## 0. Apa saja yang berubah?

| Item | Staging (sandbox) | Production | Siapa yang mengubah |
|---|---|---|---|
| Domain / URL callback | `https://work.ayoo.web.id/...` | `https://<domain-production>/...` | Kita kirim ke Espay, Espay mendaftarkan |
| IP server (whitelist di Espay) | `72.61.210.19` | IP publik server production | Kita kirim, Espay whitelist |
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

### 1.1 Deploy kode
1. Pastikan aplikasi production sudah berisi semua file di repo ini (salin dengan struktur folder yang sama).
2. Dependensi: `barryvdh/laravel-dompdf` (sudah ada di `composer.json` Rocket LMS) lalu `composer install --no-dev -o`.
3. Jalankan migrasi (membuat tabel `espay_virtual_accounts`, `espay_va_payments`, kolom provider di `payouts`, dan mendaftarkan channel **Espay** di Admin > Settings > Financial > Payment Gateways dalam status nonaktif):
   ```bash
   php artisan migrate --force
   ```
4. Bersihkan cache:
   ```bash
   php artisan optimize:clear
   ```

### 1.2 `.env`
- `APP_URL=https://<domain-production>` (dipakai untuk URL callback & link).
- `APP_ENV=production`, `APP_DEBUG=false`.
- Variabel Espay: lihat [env.espay.example](env.espay.example). Disarankan mengisi kredensial lewat **Admin Panel** (langkah 4), `.env` cukup `ESPAY_IS_PRODUCTION=true`.

### 1.3 Permission folder (penyebab error 500 di staging)
Web server (user `www` di aaPanel) harus bisa menulis log & cache:
```bash
chown -R www:www storage bootstrap/cache
chmod -R ug+rwX storage bootstrap/cache
```
Jalankan perintah artisan sebagai `www` (atau `umask 002` bila memakai user lain yang satu grup), supaya file log harian
tidak dimiliki user lain. Bila log tidak bisa ditulis, **semua callback Espay akan error 500**.

### 1.4 Cron (wajib)
Antrean deposit, cek status payout dan cek pembayaran VA berjalan dari scheduler Laravel:
```bash
crontab -u www -e
# tambahkan:
* * * * * cd /www/wwwroot/<domain-production> && php artisan schedule:run >> /dev/null 2>&1
```
(Di aaPanel: Cron > Add Task > Shell Script, jalankan setiap 1 menit sebagai `www`.)

### 1.5 Nginx (aaPanel)
aaPanel menambahkan `error_page 404 /404.html;` yang membuat respons JSON 404 dari callback (mis. `4042412`) berubah menjadi halaman HTML.
Nonaktifkan di vhost production:
```bash
grep -n "error_page 404\|fastcgi_intercept_errors" /www/server/panel/vhost/nginx/<domain-production>.conf
# beri tanda # pada baris "error_page 404 /404.html;" lalu:
nginx -t && nginx -s reload
```

### 1.6 Firewall, Cloudflare & SSL
- Port 443 harus terbuka untuk IP Espay production (konfirmasi IP-nya ke Espay). Pastikan fail2ban / firewall aaPanel / ipset tidak memblokir.
- Bila domain lewat Cloudflare: buat **WAF Custom Rule "Skip"** untuk path yang diawali `/payments/espay/` (matikan Bot Fight Mode / challenge untuk path itu).
- Sertifikat SSL harus valid (Espay tidak memanggil URL dengan sertifikat tidak valid).

### 1.7 Catat data server
```bash
# IP publik IPv4 server (aplikasi memaksa koneksi IPv4 ke Espay)
curl -4 -s ifconfig.me ; echo
# Server bisa menjangkau Espay?
curl -4 -s -o /dev/null -w "api-merchant: %{http_code}\n" https://api-merchant.espay.id
curl -4 -s -o /dev/null -w "api: %{http_code}\n" https://api.espay.id
```

### 1.8 Pastikan URL callback bisa diakses dari internet
Request dengan signature salah harus dijawab **JSON 401** (bukan HTML, bukan 404/500):
```bash
D=https://<domain-production>
curl -s -X POST $D/payments/espay/v1.0/transfer-va/payment -H "Content-Type: application/json" -H "X-TIMESTAMP: 2026-01-01T00:00:00+07:00" -H "X-SIGNATURE: tes" -H "X-PARTNER-ID: tes" -H "X-EXTERNAL-ID: 123" -H "CHANNEL-ID: ESPAY" -d '{}' -w "\nHTTP %{http_code}\n"
curl -s -X POST $D/payments/espay/v1.0/transfer/confirmation -H "Content-Type: application/json" -H "X-TIMESTAMP: 2026-01-01T00:00:00+07:00" -H "X-SIGNATURE: tes" -H "X-PARTNER-ID: tes" -H "X-EXTERNAL-ID: 123" -H "CHANNEL-ID: ESPAY" -d '{}' -w "\nHTTP %{http_code}\n"
```
Setiap request masuk tercatat di `storage/logs/espay-YYYY-MM-DD.log` sebagai `[SNAP IN]`.

---

## 2. Buat pasangan key production & kumpulkan data

1. Login Admin > **Settings > Financial > Payment Channels > Espay > Edit**.
2. Di bagian **Production**, centang **Generate pasangan kunci baru saat disimpan (RSA 2048)**, lalu **Simpan**. Private key tersimpan terenkripsi di database.
   (Alternatif CLI: `php artisan espay:keys production` -> `storage/app/espay/production/`.)
3. Salin **Public key merchant (production)** yang muncul di halaman itu.
4. Siapkan daftar berikut untuk dikirim ke Espay:

| Data | Nilai |
|---|---|
| IP publik server production | hasil langkah 1.7 |
| Public key merchant production | hasil langkah 2.3 |
| URL Inquiry | `https://<domain-production>/payments/espay/v1.0/transfer-va/inquiry` |
| URL Payment Notification | `https://<domain-production>/payments/espay/v1.0/transfer-va/payment` |
| URL Transfer Confirmation | `https://<domain-production>/payments/espay/v1.0/transfer/confirmation` |
| URL Transfer Notification | `https://<domain-production>/payments/espay/v1.0/transfer/notification` |
| Email Customer Service | email CS Edu Pavilion |

## 3. Follow-up ke Espay

Kirim email memakai template di **[FOLLOWUP-ESPAY.md](FOLLOWUP-ESPAY.md)**. Isinya: data yang kita kirim (langkah 2) dan data yang kita minta (kredensial production, public key Espay, IP callback, host API, sumber dana disbursement, pengaturan fee, aktivasi service).

## 4. Isi kredensial production di Admin Panel

Admin > Payment Channels > Espay > Edit, bagian **Production**:

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
| Respons callback berupa HTML 404 | `error_page 404` di nginx aaPanel | Langkah 1.5 |
| Semua request 500 (termasuk callback) | File log tidak bisa ditulis web server | Langkah 1.3 |
| Inquiry Status disbursement HTTP 504 | Gangguan di sisi Espay | Laporkan ke Espay dengan log request |
