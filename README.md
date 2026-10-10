# Edu Pavilion - Integrasi Espay (Payment Gateway & Disbursement)

Repo ini **hanya berisi file integrasi Espay** dari aplikasi Edu Pavilion (Rocket LMS, Laravel 9, PHP 8.1+).
Struktur folder sama persis dengan aplikasi utama, jadi file di sini cukup disalin (ditimpa) ke root aplikasi.

- Payment Gateway: **VA Static Open** (Send Invoice) dan **QRIS (QR-MPM)**, checkout popup di dalam dashboard,
  bukti pembayaran / invoice PDF, riwayat pembayaran di Ringkasan Keuangan.
- Disbursement (withdraw guru / organisasi): **BI-FAST** dan **Intrabank Transfer**, cek saldo deposit Espay
  sebelum setiap pencairan, callback Transfer Confirmation & Notification.

> Panduan pindah ke production (server & IP berbeda) dan follow-up ke Espay: **[docs/PRODUCTION.md](docs/PRODUCTION.md)**
> Template email ke Espay: **[docs/FOLLOWUP-ESPAY.md](docs/FOLLOWUP-ESPAY.md)**

## Service Espay yang dipakai

| Payment Gateway | Disbursement |
|---|---|
| Inquiry (callback, dipakai QRIS) | Inquiry Account Internal |
| Payment (callback notifikasi VA & QRIS) | Inquiry Account External |
| QRIS (QR-MPM) | BI Fast |
| Virtual Account (Send Invoice, non-SNAP) | Intrabank Transfer (penerima di bank sumber dana) |
| Inquiry Status | Transfer Confirmation (callback) |
| Delete-VA | Balance Inquiry |
| Merchant Info & Check Payment Status (non-SNAP) | Inquiry Status |
| | Transfer Notification (callback) |

Tidak dipakai: Payment Host to Host, Direct Debit, Bank Statement, RTGS, SKN.

## Endpoint di aplikasi (didaftarkan ke Espay)

| Fungsi | Method & URL |
|---|---|
| Inquiry (QRIS) | `POST {APP_URL}/payments/espay/v1.0/transfer-va/inquiry` |
| Payment Notification (VA & QRIS, SNAP) | `POST {APP_URL}/payments/espay/v1.0/transfer-va/payment` |
| Payment Notification non-SNAP (opsional) | `POST {APP_URL}/payments/espay/notification` |
| Transfer Confirmation | `POST {APP_URL}/payments/espay/v1.0/transfer/confirmation` |
| Transfer Notification | `POST {APP_URL}/payments/espay/v1.0/transfer/notification` |

Semua callback divalidasi signature (SHA256withRSA memakai public key Espay), tercatat di `storage/logs/espay-YYYY-MM-DD.log`
dan dikecualikan dari CSRF (`app/Http/Middleware/VerifyCsrfToken.php`).

## Peta file

| Bagian | File |
|---|---|
| Klien API & signature | `app/Services/Espay/EspayClient.php`, `SnapRequestValidator.php`, `EspayException.php` |
| Kredensial dari Admin Panel | `app/Services/Espay/EspaySettings.php`, `app/Models/PaymentChannel.php`, `app/Http/Controllers/Admin/PaymentChannelController.php`, view `admin/settings/financial/payment_channel/*` |
| Payment Gateway | `EspayPaymentService.php`, `EspayStaticVaService.php`, `EspayPaymentMethods.php`, `app/PaymentChannels/Drivers/Espay/Channel.php`, `EspayController.php`, `EspayNotificationController.php`, `Web/PaymentController.php` |
| Checkout popup & bukti bayar | `resources/views/web/default/cart/channels/espay*.blade.php`, `cart/payment.blade.php`, `panel/financial/payment_receipt*.blade.php`, `includes/gateway_payments.blade.php`, `public/assets/default/{css,js}/...espay-checkout.*`, `public/assets/default/img/espay/` |
| Disbursement | `EspayDisbursementService.php`, `EspayDisbursementController.php`, `Panel/PayoutController.php`, `Admin/PayoutController.php`, `admin/financial/payout/lists.blade.php`, `config/espay_banks.php` |
| Konfigurasi | `config/espay.php`, `config/logging.php` (channel `espay`), `docs/env.espay.example` |
| Database | `database/migrations/2026_10_05_000001_*`, `2026_10_05_000002_*`, `2026_10_06_000001_*`, `2026_10_10_000001_add_espay_payment_channel.php` (mendaftarkan channel Espay di Payment Gateways) |
| Job terjadwal | `app/Console/Kernel.php`: `espay:va-sync` (5 menit), `espay:payout-sync` (10 menit) |
| Route | `routes/web.php` (callback & checkout), `routes/panel.php` (bukti bayar & invoice) |

## Alur singkat

**Pembayaran VA Static Open:** setiap user mendapat 1 nomor VA tetap per bank (`EDUU{userId}B{kodeBank}`, dibuat via sendinvoice).
Notifikasi Payment dari Espay melunasi order pending yang memakai VA tersebut dengan nominal sama; selain itu masuk sebagai top up saldo.
Bila notifikasi tidak masuk, `espay:va-sync` mengecek via Check Payment Status (non-SNAP).

**QRIS:** QR-MPM (productCode `QRIS`) -> Espay memanggil Inquiry kita -> QR ditampilkan di popup -> notifikasi Payment melunasi order.

**Withdraw:** Inquiry rekening -> **Balance Inquiry** (deposit harus >= nominal + `ESPAY_DISB_DEPOSIT_FEE_BUFFER`) ->
potong saldo -> transfer BI-FAST / Intrabank -> Espay memanggil Transfer Confirmation -> Transfer Notification -> payout selesai
(gagal = saldo dikembalikan otomatis). Bila deposit kurang, payout ditahan (`waiting`), admin diberi notifikasi,
dan diproses ulang otomatis oleh `espay:payout-sync` setelah deposit diisi.

## Perintah artisan

| Perintah | Kegunaan |
|---|---|
| `php artisan espay:keys {sandbox\|production}` | Membuat pasangan RSA merchant (alternatif: tombol generate di Admin Panel) |
| `php artisan espay:va-sync` | Cek pembayaran VA yang notifikasinya belum masuk |
| `php artisan espay:payout-sync` | Proses antrean deposit + cek status pencairan |
| `espay:simulate`, `espay:simulate-notification`, `espay:simulate-transfer`, `espay:uat-*` | Alat uji **sandbox saja** (menolak berjalan di mode production) |

## Status pengujian (sandbox, 05-07 Oktober 2026)

- UAT VA Static (Send Invoice & Payment Notification): PASS.
- Functional Test SNAP Transfer VA & QR MPM: PASS untuk semua skenario yang berlaku.
- Functional Test Disbursement (Balance, Inquiry Account, Intrabank, BI-FAST, callback, Inquiry Status): PASS.
