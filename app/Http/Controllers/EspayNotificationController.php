<?php

namespace App\Http\Controllers;

use App\Services\Espay\EspayClient;
use App\Services\Espay\EspayException;
use App\Services\Espay\EspayStaticVaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Payment Notification non-SNAP (Espay -> Merchant) untuk VA Static Open.
 * docs: Payment Notification (form-urlencoded), signature Hash-Based:
 *   request  : sha256(UPPER("##signature_key##rq_datetime##order_id##PAYMENTREPORT##"))
 *   response : sha256(UPPER("##signature_key##rq_uuid##rs_datetime##error_code##PAYMENTREPORT-RS##"))
 *
 * payment_ref disimpan (espay_va_payments.trx_id unik) -> notifikasi kedua dengan payment_ref sama
 * dijawab error "double payment".
 */
class EspayNotificationController extends Controller
{
    public const UAT_FAULT_CACHE_KEY = 'espay.uat_fault';

    /** Skenario negatif UAT Espay (hanya sandbox), diatur dengan: php artisan espay:uat-fault {mode} */
    public const UAT_FAULTS = [
        'signature' => 'Signature response sengaja salah (key salah)',
        'signature_component' => 'Komponen signature response dihilangkan (error_code tidak ikut di-hash)',
        'password' => 'Password tersimpan sengaja salah -> response invalid password',
        'format' => 'Format response tidak sesuai TSD (bukan JSON)',
    ];

    private const MANDATORY = ['rq_uuid', 'rq_datetime', 'signature', 'comm_code', 'order_id', 'ccy', 'amount', 'payment_ref'];

    public function __invoke(Request $request)
    {
        $client = new EspayClient();
        $fault = $client->isProduction() ? null : Cache::get(self::UAT_FAULT_CACHE_KEY);
        $orderId = trim((string) $request->input('order_id', ''));

        try {
            foreach (self::MANDATORY as $field) {
                if (trim((string) $request->input($field, '')) === '') {
                    throw new EspayException(400, '', '0001', 'Mandatory field ' . $field);
                }
            }

            $signatureKey = (string) $client->signatureKey();
            if ($signatureKey === '') {
                throw new EspayException(500, '', '0099', 'Signature key not configured');
            }

            $expected = EspayClient::hashSignature($signatureKey, [$request->input('rq_datetime'), $orderId, 'PAYMENTREPORT']);
            if (!hash_equals($expected, strtolower(trim((string) $request->input('signature'))))) {
                throw new EspayException(401, '', '0002', 'Invalid Signature');
            }

            $storedPassword = (string) $client->notificationPassword();
            if ($fault === 'password') {
                $storedPassword .= '-UAT-WRONG';
            }
            if ($storedPassword !== '' and !hash_equals($storedPassword, (string) $request->input('password', ''))) {
                throw new EspayException(401, '', '0003', 'Invalid Password');
            }

            if (strcasecmp(trim((string) $request->input('comm_code')), (string) $client->merchantCode()) !== 0) {
                throw new EspayException(404, '', '0004', 'Invalid Community Code');
            }

            if (strtoupper(trim((string) $request->input('ccy'))) !== 'IDR') {
                throw new EspayException(400, '', '0005', 'Invalid Currency');
            }

            $amount = (string) $request->input('amount');
            if (!preg_match('/^\d{1,13}(\.\d{1,2})?$/', $amount) or (float) $amount <= 0) {
                throw new EspayException(400, '', '0013', 'Invalid Amount');
            }

            $va = EspayStaticVaService::findVaByOrderId($orderId);
            if (empty($va)) {
                throw new EspayException(404, '', '0014', 'Invalid Order Id');
            }

            $paymentRef = mb_substr(trim((string) $request->input('payment_ref')), 0, 64);

            if ($fault === null) {
                $this->process($va, $request, $paymentRef, $amount);
            }

            $reconcileId = mb_substr(preg_replace('/[^A-Za-z0-9]/', '', $paymentRef), 0, 20);

            return $this->respond($request, $client, '0000', 'Success', $orderId, $fault, $reconcileId);
        } catch (EspayException $e) {
            // kode dari EspayStaticVaService (service "PN") -> 00xx
            $code = str_contains($e->responseCode, 'PN') ? '00' . substr($e->responseCode, -2) : substr($e->responseCode, -4);

            return $this->respond($request, $client, $code, $e->getMessage(), $orderId, $fault);
        } catch (\Throwable $e) {
            Log::error('[Espay] payment notification failed', ['order_id' => $orderId, 'message' => $e->getMessage()]);

            return $this->respond($request, $client, '0099', 'General Error', $orderId, $fault);
        }
    }

    /**
     * @throws EspayException
     */
    private function process(object $va, Request $request, string $paymentRef, string $amount): void
    {
        $body = [
            'trxId' => $paymentRef,
            'paidAmount' => ['value' => number_format((float) $amount, 2, '.', ''), 'currency' => 'IDR'],
            'additionalInfo' => ['paymentRef' => $paymentRef],
            'notification' => $request->except(['password', 'signature']),
        ];

        $callbackData = [
            'source' => 'payment_notification',
            'trx_id' => $paymentRef,
            'payment_ref' => $paymentRef,
            'paid_amount' => $body['paidAmount']['value'],
            'product_code' => $request->input('product_code'),
            'debit_from_bank' => $request->input('debit_from_bank'),
            'payment_datetime' => $request->input('payment_datetime'),
            'rq_uuid' => $request->input('rq_uuid'),
        ];

        try {
            app(EspayStaticVaService::class)->handlePayment($va, $body, $callbackData, 'PN');
        } catch (EspayException $e) {
            // payment_ref sudah pernah diproses
            if (str_ends_with($e->responseCode, 'PN14')) {
                throw new EspayException(409, '', '0015', 'double payment');
            }

            throw $e;
        }
    }

    private function respond(Request $request, EspayClient $client, string $code, string $message, string $orderId, ?string $fault, ?string $reconcileId = null)
    {
        $rqUuid = (string) $request->input('rq_uuid', '');
        $rsDatetime = now()->format('Y-m-d H:i:s');
        $key = (string) $client->signatureKey();

        $signatureParts = [$rqUuid, $rsDatetime, $code, 'PAYMENTREPORT-RS'];
        if ($fault === 'signature') {
            $key = strrev($key) . 'X';
        } elseif ($fault === 'signature_component') {
            unset($signatureParts[2]);
        }

        $payload = [
            'rq_uuid' => $rqUuid,
            'rs_datetime' => $rsDatetime,
            'error_code' => $code,
            'error_message' => $message,
            'signature' => $key !== '' ? EspayClient::hashSignature($key, array_values($signatureParts)) : '',
            'order_id' => $orderId,
        ];

        if ($code === '0000') {
            $payload['reconcile_id'] = $reconcileId;
            $payload['reconcile_datetime'] = $rsDatetime;
        }

        if ($code !== '0000') {
            Log::channel('espay')->warning('[NOTIF] payment notification rejected', ['order_id' => $orderId, 'error_code' => $code, 'message' => $message]);
        }

        if ($fault === 'format') {
            return response('OK ' . $code, 200)->header('Content-Type', 'text/plain');
        }

        return response()->json($payload, 200, [], JSON_UNESCAPED_SLASHES);
    }
}
