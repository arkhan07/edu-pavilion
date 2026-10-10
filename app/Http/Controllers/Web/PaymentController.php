<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Mixins\Cashback\CashbackAccounting;
use App\Models\Accounting;
use App\Models\BecomeInstructor;
use App\Models\Cart;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentChannel;
use App\Models\Product;
use App\Models\ProductOrder;
use App\Models\ReserveMeeting;
use App\Models\Reward;
use App\Models\RewardAccounting;
use App\Models\Sale;
use App\Models\TicketUser;
use App\PaymentChannels\ChannelManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;

class PaymentController extends Controller
{
    protected $order_session_key = 'payment.order_id';

    public function paymentRequest(Request $request)
    {
        $this->validate($request, [
            'gateway' => 'required'
        ]);

        $user = auth()->user();
        $gateway = $request->input('gateway');
        $orderId = $request->input('order_id');

        $order = Order::where('id', $orderId)
            ->where('user_id', $user->id)
            ->first();

        if (empty($order)) {
            abort(404);
        }

        if ($order->status === Order::$paid) {
            $toastData = [
                'title' => trans('cart.fail_purchase'),
                'msg' => 'Pesanan ini sudah dibayar sebelumnya.',
                'status' => 'error'
            ];
            return back()->with(['toast' => $toastData]);
        }

        if ($order->type === Order::$meeting) {
            $orderItem = OrderItem::where('order_id', $order->id)->first();
            $reserveMeeting = ReserveMeeting::where('id', $orderItem->reserve_meeting_id)->first();
            
            if ($reserveMeeting) {
                // Validasi akhir sebelum pembayaran
                $hasConflict = ReserveMeeting::where('meeting_time_id', $reserveMeeting->meeting_time_id)
                    ->where('day', $reserveMeeting->day)
                    ->where('id', '!=', $reserveMeeting->id)
                    ->where(function($query) {
                        $query->whereNotNull('reserved_at')
                              ->orWhere(function($subQ) {
                                  $subQ->whereNotNull('locked_at')
                                       ->where('locked_at', '>', (time() - 900));
                              });
                    })->exists();

                if ($hasConflict) {
                    $toastData = [
                        'title' => trans('public.request_failed'),
                        'msg' => 'Maaf, jadwal reservasi ini baru saja didahului oleh siswa lain yang melakukan pembayaran. Silakan pilih waktu yang lain.',
                        'status' => 'error'
                    ];
                    return redirect('/cart')->with(['toast' => $toastData]);
                }

                $reserveMeeting->update(['locked_at' => time()]);
            }
        }

        if ($gateway === 'credit') {

            if ($user->getAccountingCharge() < $order->total_amount) {
                $order->update(['status' => Order::$fail]);

                session()->put($this->order_session_key, $order->id);

                return redirect('/payments/status');
            }

            $order->update([
                'payment_method' => Order::$credit
            ]);

            $this->setPaymentAccounting($order, 'credit');

            $order->update([
                'status' => Order::$paid
            ]);

            session()->put($this->order_session_key, $order->id);

            return redirect('/payments/status');
        }

        $paymentChannel = PaymentChannel::where('id', $gateway)
            ->where('status', 'active')
            ->first();

        if (!$paymentChannel) {
            $toastData = [
                'title' => trans('cart.fail_purchase'),
                'msg' => trans('public.channel_payment_disabled'),
                'status' => 'error'
            ];
            return back()->with(['toast' => $toastData]);
        }

        $order->payment_method = Order::$paymentChannel;
        $order->save();

        // Jika gateway Flip dan ada setting biaya layanan, tambahkan ke total_amount order
        if ($paymentChannel->class_name === 'Flip') {
            $flipPercent = (float) (getFinancialSettings('flip_service_fee_percent') ?? 0);
            if ($flipPercent > 0) {
                // Cek apakah fee sudah pernah ditambahkan (hindari double)
                $existingData = !empty($order->payment_data) ? json_decode((string) $order->payment_data, true) : [];
                $alreadyApplied = isset($existingData['flip_service_fee']) && isset($existingData['flip_service_fee_percent']) && (float) $existingData['flip_service_fee_percent'] === $flipPercent;
                if (!$alreadyApplied) {
                    $base = (float) $order->amount - (float) $order->total_discount;
                    if ($base < 0) $base = 0;
                    $fee = round($base * $flipPercent / 100, 2);
                    if ($fee > 0) {
                        $newTotal = (float) $order->total_amount + $fee;
                        $order->update(['total_amount' => $newTotal]);
                        // simpan fee ke payment_data untuk audit & agar tidak double
                        $existingData['flip_service_fee'] = $fee;
                        $existingData['flip_service_fee_percent'] = $flipPercent;
                        $existingData['flip_service_fee_base'] = $base;
                        $order->update(['payment_data' => json_encode($existingData)]);
                        // refresh
                        $order->refresh();
                    }
                }
            }
        }

        try {
            $channelManager = ChannelManager::makeChannel($paymentChannel);
            $redirect_url = $channelManager->paymentRequest($order);

            if (in_array($paymentChannel->class_name, PaymentChannel::$gatewayIgnoreRedirect)) {
                return $redirect_url;
            }



            if (\request()->ajax()) {
                return response()->json(array_merge([
                    'status' => 'success',
                    'redirect_url' => $redirect_url
                ], $paymentChannel->class_name === 'Espay' ? [
                    // Espay: pilih VA / QRIS di popup, tanpa pindah halaman
                    'popup' => 'espay',
                    'checkout_url' => $redirect_url,
                ] : []));
            }

            return Redirect::away($redirect_url);

        } catch (\Exception $exception) {
            $rawMsg = $exception->getMessage();
            // Pesan user-friendly untuk kasus yang bisa di-action (minimum Flip, dll)
            $userMsg = 'Terjadi kesalahan sistem. Silakan coba lagi nanti atau hubungi tim bantuan kami.';
            if (str_contains($rawMsg, 'minimum Flip') || str_contains($rawMsg, 'Minimum') || str_contains($rawMsg, 'Rp 10.000')) {
                $userMsg = $rawMsg;
            } elseif (str_contains($rawMsg, 'Nominal minimum pembayaran Espay')) {
                $userMsg = $rawMsg;
            } elseif (str_contains($rawMsg, 'Flip') && mb_strlen($rawMsg) < 200) {
                $userMsg = $rawMsg;
            }
            \Illuminate\Support\Facades\Log::warning('[Payment] paymentRequest failed', ['gateway' => $paymentChannel->class_name ?? $gateway, 'order_id' => $order->id ?? null, 'error' => $rawMsg]);

            if (\request()->ajax()) {
                return response()->json([
                    'status' => 'error',
                    'message' => $userMsg,
                    'technical_error' => 'Koneksi ke server Flip gagal. Pastikan API Key / konfigurasi sudah benar.',
                    'exception' => $rawMsg
                ], 500);
            }

            $toastData = [
                'title' => trans('cart.fail_purchase'),
                'msg' => $userMsg,
                'status' => 'error'
            ];
            return back()->with(['toast' => $toastData]);
        }
    }

    public function paymentVerify(Request $request, $gateway)
    {
        $paymentChannel = PaymentChannel::where('class_name', $gateway)
            ->where('status', 'active')
            ->first();

        try {
            $channelManager = ChannelManager::makeChannel($paymentChannel);
            $order = $channelManager->verify($request);

            return $this->paymentOrderAfterVerify($order);

        } catch (\Exception $exception) {
            $toastData = [
                'title' => trans('cart.fail_purchase'),
                'msg' => trans('cart.gateway_error'),
                'status' => 'error'
            ];
            return redirect('cart')->with(['toast' => $toastData]);
        }
    }

    /*
     * | this methode only run for payku.result
     * */
    public function paykuPaymentVerify(Request $request, $id)
    {
        $paymentChannel = PaymentChannel::where('class_name', PaymentChannel::$payku)
            ->where('status', 'active')
            ->first();

        try {
            $channelManager = ChannelManager::makeChannel($paymentChannel);

            $request->request->add(['transaction_id' => $id]);

            $order = $channelManager->verify($request);

            return $this->paymentOrderAfterVerify($order);

        } catch (\Exception $exception) {
            $toastData = [
                'title' => trans('cart.fail_purchase'),
                'msg' => trans('cart.gateway_error'),
                'status' => 'error'
            ];
            return redirect('cart')->with(['toast' => $toastData]);
        }
    }

    private function paymentOrderAfterVerify($order)
    {
        if (!empty($order)) {

            if ($order->status == Order::$paying) {
                $this->setPaymentAccounting($order);

                $order->update(['status' => Order::$paid]);
            } elseif ($order->status == Order::$fail) {
                if ($order->type === Order::$meeting) {
                    $orderItem = OrderItem::where('order_id', $order->id)->first();

                    if ($orderItem && $orderItem->reserve_meeting_id) {
                        $reserveMeeting = ReserveMeeting::where('id', $orderItem->reserve_meeting_id)->first();

                        if ($reserveMeeting) {
                            $reserveMeeting->update(['locked_at' => null]);
                        }
                    }
                }
            }

            session()->put($this->order_session_key, $order->id);

            return redirect('/payments/status');
        } else {
            $toastData = [
                'title' => trans('cart.fail_purchase'),
                'msg' => trans('cart.gateway_error'),
                'status' => 'error'
            ];

            return redirect('cart')->with($toastData);
        }
    }

    public function setPaymentAccounting($order, $type = null)
    {
        $cashbackAccounting = new CashbackAccounting($order->user);

        if ($order->is_charge_account) {
            Accounting::charge($order);

            $cashbackAccounting->rechargeWallet($order);
        } else {
            foreach ($order->orderItems as $orderItem) {
                $sale = Sale::createSales($orderItem, $order->payment_method);

                if (!empty($orderItem->reserve_meeting_id)) {
                    $reserveMeeting = ReserveMeeting::where('id', $orderItem->reserve_meeting_id)->first();
                    $reserveMeeting->update([
                        'sale_id' => $sale->id,
                        'reserved_at' => time(),
                        'paid_amount' => $sale->total_amount,
                    ]);

                    $reserver = $reserveMeeting->user;

                    if ($reserver) {
                        $this->handleMeetingReserveReward($reserver);
                    }
                }

                if (!empty($orderItem->gift_id)) {
                    $gift = $orderItem->gift;

                    $gift->update([
                        'status' => 'active'
                    ]);

                    $gift->sendNotificationsWhenActivated($orderItem->total_amount);
                }

                if (!empty($orderItem->subscribe_id)) {
                    Accounting::createAccountingForSubscribe($orderItem, $type);
                } elseif (!empty($orderItem->promotion_id)) {
                    Accounting::createAccountingForPromotion($orderItem, $type);
                } elseif (!empty($orderItem->registration_package_id)) {
                    Accounting::createAccountingForRegistrationPackage($orderItem, $type);

                    if (!empty($orderItem->become_instructor_id)) {
                        BecomeInstructor::where('id', $orderItem->become_instructor_id)
                            ->update([
                                'package_id' => $orderItem->registration_package_id
                            ]);
                    }
                } elseif (!empty($orderItem->installment_payment_id)) {
                    Accounting::createAccountingForInstallmentPayment($orderItem, $type);

                    $this->updateInstallmentOrder($orderItem, $sale);
                } else {
                    // webinar and meeting and product and bundle

                    Accounting::createAccounting($orderItem, $type);
                    TicketUser::useTicket($orderItem);

                    if (!empty($orderItem->product_id)) {
                        $this->updateProductOrder($sale, $orderItem);
                    }
                }
            }

            // Set Cashback Accounting For All Order Items
            $cashbackAccounting->setAccountingForOrderItems($order->orderItems);
        }

        Cart::emptyCart($order->user_id);
    }

    public function payStatus(Request $request)
    {
        $orderId = $request->get('order_id', null);

        if (!empty(session()->get($this->order_session_key, null))) {
            $orderId = session()->get($this->order_session_key, null);
            session()->forget($this->order_session_key);
        }

        $order = Order::where('id', $orderId)
            ->where('user_id', auth()->id())
            ->first();

        // Auto-sync Flip bill status saat halaman status dibuka (fallback jika webhook belum masuk)
        if (!empty($order) && in_array($order->status, [Order::$pending, Order::$paying], true) && !empty($order->reference_id)) {
            try {
                $pd = json_decode((string)$order->payment_data, true) ?: [];
                if (($pd['gateway'] ?? null) === 'Flip') {
                    $client = new \App\Services\Flip\FlipClient();
                    $bill = $client->getBill($order->reference_id);
                    $status = $bill['status'] ?? null;
                    $normStatus = strtoupper(trim((string)$status));
                    $isSuccess = in_array($normStatus, ['SUCCESSFUL','SUCCESS','BERHASIL','PAID','COMPLETED'], true) || str_contains($normStatus,'SUCCESS') || str_contains($normStatus,'BERHASIL');
                    if ($isSuccess) {
                        $webhookRequest = \Illuminate\Http\Request::create('/payments/flip/callback','POST',[],[],[],['CONTENT_TYPE'=>'application/json'], json_encode(['data'=>$bill]));
                        app(\App\Http\Controllers\FlipWebhookController::class)->handle($webhookRequest);
                        $order = Order::where('id', $orderId)->where('user_id', auth()->id())->first();
                    }
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('[Flip] payStatus auto-sync failed: '. $e->getMessage(), ['order_id'=>$orderId]);
            }
        }

        // Auto-sync status Espay (fallback jika payment notification belum masuk)
        if (!empty($order) and \App\Services\Espay\EspayPaymentService::isEspayOrder($order)) {
            $order = app(\App\Services\Espay\EspayPaymentService::class)->syncStatus($order);
        }

        if (!empty($order)) {
            $data = [
                'pageTitle' => trans('public.cart_page_title'),
                'order' => $order,
            ];

            return view('web.default.cart.status_pay', $data);
        }

        return redirect('/panel');
    }

    private function handleMeetingReserveReward($user)
    {
        if ($user->isUser()) {
            $type = Reward::STUDENT_MEETING_RESERVE;
        } else {
            $type = Reward::INSTRUCTOR_MEETING_RESERVE;
        }

        $meetingReserveReward = RewardAccounting::calculateScore($type);

        RewardAccounting::makeRewardAccounting($user->id, $meetingReserveReward, $type);
    }

    private function updateProductOrder($sale, $orderItem)
    {
        $product = $orderItem->product;

        $status = ProductOrder::$waitingDelivery;

        if ($product and $product->isVirtual()) {
            $status = ProductOrder::$success;
        }

        ProductOrder::where('product_id', $orderItem->product_id)
            ->where(function ($query) use ($orderItem) {
                $query->where(function ($query) use ($orderItem) {
                    $query->whereNotNull('buyer_id');
                    $query->where('buyer_id', $orderItem->user_id);
                });

                $query->orWhere(function ($query) use ($orderItem) {
                    $query->whereNotNull('gift_id');
                    $query->where('gift_id', $orderItem->gift_id);
                });
            })
            ->update([
                'sale_id' => $sale->id,
                'status' => $status,
            ]);

        if ($product and $product->getAvailability() < 1) {
            $notifyOptions = [
                '[p.title]' => $product->title,
            ];
            sendNotification('product_out_of_stock', $notifyOptions, $product->creator_id);
        }
    }

    private function updateInstallmentOrder($orderItem, $sale)
    {
        $installmentPayment = $orderItem->installmentPayment;

        if (!empty($installmentPayment)) {
            $installmentOrder = $installmentPayment->installmentOrder;

            $installmentPayment->update([
                'sale_id' => $sale->id,
                'status' => 'paid',
            ]);

            /* Notification Options */
            $notifyOptions = [
                '[u.name]' => $installmentOrder->user->full_name,
                '[installment_title]' => $installmentOrder->installment->main_title,
                '[time.date]' => dateTimeFormat(time(), 'j M Y - H:i'),
                '[amount]' => handlePrice($installmentPayment->amount),
            ];

            if ($installmentOrder and $installmentOrder->status == 'paying' and $installmentPayment->type == 'upfront') {
                $installment = $installmentOrder->installment;

                if ($installment) {
                    if ($installment->needToVerify()) {
                        $status = 'pending_verification';

                        sendNotification("installment_verification_request_sent", $notifyOptions, $installmentOrder->user_id);
                        sendNotification("admin_installment_verification_request_sent", $notifyOptions, 1); // Admin
                    } else {
                        $status = 'open';

                        sendNotification("paid_installment_upfront", $notifyOptions, $installmentOrder->user_id);
                    }

                    $installmentOrder->update([
                        'status' => $status
                    ]);

                    if ($status == 'open' and !empty($installmentOrder->product_id) and !empty($installmentOrder->product_order_id)) {
                        $productOrder = ProductOrder::query()->where('installment_order_id', $installmentOrder->id)
                            ->where('id', $installmentOrder->product_order_id)
                            ->first();

                        $product = Product::query()->where('id', $installmentOrder->product_id)->first();

                        if (!empty($product) and !empty($productOrder)) {
                            $productOrderStatus = ProductOrder::$waitingDelivery;

                            if ($product->isVirtual()) {
                                $productOrderStatus = ProductOrder::$success;
                            }

                            $productOrder->update([
                                'status' => $productOrderStatus
                            ]);
                        }
                    }
                }
            }


            if ($installmentPayment->type == 'step') {
                sendNotification("paid_installment_step", $notifyOptions, $installmentOrder->user_id);
                sendNotification("paid_installment_step_for_admin", $notifyOptions, 1); // For Admin
            }

        }
    }

}
