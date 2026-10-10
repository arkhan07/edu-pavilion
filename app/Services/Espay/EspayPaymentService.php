<?php

namespace App\Services\Espay;

use App\Http\Controllers\Web\PaymentController;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ReserveMeeting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class EspayPaymentService
{
    public const GATEWAY = 'Espay';

    /**
     * Nomor transaksi untuk Espay (virtualAccountNo / partnerReferenceNo), maks 28 karakter.
     * Angka saja: nomor VA bank (dan simulator SNAP VA Espay) menolak huruf.
     * Format: {id order}{yymmddHHiiss}{2 digit acak}, contoh: 21426100515311842
     */
    public static function makePaymentId(Order $order): string
    {
        return mb_substr($order->id . date('ymdHis') . random_int(10, 99), 0, 28);
    }

    public static function paymentData(Order $order): array
    {
        return json_decode((string) $order->payment_data, true) ?: [];
    }

    public static function amountForOrder(Order $order): int
    {
        return (int) (self::paymentData($order)['amount'] ?? 0);
    }

    public static function formatAmount($amount): string
    {
        return number_format((float) $amount, 2, '.', '');
    }

    public static function isEspayOrder(?Order $order): bool
    {
        return !empty($order) and (self::paymentData($order)['gateway'] ?? null) === self::GATEWAY;
    }

    public static function findOrderByPaymentId(?string $paymentId, bool $lock = false): ?Order
    {
        if (empty($paymentId) or !preg_match('/^[A-Za-z0-9]{1,28}$/', $paymentId)) {
            return null;
        }

        $query = Order::query()->where('reference_id', $paymentId);
        if ($lock) {
            $query->lockForUpdate();
        }
        $order = $query->first();

        // Nomor transaksi lama (user sempat ganti metode pembayaran)
        if (empty($order)) {
            $query = Order::query()->where('payment_data', 'like', '%"' . $paymentId . '"%');
            if ($lock) {
                $query->lockForUpdate();
            }
            $order = $query->first();

            if (!empty($order) and !in_array($paymentId, self::paymentData($order)['payment_ids'] ?? [], true)) {
                $order = null;
            }
        }

        return self::isEspayOrder($order) ? $order : null;
    }

    public static function updatePaymentData(Order $order, array $data): void
    {
        $order->update(['payment_data' => json_encode(array_merge(self::paymentData($order), $data))]);
    }

    /**
     * Membuat transaksi baru di Espay untuk produk yang dipilih customer.
     * QRIS -> QR MPM (QR ditampilkan di halaman kita), lainnya -> Payment Host to Host (redirect).
     *
     * @return array ['type' => 'redirect'|'qris', 'url' => ?string]
     */
    public function startPayment(Order $order, string $bankCode, string $productCode, string $productName = ''): array
    {
        $client = new EspayClient();
        $client->assertConfigured();

        $paymentData = self::paymentData($order);
        $amount = self::formatAmount($paymentData['amount'] ?? 0);

        $isQris = self::isQrisProduct($productCode);
        $useStaticVa = !$isQris and EspayStaticVaService::isEnabled();

        // Transaksi dinamis sebelumnya dinonaktifkan agar tidak bisa dibayar dobel
        // (VA Static Open milik user tidak dihapus: nomornya tetap dipakai untuk transaksi berikutnya)
        if (!empty($order->reference_id) and !$useStaticVa and empty(self::paymentData($order)['va_number'])) {
            $this->deleteVa($order->reference_id);
        }

        $paymentId = self::makePaymentId($order);
        $paymentIds = $paymentData['payment_ids'] ?? [];
        $paymentIds[] = $paymentId;

        $order->update(['status' => Order::$pending, 'reference_id' => $paymentId]);
        self::updatePaymentData($order, [
            'payment_id' => $paymentId,
            'payment_ids' => $paymentIds,
            'bank_code' => $bankCode,
            'product_code' => $productCode,
            'product_name' => $productName,
            'inquiry_request_id' => null,
            'qris' => null,
            'va_number' => null,
            'started_at' => time(),
        ]);

        // VA Static Open: tampilkan nomor VA tetap milik user; pembayaran dicocokkan dari nominal
        if ($useStaticVa) {
            $va = app(EspayStaticVaService::class)->vaFor($order->user, $bankCode);

            self::updatePaymentData($order->refresh(), [
                'va_number' => $va['va_number'],
                'va_expired_at' => $va['expired_at'],
            ]);

            return ['type' => 'va', 'url' => null];
        }

        $validUpTo = EspayClient::timestamp(now()->addMinutes((int) config('espay.expiry_minutes', 1440)));
        $user = $order->user;

        if ($isQris) {
            $response = $client->qrMpmGenerate([
                'partnerReferenceNo' => $paymentId,
                'merchantId' => $client->merchantCode(),
                'amount' => ['value' => $amount, 'currency' => 'IDR'],
                'additionalInfo' => [
                    'productCode' => (string) config('espay.qris_product_code', 'QRIS'),
                ],
                'validityPeriod' => $validUpTo,
            ]);

            if (($response['responseCode'] ?? null) !== '2004700' or empty($response['qrContent'] ?? $response['qrImage'] ?? $response['qrUrl'] ?? null)) {
                throw new RuntimeException('Espay QRIS gagal: ' . ($response['responseMessage'] ?? 'response tidak valid'));
            }

            self::updatePaymentData($order->refresh(), ['qris' => [
                'content' => $response['qrContent'] ?? null,
                'image' => $response['qrImage'] ?? null,
                'url' => $response['qrUrl'] ?? null,
                'reference_no' => data_get($response, 'additionalInfo.referenceNo'),
                'valid_up_to' => $validUpTo,
            ]]);

            return ['type' => 'qris', 'url' => null];
        }

        $response = $client->paymentHostToHost([
            'partnerReferenceNo' => $paymentId,
            'merchantId' => $client->merchantCode(),
            'subMerchantId' => $client->apiKey(),
            'amount' => ['value' => $amount, 'currency' => 'IDR'],
            'urlParam' => [
                'url' => url('/payments/verify/Espay?order_id=' . $order->id),
                'type' => 'PAY_RETURN',
                'isDeeplink' => 'N',
            ],
            'validUpTo' => $validUpTo,
            'pointOfInitiation' => 'Website',
            'payOptionDetails' => [
                'payMethod' => $bankCode,
                'payOption' => $productCode,
                'transAmount' => ['value' => $amount, 'currency' => 'IDR'],
                'feeAmount' => ['value' => '0.00', 'currency' => 'IDR'],
            ],
            'additionalInfo' => array_filter([
                'payType' => 'REDIRECT',
                'userId' => (string) $order->user_id,
                'userName' => self::ascii($user->full_name ?? 'Customer', 64),
                'userEmail' => mb_substr((string) ($user->email ?? ''), 0, 64),
                'userPhone' => self::phone($user->mobile ?? '', 16),
                'productCode' => $productCode,
            ], fn ($value) => $value !== ''),
        ]);

        if (($response['responseCode'] ?? null) !== '2005400' or empty($response['webRedirectUrl'])) {
            throw new RuntimeException('Espay Payment Host to Host gagal: ' . ($response['responseMessage'] ?? 'response tidak valid'));
        }

        return ['type' => 'redirect', 'url' => $response['webRedirectUrl']];
    }

    public function deleteVa(string $paymentId): void
    {
        try {
            (new EspayClient())->deleteVa($paymentId);
        } catch (\Throwable $e) {
            Log::info('[Espay] delete VA skipped: ' . $e->getMessage(), ['payment_id' => $paymentId]);
        }
    }

    /**
     * Tandai order lunas. Order yang sudah lunas ditolak (double payment).
     *
     * @throws EspayException
     */
    public function settle(string $paymentId, array $callbackData, ?float $totalAmount = null, string $serviceCode = '25'): Order
    {
        return DB::transaction(function () use ($paymentId, $callbackData, $totalAmount, $serviceCode) {
            $order = self::findOrderByPaymentId($paymentId, true);

            if (empty($order)) {
                throw new EspayException(404, $serviceCode, '12', 'Invalid Bill/Virtual Account [Not Found]');
            }

            if ($order->status === Order::$paid) {
                throw new EspayException(404, $serviceCode, '14', 'Paid Bill');
            }

            $expectedAmount = self::amountForOrder($order);
            if ($totalAmount !== null and $expectedAmount > 0 and (int) round($totalAmount) !== $expectedAmount) {
                throw new EspayException(404, $serviceCode, '13', 'Invalid Amount');
            }

            // "fail" tetap diterima: transaksi bisa expired lalu ternyata dibayar (notifikasi terlambat)
            if (!in_array($order->status, [Order::$pending, Order::$paying, Order::$fail], true)) {
                throw new EspayException(404, $serviceCode, '19', 'Invalid Bill/Virtual Account');
            }

            self::updatePaymentData($order, ['callback' => array_merge($callbackData, ['received_at' => now()->toIso8601String()])]);

            app(PaymentController::class)->setPaymentAccounting($order);
            $order->update(['status' => Order::$paid]);

            Log::info('[Espay] order paid', ['order_id' => $order->id, 'payment_id' => $paymentId]);

            return $order->refresh();
        }, 3);
    }

    public function markFailed(Order $order, array $statusData = []): Order
    {
        if ($order->status === Order::$paid) {
            return $order;
        }

        self::updatePaymentData($order, ['last_status' => $statusData]);
        $order->update(['status' => Order::$fail]);

        if ($order->type === Order::$meeting) {
            $orderItem = OrderItem::query()->where('order_id', $order->id)->first();

            if (!empty($orderItem?->reserve_meeting_id)) {
                ReserveMeeting::query()
                    ->where('id', $orderItem->reserve_meeting_id)
                    ->update(['locked_at' => null]);
            }
        }

        return $order->refresh();
    }

    /**
     * Sinkronisasi status via Inquiry Status (fallback bila callback Payment belum masuk).
     */
    public function syncStatus(Order $order): Order
    {
        if (!self::isEspayOrder($order) or empty($order->reference_id) or empty(self::paymentData($order)['product_code'])) {
            return $order;
        }

        // VA Static Open: notifikasi Payment; bila belum masuk, cek transaksi terakhir VA via Check Payment Status
        if (!empty(self::paymentData($order)['va_number'])) {
            if (in_array($order->status, [Order::$pending, Order::$paying], true) and $va = EspayStaticVaService::vaForOrder($order)) {
                app(EspayStaticVaService::class)->syncFromEspay($va);
            }

            return $order->refresh();
        }

        if (!in_array($order->status, [Order::$pending, Order::$paying], true)) {
            return $order;
        }

        $paymentData = self::paymentData($order);

        try {
            $response = (new EspayClient())->inquiryStatus($order->reference_id, $paymentData['inquiry_request_id'] ?? null);
        } catch (\Throwable $e) {
            Log::warning('[Espay] inquiry status failed: ' . $e->getMessage(), ['order_id' => $order->id]);
            return $order;
        }

        if (($response['responseCode'] ?? null) !== '2002600') {
            return $order;
        }

        $vaData = $response['virtualAccountData'] ?? [];
        $flag = strtoupper((string) ($vaData['paymentFlagStatus'] ?? ''));

        if ($flag === 'S') {
            try {
                return $this->settle($order->reference_id, [
                    'source' => 'inquiry_status',
                    'payment_request_id' => $vaData['paymentRequestId'] ?? null,
                    'trx_id' => data_get($vaData, 'additionalInfo.trxId'),
                    'product_code' => data_get($vaData, 'additionalInfo.productCode'),
                ], isset($vaData['totalAmount']['value']) ? (float) $vaData['totalAmount']['value'] : null, '26');
            } catch (EspayException $e) {
                Log::warning('[Espay] settle via inquiry status rejected: ' . $e->getMessage(), ['order_id' => $order->id]);
                return $order->refresh();
            }
        }

        if (in_array($flag, ['F', 'EX'], true)) {
            return $this->markFailed($order, $vaData);
        }

        return $order;
    }

    public static function isQrisProduct(string $productCode): bool
    {
        return str_contains(strtoupper($productCode), 'QRIS');
    }

    public static function ascii(string $value, int $max): string
    {
        $value = trim(preg_replace('/[^\x20-\x7E]/', '', Str::ascii($value)));

        return mb_substr($value !== '' ? $value : 'Customer', 0, $max);
    }

    /**
     * Format nomor telepon 62xxxxxxxx.
     */
    public static function phone(?string $value, int $max): string
    {
        $digits = preg_replace('/[^0-9]/', '', (string) $value);

        if (str_starts_with($digits, '0')) {
            $digits = '62' . substr($digits, 1);
        }

        return mb_substr($digits, 0, $max);
    }

    /**
     * Deskripsi tagihan (maks 18 karakter).
     */
    public static function billDescription(Order $order): array
    {
        return [
            'english' => mb_substr('Order #' . $order->id, 0, 18),
            'indonesia' => mb_substr('Pesanan #' . $order->id, 0, 18),
        ];
    }
}
