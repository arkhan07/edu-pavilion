<?php

namespace App\Services\Espay;

use App\Http\Controllers\Web\PaymentController;
use App\Models\Order;
use App\Models\OrderItem;
use App\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Espay Virtual Account Static Open (tipe VA merchant Edu Pavilion).
 *
 *  - Setiap user punya satu nomor VA tetap per bank (dibuat sekali via sendinvoice, diperpanjang otomatis).
 *  - Nominal bebas: Espay hanya mengirim callback Payment (tanpa Inquiry).
 *  - Pembayaran masuk:
 *      nominal sama dengan order Espay user yang masih pending  -> order tersebut lunas
 *      selain itu                                               -> top up saldo (charge account) sebesar nominal
 */
class EspayStaticVaService
{
    public static function isEnabled(): bool
    {
        return config('espay.va_type', 'static_open') === 'static_open';
    }

    public static function orderIdFor(int $userId, string $bankCode): string
    {
        return 'EDUU' . $userId . 'B' . $bankCode;
    }

    /**
     * Nomor VA tetap milik user untuk bank tertentu (dibuat / diperpanjang bila perlu).
     *
     * @return array{va_number: string, bank_code: string, expired_at: ?int}
     */
    public function vaFor(User $user, string $bankCode): array
    {
        $existing = DB::table('espay_virtual_accounts')->where('user_id', $user->id)->where('bank_code', $bankCode)->first();

        // masih berlaku > 1 hari: pakai yang ada
        if (!empty($existing) and (empty($existing->expired_at) or $existing->expired_at > time() + 86400)) {
            return ['va_number' => $existing->va_number, 'bank_code' => $bankCode, 'expired_at' => $existing->expired_at];
        }

        $orderId = self::orderIdFor($user->id, $bankCode);
        $params = [
            'order_id' => $orderId,
            'remark1' => EspayPaymentService::phone($user->mobile ?? '', 17) ?: '0',
            'remark2' => mb_substr(trim(preg_replace('/[^A-Za-z0-9 ]/', '', EspayPaymentService::ascii($user->full_name ?? 'Customer', 60))) ?: 'Customer', 0, 30),
            'remark3' => mb_substr((string) ($user->email ?? ''), 0, 50),
            'remark4' => (string) $user->id,
            'bank_code' => $bankCode,
            'va_expired' => (string) config('espay.static_va_expired_minutes', 525600),
            'update' => empty($existing) ? 'N' : 'Y',
        ];

        $client = new EspayClient();
        $response = $client->sendInvoice($params);

        // order_id sudah pernah dibuat (mis. data lokal direset) -> perbarui
        if (empty($response['va_number']) and stripos((string) ($response['error_message'] ?? ''), 'duplicate') !== false) {
            $response = $client->sendInvoice(array_merge($params, ['update' => 'Y']));
        }

        if (!in_array((string) ($response['error_code'] ?? ''), ['00', '0000'], true) or empty($response['va_number'])) {
            throw new RuntimeException('Espay gagal membuat Virtual Account: ' . ($response['error_message'] ?? 'response tidak valid'));
        }

        $expiredAt = !empty($response['expired']) ? strtotime($response['expired']) ?: null : null;

        DB::table('espay_virtual_accounts')->updateOrInsert(
            ['user_id' => $user->id, 'bank_code' => $bankCode],
            [
                'order_id' => $orderId,
                'va_number' => (string) $response['va_number'],
                'expired_at' => $expiredAt,
                'data' => json_encode($response, JSON_UNESCAPED_SLASHES),
                'created_at' => $existing->created_at ?? time(),
                'updated_at' => time(),
            ]
        );

        return ['va_number' => (string) $response['va_number'], 'bank_code' => $bankCode, 'expired_at' => $expiredAt];
    }

    /**
     * Cari VA dari callback Payment (virtualAccountNo bisa nomor VA penuh atau digabung partnerServiceId/customerNo).
     */
    /**
     * VA dari order_id sendinvoice (notifikasi pembayaran non-SNAP).
     */
    public static function findVaByOrderId(string $orderId): ?object
    {
        return DB::table('espay_virtual_accounts')->where('order_id', trim($orderId))->first() ?: null;
    }

    public static function findVa(array $body): ?object
    {
        // Espay mengirim order_id sendinvoice sebagai virtualAccountNo / additionalInfo.userId (contoh EDUU2B008)
        foreach ([$body['virtualAccountNo'] ?? null, data_get($body, 'additionalInfo.userId')] as $orderId) {
            if (is_string($orderId) and preg_match('/^EDUU\d+B\d{3}$/i', trim($orderId)) and $row = self::findVaByOrderId(strtoupper(trim($orderId)))) {
                return $row;
            }
        }

        // nomor VA di additionalInfo.productValue
        $productValue = preg_replace('/\D/', '', (string) data_get($body, 'additionalInfo.productValue', ''));
        if (strlen($productValue) >= 8 and $row = DB::table('espay_virtual_accounts')->where('va_number', $productValue)->first()) {
            return $row;
        }

        $va = preg_replace('/\D/', '', (string) ($body['virtualAccountNo'] ?? ''));
        $customerNo = preg_replace('/\D/', '', (string) ($body['customerNo'] ?? ''));
        $serviceId = preg_replace('/\D/', '', (string) ($body['partnerServiceId'] ?? ''));

        $candidates = array_values(array_unique(array_filter([$va, $customerNo . $va, $serviceId . $customerNo . $va, $serviceId . $va])));

        if (empty($candidates)) {
            return null;
        }

        $row = DB::table('espay_virtual_accounts')->whereIn('va_number', $candidates)->first();

        // nomor dikirim tanpa prefix bank
        if (empty($row) and strlen($va) >= 8) {
            $row = DB::table('espay_virtual_accounts')->where('va_number', 'like', '%' . $va)->first();
        }

        return $row ?: null;
    }

    /**
     * Proses pembayaran ke VA Static Open (idempotent per trxId).
     *
     * @return array{result: string, order: Order}
     * @throws EspayException
     */
    public function handlePayment(object $va, array $body, array $callbackData, string $serviceCode = '25'): array
    {
        $amount = (float) (data_get($body, 'paidAmount.value') ?? data_get($body, 'totalAmount.value') ?? 0);
        // ID transaksi Espay (ESP...): trxId di notifikasi SNAP, tx_id di Check Payment Status.
        // payment_ref ikut disimpan -> pembayaran yang sama tidak diproses dua kali lewat jalur mana pun.
        $trxId = mb_substr((string) (data_get($body, 'check_status.tx_id') ?: ($body['trxId'] ?? '') ?: data_get($body, 'additionalInfo.paymentRef') ?: ($body['paymentRequestId'] ?? '')), 0, 64);
        $paymentRef = mb_substr((string) (data_get($body, 'additionalInfo.paymentRef') ?: ''), 0, 64);

        if ($amount <= 0) {
            throw new EspayException(404, $serviceCode, '13', 'Invalid Amount');
        }

        if ($trxId === '') {
            throw new EspayException(400, $serviceCode, '02', 'Invalid Mandatory Field {trxId}');
        }

        return DB::transaction(function () use ($va, $body, $callbackData, $serviceCode, $amount, $trxId, $paymentRef) {
            // catat dulu: trx_id unik -> notifikasi ganda ditolak tanpa memproses ulang
            if (self::isRecorded($trxId, $paymentRef)) {
                throw new EspayException(404, $serviceCode, '14', 'Paid Bill');
            }

            try {
                $paymentLogId = DB::table('espay_va_payments')->insertGetId([
                    'trx_id' => $trxId,
                    'payment_ref' => $paymentRef !== '' ? $paymentRef : null,
                    'espay_virtual_account_id' => $va->id,
                    'user_id' => $va->user_id,
                    'va_number' => $va->va_number,
                    'amount' => $amount,
                    'result' => 'processing',
                    'data' => json_encode($body, JSON_UNESCAPED_SLASHES),
                    'created_at' => time(),
                ]);
            } catch (QueryException $e) {
                throw new EspayException(404, $serviceCode, '14', 'Paid Bill');
            }

            $user = User::query()->find($va->user_id);
            if (empty($user)) {
                throw new EspayException(404, $serviceCode, '12', 'Invalid Bill/Virtual Account [Not Found]');
            }

            $service = app(EspayPaymentService::class);
            $callbackData = array_merge($callbackData, ['va_number' => $va->va_number, 'static_va' => true]);

            // 1. order Espay user yang menunggu pembayaran dengan nominal sama persis (terbaru dulu)
            $order = Order::query()
                ->where('user_id', $user->id)
                ->whereIn('status', [Order::$pending, Order::$paying])
                ->where('payment_data', 'like', '%"gateway":"Espay"%')
                // hanya order yang memang dibayar lewat VA ini (order QRIS / VA bank lain tidak ikut terlunasi)
                ->where('payment_data', 'like', '%"va_number":"' . $va->va_number . '"%')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->get()
                ->first(fn (Order $item) => (int) round(EspayPaymentService::amountForOrder($item)) === (int) round($amount) and !empty($item->reference_id));

            $result = 'order';

            // 2. tidak ada yang cocok -> top up saldo sebesar nominal yang dibayar
            if (empty($order)) {
                $order = $this->createTopupOrder($user, $amount, ['bank_code' => $va->bank_code, 'va_number' => $va->va_number, 'source' => 'static_va_topup']);
                $result = 'topup';
            }

            $order = $service->settle($order->reference_id, $callbackData, $amount, $serviceCode);

            DB::table('espay_va_payments')->where('id', $paymentLogId)->update(['order_id' => $order->id, 'result' => $result]);

            Log::channel('espay')->info('[VA] pembayaran static VA', ['va' => $va->va_number, 'user_id' => $user->id, 'amount' => $amount, 'result' => $result, 'order_id' => $order->id]);

            return ['result' => $result, 'order' => $order];
        }, 3);
    }

    /**
     * Cadangan bila Payment Notification Espay tidak sampai (transaksi Suspect / PR31):
     * cek transaksi terakhir VA via Check Payment Status lalu proses seperti notifikasi.
     * Dibatasi maks 1x / 15 detik per VA.
     *
     * @return array{result: string, order: Order}|null
     */
    public function syncFromEspay(object $va, bool $force = false): ?array
    {
        if (!$force and !Cache::add('espay.va_sync.' . $va->id, 1, 15)) {
            return null;
        }

        try {
            $client = new EspayClient();
            $status = $client->checkPaymentStatus($va->order_id);
        } catch (\Throwable $e) {
            Log::warning('[Espay] VA check status failed: ' . $e->getMessage(), ['va' => $va->va_number]);
            return null;
        }

        $txStatus = strtoupper((string) ($status['tx_status'] ?? ''));
        $paymentRef = mb_substr(trim((string) ($status['payment_ref'] ?? '')), 0, 64);
        $amount = (float) ($status['amount'] ?? 0);

        // S = sukses, SP = sudah dibayar tetapi notifikasi ke merchant gagal
        if (($status['error_code'] ?? null) !== '0000' or !in_array($txStatus, ['S', 'SP'], true) or $paymentRef === '' or $amount <= 0) {
            return null;
        }

        if (self::isRecorded((string) ($status['tx_id'] ?? ''), $paymentRef)) {
            return null;
        }

        $body = [
            'trxId' => $paymentRef,
            'paidAmount' => ['value' => number_format($amount, 2, '.', ''), 'currency' => 'IDR'],
            'additionalInfo' => ['paymentRef' => $paymentRef],
            'check_status' => $status,
        ];

        try {
            $result = $this->handlePayment($va, $body, [
                'source' => 'check_status',
                'trx_id' => $status['tx_id'] ?? $paymentRef,
                'payment_ref' => $paymentRef,
                'paid_amount' => $body['paidAmount']['value'],
                'tx_status' => $txStatus,
                'product_code' => $status['product_name'] ?? null,
                'payment_datetime' => $status['payment_datetime'] ?? null,
            ], 'CS');
        } catch (EspayException $e) {
            Log::warning('[Espay] VA check status not processed: ' . $e->getMessage(), ['va' => $va->va_number, 'payment_ref' => $paymentRef]);
            return null;
        }

        // transaksi Suspect di dashboard Espay ditandai Success karena sudah kami proses
        if ($txStatus === 'SP') {
            try {
                $client->checkPaymentStatus($va->order_id, 'N');
            } catch (\Throwable $e) {
                Log::info('[Espay] mark success at Espay skipped: ' . $e->getMessage());
            }
        }

        return $result;
    }

    /**
     * Pembayaran sudah tercatat (cocok dengan ID transaksi atau payment_ref Espay)?
     */
    public static function isRecorded(string $trxId, string $paymentRef = ''): bool
    {
        $keys = array_values(array_unique(array_filter([trim($trxId), trim($paymentRef)], fn ($key) => $key !== '')));

        if (empty($keys)) {
            return false;
        }

        return DB::table('espay_va_payments')
            ->where(fn ($query) => $query->whereIn('trx_id', $keys)->orWhereIn('payment_ref', $keys))
            ->exists();
    }

    /**
     * VA milik order Espay (VA Static).
     */
    public static function vaForOrder(Order $order): ?object
    {
        $paymentData = EspayPaymentService::paymentData($order);

        if (empty($paymentData['va_number'])) {
            return null;
        }

        return DB::table('espay_virtual_accounts')
            ->where('user_id', $order->user_id)
            ->where('va_number', $paymentData['va_number'])
            ->first() ?: null;
    }

    /**
     * Order top up (charge account) untuk pembayaran VA yang tidak cocok dengan order mana pun.
     */
    /**
     * Pembayaran QRIS untuk order yang ternyata sudah lunas (mis. lewat VA): dana dijadikan top up saldo
     * agar tidak hilang. Idempotent per ID transaksi Espay.
     *
     * @throws EspayException
     */
    public function creditAsTopup(User $user, float $amount, string $trxId, string $paymentRef, array $callbackData, string $serviceCode = '25'): Order
    {
        return DB::transaction(function () use ($user, $amount, $trxId, $paymentRef, $callbackData, $serviceCode) {
            if ($trxId === '' or self::isRecorded($trxId, $paymentRef)) {
                throw new EspayException(404, $serviceCode, '14', 'Paid Bill');
            }

            $logId = DB::table('espay_va_payments')->insertGetId([
                'trx_id' => mb_substr($trxId, 0, 64),
                'payment_ref' => $paymentRef !== '' ? mb_substr($paymentRef, 0, 64) : null,
                'user_id' => $user->id,
                'va_number' => '',
                'amount' => $amount,
                'result' => 'processing',
                'data' => json_encode($callbackData, JSON_UNESCAPED_SLASHES),
                'created_at' => time(),
            ]);

            $order = $this->createTopupOrder($user, $amount, ['source' => 'paid_order_overpayment', 'product_code' => $callbackData['product_code'] ?? null]);
            $order = app(EspayPaymentService::class)->settle($order->reference_id, $callbackData, $amount, $serviceCode);

            DB::table('espay_va_payments')->where('id', $logId)->update(['order_id' => $order->id, 'result' => 'topup']);
            Log::channel('espay')->info('[PAY] pembayaran untuk order yang sudah lunas dijadikan top up', ['user_id' => $user->id, 'amount' => $amount, 'trx_id' => $trxId, 'order_id' => $order->id]);

            return $order;
        }, 3);
    }

    protected function createTopupOrder(User $user, float $amount, array $meta): Order
    {
        $order = Order::query()->create([
            'user_id' => $user->id,
            'status' => Order::$pending,
            'payment_method' => Order::$paymentChannel,
            'is_charge_account' => true,
            'amount' => $amount,
            'tax' => 0,
            'total_discount' => 0,
            'total_amount' => $amount,
            'created_at' => time(),
        ]);

        OrderItem::query()->create([
            'user_id' => $user->id,
            'order_id' => $order->id,
            'amount' => $amount,
            'total_amount' => $amount,
            'tax' => 0,
            'tax_price' => 0,
            'commission' => 0,
            'commission_price' => 0,
            'discount' => 0,
            'created_at' => time(),
        ]);

        $paymentId = EspayPaymentService::makePaymentId($order);
        $order->update(['reference_id' => $paymentId]);
        EspayPaymentService::updatePaymentData($order, [
            'gateway' => EspayPaymentService::GATEWAY,
            'amount' => (int) round($amount),
            'payment_id' => $paymentId,
            'payment_ids' => [$paymentId],
        ] + array_filter($meta));

        return $order->refresh();
    }
}
