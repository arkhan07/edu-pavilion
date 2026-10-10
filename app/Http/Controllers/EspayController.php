<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\Espay\EspayClient;
use App\Services\Espay\EspayException;
use App\Services\Espay\EspayPaymentMethods;
use App\Services\Espay\EspayPaymentService;
use App\Services\Espay\EspayStaticVaService;
use App\Services\Espay\SnapRequestValidator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class EspayController extends Controller
{
    private const SERVICE_INQUIRY = '24';
    private const SERVICE_PAYMENT = '25';

    /**
     * Pilih metode pembayaran (VA per bank / QRIS). AJAX -> potongan HTML untuk popup,
     * selain itu halaman di dalam dashboard (/panel/financial/espay/{order}).
     */
    public function checkout(Request $request, $orderId)
    {
        $order = $this->ownedOrder($orderId);

        if ($order->status === Order::$paid) {
            return $request->ajax()
                ? response()->json(['redirect' => self::statusUrl($order)])
                : redirect(self::statusUrl($order));
        }

        $data = $this->checkoutData($order);

        if ($request->ajax()) {
            return response()->json(['html' => view('web.default.cart.channels.espay_content', $data)->render()]);
        }

        return view('web.default.cart.channels.espay', array_merge($data, ['pageTitle' => 'Pembayaran']));
    }

    /**
     * Customer memilih metode -> nomor VA Static Open / QRIS ditampilkan di popup yang sama.
     */
    public function pay(Request $request, $orderId)
    {
        $data = $request->validate([
            'bank_code' => 'required|string|max:64',
            'product_code' => 'required|string|max:64',
        ]);

        $order = $this->ownedOrder($orderId);

        if ($order->status === Order::$paid) {
            return $this->payResponse($request, $order, null, self::statusUrl($order));
        }

        // Hanya VA / QRIS yang aktif untuk merchant
        $method = null;
        try {
            $method = EspayPaymentMethods::find((new EspayClient())->merchantInfo(), $data['bank_code'], $data['product_code']);
        } catch (\Throwable $e) {
            Log::warning('[Espay] merchantinfo unavailable on pay: ' . $e->getMessage());
        }

        if (empty($method)) {
            return $this->payResponse($request, $order, 'Metode pembayaran tidak tersedia.');
        }

        try {
            $result = app(EspayPaymentService::class)->startPayment($order, $data['bank_code'], $data['product_code'], $method['name']);
        } catch (\Throwable $e) {
            Log::warning('[Espay] start payment failed', ['order_id' => $order->id, 'error' => $e->getMessage()]);

            return $this->payResponse($request, $order->refresh(), 'Gagal membuat transaksi ' . $method['name'] . '. Silakan coba lagi atau pilih metode lain.');
        }

        if ($result['type'] === 'redirect') {
            return $this->payResponse($request, $order, null, $result['url']);
        }

        return $this->payResponse($request, $order->refresh());
    }

    /**
     * Polling status pembayaran (popup / halaman dashboard).
     */
    public function status($orderId)
    {
        $order = $this->ownedOrder($orderId);

        // Batasi panggilan Inquiry Status ke Espay: maks 1x / 10 detik per order
        if (Cache::add('espay.status_poll.' . $order->id, 1, 10)) {
            $order = app(EspayPaymentService::class)->syncStatus($order);
        }

        return response()->json([
            'status' => $order->status,
            'redirect' => $order->status === Order::$pending ? null : self::statusUrl($order),
        ]);
    }

    /**
     * Pembayaran (trxId / paymentRef) sudah tercatat lewat Check Payment Status, bukan notifikasi Espay?
     */
    private static function processedByFallback(array $body): bool
    {
        $keys = array_values(array_unique(array_filter([
            trim((string) ($body['trxId'] ?? '')),
            trim((string) data_get($body, 'additionalInfo.paymentRef', '')),
        ])));

        if (empty($keys)) {
            return false;
        }

        $row = \Illuminate\Support\Facades\DB::table('espay_va_payments')
            ->where(fn ($query) => $query->whereIn('trx_id', $keys)->orWhereIn('payment_ref', $keys))
            ->first();

        return !empty($row) and str_contains((string) $row->data, '"check_status"');
    }

    /**
     * Halaman status pembayaran di dashboard.
     */
    public static function statusUrl(Order $order): string
    {
        return url('/panel/financial/payment-status?order_id=' . $order->id);
    }

    private function checkoutData(Order $order, ?string $errorMessage = null): array
    {
        $client = new EspayClient();
        $methods = ['va' => [], 'qris' => null];

        try {
            $methods = EspayPaymentMethods::fromMerchantInfo($client->merchantInfo());
        } catch (\Throwable $e) {
            Log::warning('[Espay] merchantinfo unavailable on checkout: ' . $e->getMessage());
        }

        $paymentData = EspayPaymentService::paymentData($order);
        $bankCode = $paymentData['bank_code'] ?? null;

        return [
            'order' => $order,
            'amount' => EspayPaymentService::amountForOrder($order),
            'paymentId' => $order->reference_id,
            'methods' => $methods,
            'qris' => !empty($paymentData['product_code']) ? ($paymentData['qris'] ?? null) : null,
            'va' => !empty($paymentData['va_number']) ? [
                'number' => $paymentData['va_number'],
                'bank_code' => $bankCode,
                'bank_name' => EspayPaymentMethods::bankName($bankCode, $paymentData['product_name'] ?? null),
                'logo' => EspayPaymentMethods::bankLogo($bankCode),
            ] : null,
            'selectedProduct' => $paymentData['product_code'] ?? null,
            'doneUrl' => self::statusUrl($order),
            'errorMessage' => $errorMessage,
            'isProduction' => $client->isProduction(),
        ];
    }

    private function payResponse(Request $request, Order $order, ?string $error = null, ?string $redirect = null)
    {
        if ($request->ajax()) {
            if (!empty($redirect)) {
                return response()->json(['redirect' => $redirect]);
            }

            return response()->json([
                'status' => empty($error) ? 'ok' : 'error',
                'message' => $error,
                'html' => view('web.default.cart.channels.espay_content', $this->checkoutData($order, $error))->render(),
            ], empty($error) ? 200 : 422);
        }

        if (!empty($redirect)) {
            return redirect()->away($redirect);
        }

        $response = redirect()->route('espay.checkout', ['order' => $order->id]);

        return empty($error) ? $response : $response->with(['toast' => ['title' => trans('cart.fail_purchase'), 'msg' => $error, 'status' => 'error']]);
    }

    /**
     * SNAP Inquiry (service 24) - Espay -> Merchant.
     */
    public function inquiry(Request $request)
    {
        try {
            $body = $this->validateIncoming($request, self::SERVICE_INQUIRY, [
                'partnerServiceId', 'customerNo', 'virtualAccountNo', 'trxDateInit', 'inquiryRequestId',
            ]);

            $paymentId = trim((string) $body['virtualAccountNo']);
            $order = $this->findPayableOrder($body, self::SERVICE_INQUIRY);

            EspayPaymentService::updatePaymentData($order, ['inquiry_request_id' => (string) $body['inquiryRequestId']]);

            $user = $order->user;
            $amount = ['value' => EspayPaymentService::formatAmount(EspayPaymentService::amountForOrder($order)), 'currency' => 'IDR'];

            return response()->json([
                'responseCode' => '200' . self::SERVICE_INQUIRY . '00',
                'responseMessage' => 'Successful',
                'virtualAccountData' => [
                    'partnerServiceId' => $body['partnerServiceId'],
                    'customerNo' => $body['customerNo'],
                    'virtualAccountNo' => $paymentId,
                    'virtualAccountName' => EspayPaymentService::ascii($user->full_name ?? 'Customer', 255),
                    'virtualAccountEmail' => mb_substr((string) ($user->email ?? ''), 0, 255),
                    'virtualAccountPhone' => EspayPaymentService::phone($user->mobile ?? '', 30),
                    'inquiryRequestId' => $body['inquiryRequestId'],
                    'totalAmount' => $amount,
                    'billDetails' => [
                        ['billDescription' => EspayPaymentService::billDescription($order)],
                    ],
                    'additionalInfo' => [
                        'transactionDate' => EspayClient::timestamp(now()->setTimestamp((int) ($order->created_at ?: time()))),
                    ],
                ],
            ], 200, [], JSON_UNESCAPED_SLASHES);
        } catch (EspayException $e) {
            return $this->errorResponse($request, $e, self::SERVICE_INQUIRY);
        } catch (\Throwable $e) {
            Log::error('[Espay] inquiry failed', ['message' => $e->getMessage()]);

            return $this->errorResponse($request, new EspayException(500, self::SERVICE_INQUIRY, '00', 'General Error'), self::SERVICE_INQUIRY);
        }
    }

    /**
     * SNAP Payment (service 25) - Espay -> Merchant: notifikasi pembayaran berhasil.
     */
    public function payment(Request $request)
    {
        // Payment Notification non-SNAP (form: rq_uuid, password, signature, payment_ref, ...) ke URL yang sama
        if (!$request->isJson() and $request->filled('payment_ref') and $request->filled('rq_uuid')) {
            return app(EspayNotificationController::class)($request);
        }

        try {
            $body = $this->validateIncoming($request, self::SERVICE_PAYMENT, [
                'partnerServiceId', 'customerNo', 'virtualAccountNo', 'trxId', 'paymentRequestId',
                'paidAmount.value', 'paidAmount.currency', 'totalAmount.value', 'totalAmount.currency', 'trxDateTime',
            ]);

            foreach (['paidAmount.value', 'totalAmount.value'] as $field) {
                if (!preg_match('/^\d{1,13}\.\d{2}$/', (string) data_get($body, $field))) {
                    throw new EspayException(400, self::SERVICE_PAYMENT, '01', "Invalid Field Format {{$field}}");
                }
            }

            $paymentId = trim((string) $body['virtualAccountNo']);
            $transactionStatus = strtoupper((string) data_get($body, 'additionalInfo.transactionStatus', 'S'));
            $reconcileId = mb_substr(preg_replace('/[^A-Za-z0-9]/', '', 'R' . $paymentId . now()->format('YmdHis')), 0, 32);
            $callbackData = [
                'source' => 'payment',
                'trx_id' => $body['trxId'],
                'payment_request_id' => $body['paymentRequestId'],
                'paid_amount' => data_get($body, 'paidAmount.value'),
                'total_amount' => data_get($body, 'totalAmount.value'),
                'transaction_status' => $transactionStatus,
                'product_code' => data_get($body, 'additionalInfo.productCode'),
                'payment_ref' => data_get($body, 'additionalInfo.paymentRef'),
                'trx_date_time' => $body['trxDateTime'],
                'reconcile_id' => $reconcileId,
            ];

            // VA Static Open: nomor VA tetap milik user -> lunasi order dengan nominal sama, selain itu top up saldo
            $staticVa = EspayStaticVaService::findVa($body);
            $customerName = null;

            if (!empty($staticVa)) {
                $order = null;

                if ($transactionStatus === 'S') {
                    try {
                        $order = app(EspayStaticVaService::class)->handlePayment($staticVa, $body, $callbackData)['order'];
                    } catch (EspayException $e) {
                        // Notifikasi ganda -> 4042514 Paid Bill (UAT: double payment ditolak).
                        // Kecuali pembayaran ini sebelumnya diproses oleh cadangan Check Payment Status:
                        // notifikasi Espay untuk pembayaran tsb tetap dijawab sukses agar tidak menjadi Suspect.
                        if ($e->responseCode !== '404' . self::SERVICE_PAYMENT . '14' or !self::processedByFallback($body)) {
                            throw $e;
                        }
                    }
                }
                $customerName = \App\User::query()->whereKey($staticVa->user_id)->value('full_name');
            } elseif ($transactionStatus === 'S') {
                // totalAmount = nominal tagihan (paidAmount bisa termasuk fee pembeli)
                try {
                    $order = app(EspayPaymentService::class)->settle($paymentId, $callbackData, (float) data_get($body, 'totalAmount.value'));
                } catch (EspayException $e) {
                    // QRIS dibayar untuk order yang sudah lunas / sudah diganti metodenya -> jadikan saldo, jangan ditolak
                    $paidOrder = EspayPaymentService::findOrderByPaymentId($paymentId);

                    if (empty($paidOrder) or empty($paidOrder->user) or !in_array($e->responseCode, ['404' . self::SERVICE_PAYMENT . '14', '404' . self::SERVICE_PAYMENT . '19'], true)) {
                        throw $e;
                    }

                    try {
                        $order = app(EspayStaticVaService::class)->creditAsTopup(
                            $paidOrder->user,
                            (float) data_get($body, 'paidAmount.value'),
                            (string) $body['trxId'],
                            (string) data_get($body, 'additionalInfo.paymentRef', ''),
                            $callbackData
                        );
                    } catch (EspayException $duplicate) {
                        // notifikasi ulang untuk transaksi yang sudah dijadikan saldo
                        $order = $paidOrder;
                    }
                }
            } else {
                $order = $this->findPayableOrder($body, self::SERVICE_PAYMENT);
                EspayPaymentService::updatePaymentData($order, ['last_status' => $callbackData]);

                if ($transactionStatus === 'F') {
                    app(EspayPaymentService::class)->markFailed($order, $callbackData);
                }
            }

            return response()->json([
                'responseCode' => '200' . self::SERVICE_PAYMENT . '00',
                'responseMessage' => 'Successful',
                'virtualAccountData' => [
                    'partnerServiceId' => $body['partnerServiceId'],
                    'customerNo' => $body['customerNo'],
                    'virtualAccountNo' => $paymentId,
                    'virtualAccountName' => EspayPaymentService::ascii($order?->user?->full_name ?? $customerName ?? 'Customer', 255),
                    'paymentRequestId' => $body['paymentRequestId'],
                    'totalAmount' => [
                        'value' => data_get($body, 'totalAmount.value'),
                        'currency' => data_get($body, 'totalAmount.currency'),
                    ],
                    'billDetails' => [
                        ['billDescription' => !empty($order) ? EspayPaymentService::billDescription($order) : ['english' => 'Top up', 'indonesia' => 'Top up']],
                    ],
                ],
                'additionalInfo' => [
                    'reconcileId' => $reconcileId,
                    'reconcileDatetime' => EspayClient::timestamp(),
                ],
            ], 200, [], JSON_UNESCAPED_SLASHES);
        } catch (EspayException $e) {
            return $this->errorResponse($request, $e, self::SERVICE_PAYMENT);
        } catch (\Throwable $e) {
            Log::error('[Espay] payment failed', ['message' => $e->getMessage()]);

            return $this->errorResponse($request, new EspayException(500, self::SERVICE_PAYMENT, '00', 'General Error'), self::SERVICE_PAYMENT);
        }
    }

    /**
     * Validasi header, signature, X-EXTERNAL-ID & field wajib. Mengembalikan body (array).
     *
     * @throws EspayException
     */
    private function validateIncoming(Request $request, string $service, array $mandatoryFields): array
    {
        $body = SnapRequestValidator::validate($request, $service, $mandatoryFields, [(new EspayClient())->merchantCode()]);

        if (!preg_match('/^[A-Za-z0-9]{1,28}$/', trim((string) $body['virtualAccountNo']))) {
            throw new EspayException(400, $service, '01', 'Invalid Field Format {virtualAccountNo}');
        }

        return $body;
    }

    /**
     * @throws EspayException
     */
    private function findPayableOrder(array $body, string $service): Order
    {
        $client = new EspayClient();
        $paymentId = trim((string) $body['virtualAccountNo']);

        if (strcasecmp(trim((string) $body['customerNo']), (string) $client->merchantCode()) !== 0) {
            throw new EspayException(404, $service, '12', 'Invalid Bill/Virtual Account [Not Found]');
        }

        $order = EspayPaymentService::findOrderByPaymentId($paymentId);

        if (empty($order)) {
            throw new EspayException(404, $service, '12', 'Invalid Bill/Virtual Account [Not Found]');
        }

        if ($order->status === Order::$paid) {
            throw new EspayException(404, $service, '14', 'Paid Bill');
        }

        // Nomor transaksi lama (sudah diganti metode lain) atau order gagal/expired
        if ($order->reference_id !== $paymentId or $order->status === Order::$fail) {
            throw new EspayException(404, $service, '19', 'Invalid Bill/Virtual Account');
        }

        return $order;
    }

    private function errorResponse(Request $request, EspayException $e, string $service)
    {
        Log::warning('[Espay] SNAP request rejected', [
            'service' => $service,
            'response_code' => $e->responseCode,
            'message' => $e->getMessage(),
            'external_id' => $request->header('X-EXTERNAL-ID'),
            'virtual_account_no' => $request->json('virtualAccountNo'),
            'ip' => $request->ip(),
        ]);

        return response()->json([
            'responseCode' => $e->responseCode,
            'responseMessage' => $e->getMessage(),
        ], $e->httpCode, [], JSON_UNESCAPED_SLASHES);
    }

    private function ownedOrder($orderId): Order
    {
        $order = Order::query()
            ->where('id', $orderId)
            ->where('user_id', auth()->id())
            ->firstOrFail();

        if (!EspayPaymentService::isEspayOrder($order)) {
            abort(404);
        }

        return $order;
    }
}
