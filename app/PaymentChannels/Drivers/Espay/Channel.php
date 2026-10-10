<?php

namespace App\PaymentChannels\Drivers\Espay;

use App\Models\Order;
use App\Models\PaymentChannel;
use App\PaymentChannels\BasePaymentChannel;
use App\PaymentChannels\IChannel;
use App\Services\Espay\EspayClient;
use App\Services\Espay\EspayPaymentService;
use App\Services\Espay\EspaySettings;
use Illuminate\Http\Request;
use RuntimeException;

class Channel extends BasePaymentChannel implements IChannel
{
    // Kredensial & kunci RSA diambil dari .env (config/espay.php) agar sandbox & production terpisah
    protected array $credentialItems = [];

    private string $orderSessionKey = 'espay.payments.order_id';
    private PaymentChannel $paymentChannel;

    public function __construct(PaymentChannel $paymentChannel)
    {
        $this->paymentChannel = $paymentChannel;
        $this->setCredentialItems($paymentChannel);
    }

    /**
     * Menyimpan nominal order lalu mengarahkan user ke halaman pilih metode pembayaran Espay.
     * Transaksi Espay (Payment Host to Host / QRIS) dibuat setelah user memilih bank/e-wallet.
     */
    public function paymentRequest(Order $order)
    {
        $client = new EspayClient();
        $client->assertConfigured();

        $amount = (int) round($this->makeAmountByCurrency($order->total_amount, 'IDR'));
        $minimum = (int) config('espay.minimum_amount', 10000);

        if ($amount < $minimum) {
            throw new RuntimeException('Nominal minimum pembayaran Espay adalah Rp ' . number_format($minimum, 0, ',', '.') . '.');
        }

        $paymentData = EspayPaymentService::paymentData($order);
        $isSameTransaction = ($paymentData['gateway'] ?? null) === EspayPaymentService::GATEWAY
            && (int) ($paymentData['amount'] ?? 0) === $amount;

        $order->update([
            // Selalu pending: status "paying" akan dianggap lunas oleh PaymentController::paymentOrderAfterVerify
            'status' => Order::$pending,
            'payment_data' => json_encode(array_merge($paymentData, [
                'gateway' => EspayPaymentService::GATEWAY,
                'mode' => $client->getModeLabel(),
                'amount' => $amount,
                'ccy' => 'IDR',
                'created_at' => $paymentData['created_at'] ?? time(),
            ], $isSameTransaction ? [] : ['product_code' => null, 'qris' => null])),
        ]);

        session()->put($this->orderSessionKey, $order->id);

        return route('espay.checkout', ['order' => $order->id]);
    }

    /**
     * Dipanggil Admin PaymentChannelController sebelum menyimpan form kredensial Espay.
     */
    public function prepareCredentials(array $input): array
    {
        return EspaySettings::prepareForSave($input, $this->paymentChannel->credentials);
    }

    public function verify(Request $request)
    {
        $orderId = $request->integer('order_id');

        if (empty($orderId)) {
            $orderId = (int) session()->get($this->orderSessionKey, 0);
        }

        session()->forget($this->orderSessionKey);

        if (empty($orderId) or !auth()->check()) {
            return null;
        }

        $order = Order::query()
            ->where('id', $orderId)
            ->where('user_id', auth()->id())
            ->first();

        if (empty($order)) {
            return null;
        }

        // Callback Payment biasanya sudah masuk; jika belum, cek via Inquiry Status.
        // Order lunas di-settle oleh service, controller hanya meneruskan ke halaman status.
        return app(EspayPaymentService::class)->syncStatus($order);
    }
}
