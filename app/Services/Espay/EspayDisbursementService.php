<?php

namespace App\Services\Espay;

use App\Models\Accounting;
use App\Models\Payout;
use App\Models\UserSelectedBank;
use App\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Pencairan dana (withdraw) ke rekening guru/user via Espay Disbursement (SNAP Transfer).
 * Spesifikasi: docs.espay.id > Disbursement (salinan HTML di panduan-espey/disbursement).
 *
 * Alur:
 *  1. Inquiry Account Internal (15) / External (16) -> rekening valid & nama pemilik
 *  1b. Balance Inquiry (11): saldo deposit Espay harus cukup. Bila kurang / gagal dicek, payout ditahan
 *      (`waiting`, saldo user belum dipotong), admin diberi tahu, lalu diproses ulang otomatis oleh espay:payout-sync
 *  2. Potong saldo + payout `processing` (data untuk Transfer Confirmation disimpan di sini)
 *  3. Transfer Intrabank (17) / Interbank BI-FAST (18)
 *     -> selama proses ini Espay memanggil Transfer Confirmation (96) ke kita
 *  4. Transfer Notification (97) -> `done`, atau `reject` + saldo dikembalikan.
 *     Fallback: Inquiry Status (36) via espay:payout-sync.
 */
class EspayDisbursementService
{
    public const PROVIDER = 'espay';

    public const SERVICE_INTRABANK = '17';
    public const SERVICE_INTERBANK = '18';

    // latestTransactionStatus: 00 Success, 01 Init, 03 Pending, 05 Canceled, 06 Failed
    public const STATUS_SUCCESS = ['00'];
    public const STATUS_FAILED = ['05', '06'];

    // provider_status payout `waiting` yang ditahan karena saldo deposit Espay
    public const HOLD_INSUFFICIENT = 'insufficient_deposit';
    public const HOLD_CHECK_FAILED = 'deposit_check_failed';
    public const HOLD_STATUSES = [self::HOLD_INSUFFICIENT, self::HOLD_CHECK_FAILED];

    /**
     * Data rekening tujuan dari spesifikasi bank yang diisi user (Nama Bank / Nomor Rekening / Nama Pemilik).
     *
     * @return array{bank_code: string, bank_name: string, swift: string, account_no: string, holder: string}
     */
    public static function beneficiary(UserSelectedBank $selectedBank): array
    {
        $bankName = '';
        $accountNo = '';
        $holder = '';

        $specs = DB::table('user_selected_bank_specifications as s')
            ->join('user_bank_specification_translations as t', 't.user_bank_specification_id', '=', 's.user_bank_specification_id')
            ->where('s.user_selected_bank_id', $selectedBank->id)
            ->where('t.locale', 'id')
            ->get(['t.name', 's.value']);

        foreach ($specs as $spec) {
            $name = mb_strtolower((string) $spec->name);

            if (str_contains($name, 'nama bank')) {
                $bankName = trim((string) $spec->value);
            } elseif (str_contains($name, 'nomor rekening') or str_contains($name, 'no rekening') or str_contains($name, 'no. rekening')) {
                $accountNo = preg_replace('/\D/', '', (string) $spec->value);
            } elseif (str_contains($name, 'pemilik') or str_contains($name, 'atas nama')) {
                $holder = trim((string) $spec->value);
            }
        }

        if ($bankName === '') {
            $bankName = (string) ($selectedBank->bank->title ?? '');
        }

        $bankCode = self::bankCode($bankName);
        $bank = config("espay_banks.{$bankCode}", []);

        return [
            'bank_code' => $bankCode,
            'bank_name' => $bank['name'] ?? $bankName,
            'swift' => $bank['swift'] ?? '',
            'account_no' => $accountNo,
            'holder' => $holder,
        ];
    }

    /**
     * Kode bank BI dari nama bank ("BCA", "Bank Mandiri", atau langsung "014").
     */
    public static function bankCode(string $bankName): string
    {
        $value = mb_strtolower(trim($bankName));

        if (preg_match('/^\d{3}$/', $value)) {
            return $value;
        }

        $aliases = config('espay.bank_codes', []);
        $normalized = trim(preg_replace('/\s+/', ' ', preg_replace('/[^a-z0-9 ]/', ' ', $value)));

        return $aliases[$normalized] ?? $aliases[preg_replace('/^bank /', '', $normalized)] ?? '';
    }

    /**
     * Nomor referensi pencairan (partnerReferenceNo, maks 32): alfanumerik, unik per percobaan.
     */
    public static function makeReference(Payout $payout): string
    {
        return mb_substr('PO' . $payout->id . 'T' . date('ymdHis') . random_int(10, 99), 0, 32);
    }

    public static function findByReference(?string $reference): ?Payout
    {
        if (empty($reference) or !preg_match('/^[A-Za-z0-9]{1,64}$/', $reference)) {
            return null;
        }

        return Payout::query()
            ->where('provider', self::PROVIDER)
            ->where('provider_reference', $reference)
            ->first();
    }

    /**
     * Ajukan pencairan dana untuk $owner (pemilik saldo) ke rekening $selectedBank.
     * $existing: payout `waiting` yang sudah ada (diproses admin); kosong = buat payout baru.
     *
     * @throws RuntimeException pesan siap ditampilkan ke user; saldo tidak dipotong bila gagal sebelum transfer
     */
    public function disburse(User $owner, UserSelectedBank $selectedBank, float $amount, string $remark = 'Payout Edu Pavilion', ?Payout $existing = null): Payout
    {
        $amount = round($amount, 2);
        $minimum = (int) config('espay.disbursement.minimum_amount', 10000);
        $maximum = (int) config('espay.disbursement.maximum_amount', 250000000);

        if ($amount < $minimum) {
            throw new RuntimeException('Nominal minimal pencairan via Espay adalah ' . handlePrice($minimum) . '.');
        }

        if ($amount > $maximum) {
            throw new RuntimeException('Nominal maksimal sekali pencairan adalah ' . handlePrice($maximum) . '.');
        }

        $client = new EspayClient();
        $target = self::beneficiary($selectedBank);

        if ($target['account_no'] === '' or $target['bank_code'] === '') {
            throw new RuntimeException('Data rekening belum lengkap atau nama bank tidak dikenali. Periksa kembali Nama Bank dan Nomor Rekening di pengaturan identitas.');
        }

        $intrabank = $target['bank_code'] === $client->disbursementSourceBankCode();

        if (!$intrabank and $target['swift'] === '') {
            throw new RuntimeException('Bank tujuan (' . $target['bank_name'] . ') belum didukung untuk pencairan otomatis.');
        }

        $amountValue = EspayPaymentService::formatAmount($amount);

        // 1. cek rekening tujuan (tidak ada uang yang bergerak)
        $inquiryReference = 'AI' . $owner->id . 'T' . date('ymdHis') . random_int(10, 99);
        $inquiry = $intrabank
            ? $client->accountInquiryInternal($inquiryReference, $target['account_no'])
            : $client->accountInquiryExternal($inquiryReference, $target['bank_code'], $target['account_no'], $amountValue);

        if (!self::isSuccessCode($inquiry['responseCode'] ?? '')) {
            throw new RuntimeException('Rekening tujuan tidak valid: ' . ($inquiry['responseMessage'] ?? 'tidak ditemukan') . '.');
        }

        $accountName = EspayPaymentService::ascii((string) ($inquiry['beneficiaryAccountName'] ?? $target['holder']), 100);
        $bankName = trim((string) ($inquiry['beneficiaryBankName'] ?? '')) ?: $target['bank_name'];

        // 1b. saldo deposit Espay harus cukup untuk nominal + perkiraan biaya transfer
        $deposit = $this->depositBalance();
        $needed = $amount + (float) config('espay.disbursement.deposit_fee_buffer', 5000);

        if ($deposit === null or $deposit < $needed) {
            return $this->hold($owner, $selectedBank, $amount, $existing, $deposit === null ? self::HOLD_CHECK_FAILED : self::HOLD_INSUFFICIENT, $deposit, $needed);
        }

        $transactionDate = EspayClient::timestamp();

        // 2. potong saldo & catat payout processing (+ data untuk menjawab Transfer Confirmation)
        $payout = DB::transaction(function () use ($owner, $selectedBank, $amount, $amountValue, $target, $accountName, $bankName, $inquiry, $intrabank, $existing, $transactionDate) {
            if (!empty($existing)) {
                $payout = Payout::query()->lockForUpdate()->findOrFail($existing->id);

                if ($payout->status !== Payout::$waiting) {
                    throw new RuntimeException('Permintaan pencairan ini sudah diproses.');
                }

                $payout->update(['status' => Payout::$processing, 'provider' => self::PROVIDER]);
            } else {
                $payout = Payout::create([
                    'user_id' => $owner->id,
                    'user_selected_bank_id' => $selectedBank->id,
                    'amount' => $amount,
                    'status' => Payout::$processing,
                    'is_automatic' => true,
                    'provider' => self::PROVIDER,
                    'created_at' => time(),
                ]);
            }

            $payout->update([
                'provider_reference' => self::makeReference($payout),
                'provider_status' => 'requested',
                'provider_data' => json_encode([
                    'service' => $intrabank ? self::SERVICE_INTRABANK : self::SERVICE_INTERBANK,
                    'amount' => $amountValue,
                    'transaction_date' => $transactionDate,
                    'beneficiary' => array_merge($target, ['bank_name' => $bankName, 'account_name' => $accountName]),
                    'account_inquiry' => $inquiry,
                ], JSON_UNESCAPED_SLASHES),
            ]);

            $this->deductBalance($owner, $payout);

            return $payout;
        });

        // 3. transfer
        $email = (string) ($selectedBank->user->email ?? $owner->email ?? '');
        $body = $intrabank
            ? $this->intrabankBody($client, $payout, $amountValue, $target, $email, $remark, $transactionDate, $inquiry)
            : $this->interbankBody($client, $payout, $amountValue, $target, $accountName, $bankName, $email, $remark, $transactionDate, $inquiry);

        try {
            $response = $client->transfer($body, $intrabank);
        } catch (\Throwable $e) {
            // Timeout/koneksi: status transfer belum pasti -> tetap processing, dicek via Inquiry Status
            Log::channel('espay')->warning('[Payout] transfer request error, menunggu cek status', ['payout_id' => $payout->id, 'error' => $e->getMessage()]);
            $this->appendData($payout, ['external_id' => $client->lastExternalId(), 'transfer_error' => $e->getMessage()], 'unknown');

            return $payout->refresh();
        }

        $this->appendData($payout, [
            'external_id' => $client->lastExternalId(),
            'reference_no' => $response['referenceNo'] ?? null,
            'trace_no' => $response['traceNo'] ?? null,
            'transfer_response' => $response,
        ], 'submitted');

        $code = (string) ($response['responseCode'] ?? '');

        if (!self::isSuccessCode($code)) {
            // 404xx18 Inconsistent Request pada transfer kredit = dianggap berhasil (dok. Kode Respons)
            if (preg_match('/^404\d{2}18$/', $code)) {
                return $payout->refresh();
            }

            // Ditolak jelas oleh Espay (saldo deposit kurang, rekening salah, dll.) -> kembalikan saldo
            $this->complete($payout->refresh(), false, ['reason' => $response['responseMessage'] ?? 'Transfer ditolak', 'response' => $response]);
        }

        return $payout->refresh();
    }

    /**
     * Body Interbank Transfer / BI-FAST (service 18).
     */
    protected function interbankBody(EspayClient $client, Payout $payout, string $amount, array $target, string $accountName, string $bankName, string $email, string $remark, string $transactionDate, array $inquiry): array
    {
        $remark = EspayPaymentService::ascii($remark, 40);

        return [
            'partnerReferenceNo' => $payout->provider_reference,
            'amount' => ['value' => $amount, 'currency' => 'IDR'],
            'beneficiaryAccountName' => $accountName,
            'beneficiaryAccountNo' => $target['account_no'],
            'beneficiaryBankCode' => $target['bank_code'],
            'beneficiaryBankName' => EspayPaymentService::ascii($bankName, 100),
            'beneficiaryEmail' => mb_substr($email, 0, 100),
            'customerReference' => (string) $payout->id,
            'currency' => 'IDR',
            'sourceAccountNo' => $client->disbursementSourceAccount(),
            'transactionDate' => $transactionDate,
            'feeType' => (string) config('espay.disbursement.fee_type', 'OUR'),
            'additionalInfo' => array_filter([
                'sourceAccountName' => EspayPaymentService::ascii((string) config('espay.disbursement.source_account_name', 'PTPLUS'), 40),
                'remark' => $remark,
                'sourceBankCode' => $client->disbursementSourceBankCode(),
                'chargeBearerCode' => 'DEBT',
                'beneficiaryAccountType' => (string) config('espay.disbursement.beneficiary_account_type', 'SVGS'),
                'purposeOfTransaction' => (string) config('espay.disbursement.purpose_of_transaction', '99'),
                'beneficiaryCustomerType' => (string) config('espay.disbursement.beneficiary_customer_type', '01'),
                'memo' => preg_replace('/[^A-Za-z0-9 ]/', '', $remark),
                'beneficiaryCustomerResidentStatus' => '1',
                'beneficiaryCustomerTownName' => '',
                'swiftCode' => $target['swift'],
                'transferType' => '5', // BI-FAST
                'referenceNo' => $inquiry['referenceNo'] ?? null,
            ], fn ($value) => $value !== null),
        ];
    }

    /**
     * Body Intrabank Transfer (service 17).
     */
    protected function intrabankBody(EspayClient $client, Payout $payout, string $amount, array $target, string $email, string $remark, string $transactionDate, array $inquiry): array
    {
        return [
            'partnerReferenceNo' => $payout->provider_reference,
            'amount' => ['value' => $amount, 'currency' => 'IDR'],
            'beneficiaryAccountNo' => $target['account_no'],
            'beneficiaryEmail' => mb_substr($email ?: (string) getGeneralSettings('site_email'), 0, 64),
            'currency' => 'IDR',
            'customerReference' => (string) $payout->id,
            'feeType' => (string) config('espay.disbursement.fee_type', 'OUR'),
            'remark' => EspayPaymentService::ascii($remark, 64),
            'sourceAccountNo' => $client->disbursementSourceAccount(),
            'transactionDate' => $transactionDate,
            'additionalInfo' => array_filter([
                'referenceNo' => $inquiry['referenceNo'] ?? null,
                'sourceBankCode' => $client->disbursementSourceBankCode(),
            ], fn ($value) => $value !== null),
        ];
    }

    /**
     * Transfer Confirmation (96): pastikan pencairan memang dari kita dan nominalnya cocok, lalu kembalikan
     * detail transaksi. Data penerima di-hash sha256(nilai + partnerReferenceNo) sesuai dokumentasi.
     *
     * @return array<string, mixed> isi respons 2009600 (tanpa responseCode/Message)
     * @throws EspayException
     */
    public function confirm(string $reference, ?string $amount, string $service, array $requestBody = []): array
    {
        $payout = self::findByReference($reference);

        if (empty($payout) or !in_array($payout->status, [Payout::$processing, Payout::$done], true)) {
            throw new EspayException(404, $service, '12', 'invalid partnerReferenceNo');
        }

        if ($amount !== null and $amount !== '' and abs((float) $amount - (float) $payout->amount) > 0.001) {
            throw new EspayException(404, $service, '13', 'Invalid Amount');
        }

        $data = $payout->providerData();
        $beneficiary = $data['beneficiary'] ?? [];
        $client = new EspayClient();
        $hash = fn ($value) => hash('sha256', $value . $reference);

        $this->appendData($payout, ['confirmation_at' => date('c')], $payout->status === Payout::$processing ? 'confirmed' : null);

        return [
            'partnerReferenceNo' => $reference,
            'amount' => ['value' => $data['amount'] ?? EspayPaymentService::formatAmount($payout->amount), 'currency' => 'IDR'],
            'transactionDate' => $data['transaction_date'] ?? EspayClient::timestamp(),
            'beneficiaryAccountNo' => $hash($beneficiary['account_no'] ?? ''),
            'beneficiaryBankCode' => $hash($beneficiary['bank_code'] ?? ''),
            'beneficiaryAccountName' => $hash($beneficiary['account_name'] ?? ''),
            'sourceBankCode' => $requestBody['sourceBankCode'] ?? $hash($client->disbursementSourceBankCode()),
            'sourceAccountNo' => $requestBody['sourceAccountNo'] ?? $hash($client->disbursementSourceAccount()),
        ];
    }

    /**
     * Hasil akhir transfer (idempotent). Sukses -> done; gagal -> reject + saldo dikembalikan.
     */
    public function complete(Payout $payout, bool $success, array $data = []): Payout
    {
        return DB::transaction(function () use ($payout, $success, $data) {
            $payout = Payout::query()->lockForUpdate()->findOrFail($payout->id);

            if ($payout->status !== Payout::$processing) {
                return $payout; // sudah final
            }

            $payout->update([
                'status' => $success ? Payout::$done : Payout::$reject,
                'provider_status' => $success ? 'success' : 'failed',
                'processed_at' => time(),
                'provider_data' => json_encode(array_merge($payout->providerData(), ['result' => $data]), JSON_UNESCAPED_SLASHES),
            ]);

            if ($success) {
                sendNotification('payout_proceed', [
                    '[payout.amount]' => handlePrice($payout->amount),
                    '[payout.account]' => $payout->providerData()['beneficiary']['bank_name'] ?? '',
                ], $payout->user_id);
            } else {
                $this->refundBalance($payout);
            }

            Log::channel('espay')->info('[Payout] selesai', ['payout_id' => $payout->id, 'success' => $success]);

            return $payout;
        });
    }

    /**
     * Cek status ke Espay (Inquiry Status, service 36) untuk pencairan yang belum mendapat notifikasi.
     */
    public function syncStatus(Payout $payout): Payout
    {
        if ($payout->status !== Payout::$processing or empty($payout->provider_reference)) {
            return $payout;
        }

        $data = $payout->providerData();
        $response = (new EspayClient())->transferStatus([
            'partner_reference_no' => $payout->provider_reference,
            'reference_no' => $data['reference_no'] ?? '',
            'external_id' => $data['external_id'] ?? '',
            'service' => $data['service'] ?? self::SERVICE_INTERBANK,
            'transaction_date' => $data['transaction_date'] ?? EspayClient::timestamp(now()->setTimestamp((int) $payout->created_at)),
            'amount' => $data['amount'] ?? EspayPaymentService::formatAmount($payout->amount),
            'beneficiary_account_no' => $data['beneficiary']['account_no'] ?? '',
        ]);

        $this->appendData($payout, ['last_status_check' => $response]);

        if (!self::isSuccessCode($response['responseCode'] ?? '')) {
            // 4043601 Transaction Not Found: transfer tidak pernah tercatat di Espay -> aman dikembalikan
            if (($response['responseCode'] ?? '') === '4043601') {
                return $this->complete($payout, false, ['source' => 'status_inquiry', 'reason' => 'Transaction Not Found', 'response' => $response]);
            }

            return $payout->refresh();
        }

        $status = (string) ($response['latestTransactionStatus'] ?? '');

        if (in_array($status, self::STATUS_SUCCESS, true)) {
            return $this->complete($payout, true, ['source' => 'status_inquiry', 'response' => $response]);
        }

        if (in_array($status, self::STATUS_FAILED, true)) {
            return $this->complete($payout, false, ['source' => 'status_inquiry', 'response' => $response]);
        }

        return $payout->refresh();
    }

    /**
     * Saldo deposit disbursement di Espay (Balance Inquiry, service 11). null = gagal dicek.
     */
    public function depositBalance(): ?float
    {
        try {
            $response = (new EspayClient())->balanceInquiry('BI' . date('ymdHis') . random_int(10, 99));
        } catch (\Throwable $e) {
            Log::channel('espay')->warning('[Payout] balance inquiry error', ['error' => $e->getMessage()]);

            return null;
        }

        if (!self::isSuccessCode((string) ($response['responseCode'] ?? ''))) {
            Log::channel('espay')->warning('[Payout] balance inquiry ditolak', ['response' => $response]);

            return null;
        }

        $info = $response['accountInfo'] ?? ($response['accountInfos'][0] ?? []);
        $value = $info['amount']['value'] ?? $info['availableBalance']['value'] ?? null;

        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * Proses ulang payout yang ditahan karena saldo deposit (dipanggil espay:payout-sync).
     * Berhenti di payout pertama yang masih tertahan, supaya antrean tetap urut.
     *
     * @return array<int, string> hasil per payout id
     */
    public function retryHeld(int $limit = 20): array
    {
        $held = Payout::query()
            ->where('provider', self::PROVIDER)
            ->where('status', Payout::$waiting)
            ->whereIn('provider_status', self::HOLD_STATUSES)
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $results = [];

        foreach ($held as $payout) {
            $owner = $payout->user;
            $selectedBank = $payout->userSelectedBank;

            if (empty($owner) or empty($selectedBank)) {
                $results[$payout->id] = 'dilewati: user / rekening tidak ditemukan';
                continue;
            }

            // saldo user belum dipotong selama ditahan; pastikan masih cukup
            if ((float) $owner->getPayout(true) < (float) $payout->amount) {
                $payout->update(['status' => Payout::$reject, 'provider_status' => 'failed', 'processed_at' => time()]);
                $this->appendData($payout, ['result' => ['reason' => 'Saldo pengguna tidak lagi mencukupi']]);
                $results[$payout->id] = 'ditolak: saldo pengguna tidak cukup';
                continue;
            }

            try {
                $payout = $this->disburse($owner, $selectedBank, (float) $payout->amount, 'Payout Request #' . $payout->id, $payout);
            } catch (\Throwable $e) {
                $this->appendData($payout, ['last_retry_error' => $e->getMessage(), 'last_retry_at' => date('c')]);
                $results[$payout->id] = 'gagal: ' . $e->getMessage();
                continue;
            }

            $results[$payout->id] = $payout->status . ' (' . $payout->provider_status . ')';

            if ($payout->status === Payout::$waiting) {
                break; // deposit masih kurang / belum bisa dicek
            }
        }

        return $results;
    }

    /**
     * Tahan payout (status `waiting`, saldo user belum dipotong) sampai saldo deposit Espay cukup.
     */
    protected function hold(User $owner, UserSelectedBank $selectedBank, float $amount, ?Payout $existing, string $reason, ?float $deposit, float $needed): Payout
    {
        $payout = $existing ?: Payout::create([
            'user_id' => $owner->id,
            'user_selected_bank_id' => $selectedBank->id,
            'amount' => $amount,
            'status' => Payout::$waiting,
            'is_automatic' => true,
            'provider' => self::PROVIDER,
            'created_at' => time(),
        ]);

        $firstHold = !in_array($payout->provider_status, self::HOLD_STATUSES, true);

        $payout->update(['provider' => self::PROVIDER, 'provider_status' => $reason]);
        $this->appendData($payout, ['hold' => ['reason' => $reason, 'deposit' => $deposit, 'needed' => $needed, 'at' => date('c')]]);

        Log::channel('espay')->warning('[Payout] ditahan, saldo deposit Espay', ['payout_id' => $payout->id, 'reason' => $reason, 'deposit' => $deposit, 'needed' => $needed]);

        if ($firstHold) {
            $this->notifyAdminHold($payout, $owner, $reason, $deposit, $needed);
        }

        return $payout->refresh();
    }

    protected function notifyAdminHold(Payout $payout, User $owner, string $reason, ?float $deposit, float $needed): void
    {
        $adminId = User::getMainAdminId() ?: 1;
        $title = $reason === self::HOLD_INSUFFICIENT ? 'Saldo deposit Espay tidak cukup' : 'Saldo deposit Espay gagal dicek';
        $message = 'Pencairan ' . handlePrice($payout->amount) . ' untuk ' . $owner->full_name . ' (Payout #' . $payout->id . ') ditahan karena '
            . ($reason === self::HOLD_INSUFFICIENT
                ? 'saldo deposit Espay ' . handlePrice($deposit) . ' kurang dari kebutuhan ' . handlePrice($needed) . '. Silakan isi saldo deposit Espay; pencairan akan diproses otomatis.'
                : 'saldo deposit Espay tidak dapat dicek (Balance Inquiry gagal). Sistem akan mencoba lagi secara otomatis.');

        try {
            \App\Models\Notification::create([
                'user_id' => $adminId,
                'group_id' => null,
                'title' => $title,
                'message' => $message,
                'sender' => 'system',
                'type' => 'single',
                'created_at' => time(),
            ]);

            $admin = User::find($adminId);

            if (env('APP_ENV') == 'production' and !empty($admin?->email)) {
                \Mail::to($admin->email)->send(new \App\Mail\SendNotifications(['title' => $title, 'message' => $message]));
            }
        } catch (\Throwable $e) {
            Log::channel('espay')->warning('[Payout] notifikasi admin gagal', ['payout_id' => $payout->id, 'error' => $e->getMessage()]);
        }
    }

    public static function isSuccessCode(string $responseCode): bool
    {
        return str_starts_with($responseCode, '200') or str_starts_with($responseCode, '202');
    }

    /**
     * Potong saldo: pendapatan (income) dulu, sisanya dari saldo aset (sama seperti alur Flip).
     */
    protected function deductBalance(User $owner, Payout $payout): void
    {
        $remaining = (float) $payout->amount;
        $description = trans('financial.payout_request') . ' (Espay #' . $payout->id . ')';
        $incomeBalance = (float) $owner->getIncomeBalance();

        if ($incomeBalance > 0) {
            $fromIncome = min($incomeBalance, $remaining);
            $this->accounting($owner->id, $payout->id, $fromIncome, Accounting::$income, Accounting::$deduction, $description);
            $remaining -= $fromIncome;
        }

        if ($remaining > 0) {
            $this->accounting($owner->id, $payout->id, $remaining, Accounting::$asset, Accounting::$deduction, $description);
        }
    }

    /**
     * Kembalikan saldo persis seperti saat dipotong (per jenis akun).
     */
    protected function refundBalance(Payout $payout): void
    {
        $deductions = Accounting::query()
            ->where('payout_id', $payout->id)
            ->where('type', Accounting::$deduction)
            ->get();

        foreach ($deductions as $deduction) {
            $this->accounting($payout->user_id, $payout->id, (float) $deduction->amount, $deduction->type_account, Accounting::$addiction,
                'Pengembalian dana pencairan gagal (Espay #' . $payout->id . ')');
        }
    }

    protected function accounting(int $userId, int $payoutId, float $amount, string $typeAccount, string $type, string $description): void
    {
        Accounting::create([
            'user_id' => $userId,
            'amount' => $amount,
            'payout_id' => $payoutId,
            'type_account' => $typeAccount,
            'type' => $type,
            'store_type' => Accounting::$storeAutomatic,
            'description' => $description,
            'created_at' => time(),
        ]);
    }

    protected function appendData(Payout $payout, array $data, ?string $providerStatus = null): void
    {
        $payout->refresh();
        $update = ['provider_data' => json_encode(array_merge($payout->providerData(), $data), JSON_UNESCAPED_SLASHES)];

        if ($providerStatus !== null) {
            $update['provider_status'] = $providerStatus;
        }

        $payout->update($update);
    }
}
