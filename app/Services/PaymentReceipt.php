<?php

namespace App\Services;

use App\Models\Order;
use App\Services\Espay\EspayPaymentMethods;
use App\Services\Espay\EspayPaymentService;
use Carbon\Carbon;

/**
 * Data invoice / bukti pembayaran sebuah order (halaman detail riwayat, halaman terima kasih, PDF).
 */
class PaymentReceipt
{
    public static function make(Order $order): array
    {
        $order->loadMissing(['user', 'orderItems' => function ($query) {
            $query->with(['webinar', 'bundle', 'product', 'subscribe', 'promotion', 'registrationPackage']);
        }]);

        $paymentData = json_decode((string) $order->payment_data, true) ?: [];
        $callback = $paymentData['callback'] ?? [];
        $timezone = getTimezone() ?: 'Asia/Jakarta';

        $status = match ($order->status) {
            Order::$paid => 'paid',
            Order::$pending, Order::$paying => 'pending',
            default => 'failed',
        };

        $createdAt = Carbon::createFromTimestamp((int) ($order->created_at ?: time()))->setTimezone($timezone);
        $paidAt = null;
        if ($status === 'paid') {
            $paidAt = !empty($callback['received_at'])
                ? Carbon::parse($callback['received_at'])->setTimezone($timezone)
                : $createdAt;
        }

        return [
            'order' => $order,
            'number' => 'INV/EDU/' . $createdAt->format('Ym') . '/' . str_pad((string) $order->id, 6, '0', STR_PAD_LEFT),
            'status' => $status,
            'status_label' => ['paid' => 'LUNAS', 'pending' => 'MENUNGGU PEMBAYARAN', 'failed' => 'GAGAL / KEDALUWARSA'][$status],
            'created_at' => $createdAt,
            'paid_at' => $paidAt,
            'customer' => [
                'name' => $order->user->full_name ?? '-',
                'email' => $order->user->email ?? null,
                'mobile' => $order->user->mobile ?? null,
            ],
            'merchant' => [
                'name' => getGeneralSettings('site_name') ?: config('app.name'),
                'logo' => getGeneralSettings('logo'),
                'email' => getGeneralSettings('site_email'),
            ],
            'method' => self::method($order, $paymentData),
            'references' => array_filter([
                'ID Transaksi Espay' => $callback['trx_id'] ?? null,
                'Referensi Pembayaran' => ($callback['payment_ref'] ?? null) !== ($callback['trx_id'] ?? null) ? ($callback['payment_ref'] ?? null) : null,
                'No. Transaksi' => $paymentData['payment_id'] ?? $order->reference_id,
            ]),
            'items' => self::items($order),
            'subtotal' => (float) $order->amount,
            'discount' => (float) $order->total_discount,
            'tax' => (float) $order->tax,
            'total' => (float) $order->total_amount,
            'is_topup' => (bool) $order->is_charge_account,
        ];
    }

    private static function method(Order $order, array $paymentData): array
    {
        if ($order->payment_method === Order::$credit) {
            return ['label' => 'Saldo akun', 'logo' => null, 'detail' => null];
        }

        $gateway = $paymentData['gateway'] ?? null;
        $productCode = (string) ($paymentData['product_code'] ?? '');
        $bankCode = $paymentData['bank_code'] ?? null;

        if ($gateway === EspayPaymentService::GATEWAY) {
            if ($productCode !== '' and EspayPaymentService::isQrisProduct($productCode) and empty($paymentData['va_number'])) {
                return ['label' => 'QRIS', 'logo' => EspayPaymentMethods::LOGO_DIR . '/qris.svg', 'detail' => 'via Espay'];
            }

            if (!empty($paymentData['va_number']) or $bankCode) {
                return [
                    'label' => EspayPaymentMethods::bankName($bankCode, $paymentData['product_name'] ?? null) . ' Virtual Account',
                    'logo' => EspayPaymentMethods::bankLogo($bankCode),
                    'detail' => $paymentData['va_number'] ?? null,
                ];
            }

            return ['label' => 'Espay', 'logo' => null, 'detail' => 'Metode belum dipilih'];
        }

        return ['label' => $gateway ?: '-', 'logo' => null, 'detail' => null];
    }

    private static function items(Order $order): array
    {
        $items = [];

        foreach ($order->orderItems as $item) {
            $title = $item->webinar?->title
                ?? $item->bundle?->title
                ?? $item->product?->title
                ?? $item->subscribe?->title
                ?? $item->promotion?->title
                ?? $item->registrationPackage?->title;

            if (empty($title)) {
                $title = $order->is_charge_account ? 'Top up saldo akun' : (!empty($item->reserve_meeting_id) ? 'Reservasi sesi private' : 'Item #' . $item->id);
            }

            $type = match (true) {
                !empty($item->webinar_id) => 'Kursus',
                !empty($item->bundle_id) => 'Paket kursus',
                !empty($item->product_id) => 'Produk',
                !empty($item->subscribe_id) => 'Langganan',
                !empty($item->reserve_meeting_id) => 'Kursus private',
                default => $order->is_charge_account ? 'Saldo' : null,
            };

            $items[] = ['title' => $title, 'type' => $type, 'amount' => (float) $item->amount];
        }

        return $items;
    }
}
