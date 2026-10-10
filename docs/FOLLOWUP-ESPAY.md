# Follow-up ke Espay untuk Production

Kirim setelah server production siap (lihat [PRODUCTION.md](PRODUCTION.md) langkah 1-2), supaya IP dan URL yang dikirim sudah final dan bisa langsung diuji Espay.

- Kepada: tim integrasi / onboarding Espay (`sgolive@espay.id`, cc PIC Espay)
- Lampiran: file public key merchant production (`.pem` atau teks), UAT Script (ditandatangani), Functional Test PG & Disbursement, Panduan SOP dan Matriks Penjelasan (ditandatangani)

## Checklist sebelum kirim

- [x] Domain production aktif dengan SSL valid (https://edupavilion.com)
- [x] URL callback menjawab JSON 401 untuk signature salah (dicek 10-10-2026)
- [x] IP publik server production: 103.245.39.23
- [ ] Public key merchant production sudah dibuat (pasangan baru, bukan key sandbox)
- [ ] Cron `schedule:run` aktif
- [x] Email Customer Service: info@edupavilion.com

## Template email

```
Subjek: Edu Pavilion - Permohonan Go Live Production Espay (Payment Gateway & Disbursement)

Selamat siang Bapak/Ibu tim Espay,

Pengujian sandbox Edu Pavilion (commcode sandbox SGWMILENIALBEKARYASO) sudah selesai.
Kami mengajukan aktivasi production dengan server baru. Berikut datanya:

A. DATA DARI EDU PAVILION
1. Domain production      : https://edupavilion.com
2. IP server production   : 103.245.39.23   (mohon di-whitelist untuk seluruh API)
3. Public key merchant    : terlampir (RSA 2048, SHA256withRSA)
4. URL callback Payment Gateway
   - Inquiry              : https://edupavilion.com/payments/espay/v1.0/transfer-va/inquiry
   - Payment Notification : https://edupavilion.com/payments/espay/v1.0/transfer-va/payment
5. URL callback Disbursement
   - Transfer Confirmation: https://edupavilion.com/payments/espay/v1.0/transfer/confirmation
   - Transfer Notification: https://edupavilion.com/payments/espay/v1.0/transfer/notification
6. Email Customer Service : info@edupavilion.com (WhatsApp +62 852-8145-5797)

B. SERVICE YANG DIGUNAKAN
Payment Gateway : Inquiry, Payment, QRIS (QR-MPM), Virtual Account (Send Invoice / VA Static Open),
                  Inquiry Status, Delete-VA, serta API non-SNAP Merchant Info dan Check Payment Status.
Disbursement    : Inquiry Account Internal, Inquiry Account External, BI Fast, Intrabank Transfer,
                  Transfer Confirmation, Balance Inquiry, Inquiry Status, Transfer Notification.

C. DATA YANG KAMI MOHON DARI ESPAY
1. Kredensial production: Merchant Code (commcode), API Key, Signature Key, Password Notifikasi.
2. Public key Espay production (untuk validasi callback).
3. X-PARTNER-ID disbursement (bila berbeda dari merchant code).
4. sourceAccountNo dan sourceBankCode production untuk disbursement, serta cara/rekening top up deposit.
5. Konfirmasi pengaturan fee transaksi disbursement (BI Fast dan Intrabank) di production.
6. IP Espay production yang memanggil callback kami (untuk whitelist firewall).
7. Konfirmasi host API production:
   - SNAP Payment Gateway & Disbursement : https://api-merchant.espay.id ?
   - Merchant Info (non-SNAP)            : https://api.espay.id/rest/merchant/merchantinfo ?
   - Send Invoice (non-SNAP)             : https://api-merchant.espay.id/rest/merchantpg/sendinvoice ?
   - Check Payment Status (non-SNAP)     : https://api.espay.id/rest/merchant/status ?
   - Disbursement Inquiry Status         : dokumentasi mencantumkan api-merchant.espay.id dan api.espay.id, mohon konfirmasi yang benar.
8. Daftar bank VA dan QRIS yang aktif di production.
9. Jadwal uji coba transaksi production (nominal kecil) bersama tim Espay.

Terima kasih.

Salam,
[Nama penanggung jawab]
CV Generasi Milenial Bekarya (Edu Pavilion)
```

## Setelah Espay membalas

1. Isi kredensial di Admin Panel (PRODUCTION.md langkah 4), simpan public key Espay.
2. Cek koneksi (langkah 5): daftar bank muncul dan saldo deposit terbaca.
3. Top up deposit disbursement.
4. Uji transaksi kecil (langkah 6) dan kabari Espay hasilnya beserta waktu dan nomor referensi.
