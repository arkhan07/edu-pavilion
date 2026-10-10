<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Web\PaymentController;
use App\Mixins\Cashback\CashbackRules;
use App\Models\Accounting;
use App\Models\OfflineBank;
use App\Models\OfflinePayment;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentChannel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Facades\Image;

class AccountingController extends Controller
{
    public function index()
    {
        $this->authorize("panel_financial_summary");

        $userAuth = auth()->user();

        $accountings = Accounting::where('user_id', $userAuth->id)
            ->where('system', false)
            ->where('tax', false)
            ->with([
                'webinar',
                'promotion',
                'subscribe',
                'meetingTime' => function ($query) {
                    $query->with(['meeting' => function ($query) {
                        $query->with(['creator' => function ($query) {
                            $query->select('id', 'full_name');
                        }]);
                    }]);
                }
            ])
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->paginate(10);

        $data = [
            'pageTitle' => trans('financial.summary_page_title'),
            'accountings' => $accountings,
            'payments' => $this->gatewayPayments($userAuth),
            'commission' => getFinancialSettings('commission') ?? 0,
        ];

        return view(getTemplate() . '.panel.financial.summary', $data);
    }

    /**
     * Semua transaksi payment gateway user (top up & pembelian) beserta statusnya,
     * termasuk yang belum dibayar / gagal. VA Espay yang masih menunggu dicek dulu ke Espay.
     */
    private function gatewayPayments($user)
    {
        $query = Order::query()
            ->where('user_id', $user->id)
            ->where('payment_method', Order::$paymentChannel)
            ->where('payment_data', 'like', '%"gateway":%');

        $pendingVaOrders = (clone $query)
            ->whereIn('status', [Order::$pending, Order::$paying])
            ->where('payment_data', 'like', '%"va_number":"%')
            ->orderByDesc('id')
            ->limit(20)
            ->get();

        $checked = [];
        foreach ($pendingVaOrders as $order) {
            $va = \App\Services\Espay\EspayStaticVaService::vaForOrder($order);

            if (!empty($va) and !isset($checked[$va->id]) and count($checked) < 3) {
                $checked[$va->id] = true;
                app(\App\Services\Espay\EspayStaticVaService::class)->syncFromEspay($va);
            }
        }

        return $query->with(['orderItems' => function ($query) {
                $query->with(['webinar', 'bundle', 'product', 'subscribe']);
            }])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(10, ['*'], 'payments_page');
    }

    /**
     * Status pembayaran gateway (Espay) di dalam dashboard.
     */
    public function paymentStatus(Request $request)
    {
        $order = Order::query()
            ->where('id', $request->integer('order_id'))
            ->where('user_id', auth()->id())
            ->first();

        if (empty($order)) {
            return redirect('/panel/financial/account');
        }

        if (\App\Services\Espay\EspayPaymentService::isEspayOrder($order)) {
            $order = app(\App\Services\Espay\EspayPaymentService::class)->syncStatus($order);
        }

        return view(getTemplate() . '.panel.financial.payment_receipt', [
            'pageTitle' => $order->status === Order::$paid ? 'Bukti Pembayaran' : 'Detail Pembayaran',
            'receipt' => \App\Services\PaymentReceipt::make($order),
        ]);
    }

    /**
     * Unduh invoice / bukti pembayaran (PDF).
     */
    public function invoiceDownload($orderId)
    {
        $order = Order::query()
            ->where('id', $orderId)
            ->where('user_id', auth()->id())
            ->firstOrFail();

        $receipt = \App\Services\PaymentReceipt::make($order);

        // logo untuk dompdf harus path lokal
        $logo = (string) ($receipt['merchant']['logo'] ?? '');
        $logoPath = ($logo !== '' and !str_starts_with($logo, 'http') and is_file(public_path(ltrim($logo, '/')))) ? public_path(ltrim($logo, '/')) : null;

        $fileName = str_replace('/', '-', $receipt['number']) . '.pdf';

        return \Barryvdh\DomPDF\Facade\Pdf::loadView(getTemplate() . '.panel.financial.payment_receipt_pdf', ['r' => $receipt, 'logoPath' => $logoPath])
            ->setPaper('a4')
            ->download($fileName);
    }

    public function account($id = null)
    {
        $this->authorize("panel_financial_charge_account");

        $userAuth = auth()->user();

        $editOfflinePayment = null;
        if (!empty($id)) {
            $editOfflinePayment = OfflinePayment::where('id', $id)
                ->where('user_id', $userAuth->id)
                ->first();
        }


        $paymentChannels = PaymentChannel::where('status', 'active')->get();
        $offlinePayments = OfflinePayment::where('user_id', $userAuth->id)->orderBy('created_at', 'desc')->get();

        $offlineBanks = OfflineBank::query()
            ->orderBy('created_at', 'desc')
            ->with([
                'specifications'
            ])
            ->get();

        $razorpay = false;
        foreach ($paymentChannels as $paymentChannel) {
            if ($paymentChannel->class_name == 'Razorpay') {
                $razorpay = true;
            }
        }

        $registrationBonusAmount = null;

        if ($userAuth->enable_registration_bonus) {
            $registrationBonusSettings = getRegistrationBonusSettings();

            $registrationBonusAccounting = Accounting::query()
                ->where('user_id', $userAuth->id)
                ->where('is_registration_bonus', true)
                ->where('system', false)
                ->first();

            $registrationBonusAmount = (empty($registrationBonusAccounting) and !empty($registrationBonusSettings['status']) and !empty($registrationBonusSettings['registration_bonus_amount'])) ? $registrationBonusSettings['registration_bonus_amount'] : null;
        }

        /* Cashback Rules */
        if (getFeaturesSettings('cashback_active') and !$userAuth->disable_cashback) {
            $cashbackRulesMixin = new CashbackRules($userAuth);
            $cashbackRules = $cashbackRulesMixin->getRules('recharge_wallet');
        }

        $data = [
            'pageTitle' => trans('financial.charge_account_page_title'),
            'offlinePayments' => $offlinePayments,
            'paymentChannels' => $paymentChannels,
            'offlineBanks' => $offlineBanks,
            'accountCharge' => $userAuth->getAccountingCharge(),
            'readyPayout' => $userAuth->getPayout(),
            'totalIncome' => $userAuth->getIncome(),
            'editOfflinePayment' => $editOfflinePayment,
            'razorpay' => $razorpay,
            'registrationBonusAmount' => $registrationBonusAmount,
            'cashbackRules' => $cashbackRules ?? null,
        ];

        return view('web.default.panel.financial.account', $data);
    }

    public function charge(Request $request)
    {
        $this->authorize("panel_financial_charge_account");

        $rules = [
            'amount' => 'required|numeric|min:0',
            'gateway' => 'required',
            'account' => 'required_if:gateway,offline',
            'referral_code' => 'required_if:gateway,offline',
            'date' => 'required_if:gateway,offline',
        ];

        if (!empty($request->file('attachment'))) {
            $rules['attachment'] = 'image|mimes:jpeg,png,jpg|max:10240';
        }

        $this->validate($request, $rules);

        $gateway = $request->input('gateway');
        $amount = $request->input('amount');
        $account = $request->input('account');
        $referenceNumber = $request->input('referral_code');
        $date = $request->input('date');

        if ($amount <= 0) {
            return back()->withErrors([
                'amount' => trans('update.the_amount_must_be_greater_than_0')
            ]);
        }

        $amount = convertPriceToDefaultCurrency($amount);
        $userAuth = auth()->user();

        if ($gateway === 'offline') {

            $attachment = null;

            if (!empty($request->file('attachment'))) {
                $attachment = $this->handleUploadAttachment($userAuth, $request->file('attachment'));
            }

            $date = convertTimeToUTCzone($date, getTimezone());

            OfflinePayment::create([
                'user_id' => $userAuth->id,
                'amount' => $amount,
                'offline_bank_id' => $account,
                'reference_number' => $referenceNumber,
                'status' => OfflinePayment::$waiting,
                'pay_date' => $date->getTimestamp(),
                'attachment' => $attachment,
                'created_at' => time(),
            ]);

            $notifyOptions = [
                '[amount]' => handlePrice($amount),
                '[u.name]' => $userAuth->full_name
            ];
            sendNotification('offline_payment_request', $notifyOptions, $userAuth->id);
            sendNotification('new_offline_payment_request', $notifyOptions, 1);

            $sweetAlertData = [
                'msg' => trans('financial.offline_payment_request_success_store'),
                'status' => 'success'
            ];
            return back()->with(['sweetalert' => $sweetAlertData]);
        }

        $paymentChannel = PaymentChannel::where('class_name', $gateway)->where('status', 'active')->first();

        if (!$paymentChannel) {
            $toastData = [
                'title' => trans('public.request_failed'),
                'msg' => trans('public.payment_dont_access'),
                'status' => 'error'
            ];
            return back()->with(['toast' => $toastData]);
        }

        $order = Order::create([
            'user_id' => $userAuth->id,
            'status' => Order::$pending,
            'payment_method' => Order::$paymentChannel,
            'is_charge_account' => true,
            'total_amount' => $amount,
            'amount' => $amount,
            'created_at' => time(),
            'type' => Order::$charge,
        ]);

        OrderItem::updateOrCreate([
            'user_id' => $userAuth->id,
            'order_id' => $order->id,
        ], [
            'amount' => $amount,
            'total_amount' => $amount,
            'tax' => 0,
            'tax_price' => 0,
            'commission' => 0,
            'commission_price' => 0,
            'created_at' => time(),
        ]);


        if ($paymentChannel->class_name == 'Razorpay') {
            return $this->echoRozerpayForm($order);
        } else {
            $paymentController = new PaymentController();

            $paymentRequest = new Request();
            $paymentRequest->merge([
                'gateway' => $paymentChannel->id,
                'order_id' => $order->id
            ]);

            return $paymentController->paymentRequest($paymentRequest);
        }
    }

    private function handleUploadAttachment($user, $file)
    {
        // Validasi keamanan file attachment
        if (!$file->isValid()) {
            Log::warning('OFFLINE_PAYMENT_SECURITY: Invalid file', [
                'user_id' => $user->id,
                'ip' => request()->ip()
            ]);
            abort(422, 'File tidak valid.');
        }

        $extension = strtolower($file->getClientOriginalExtension());
        $filename = $file->getClientOriginalName();
        $mimeType = $file->getMimeType();
        $filePath = $file->getRealPath();

        // Hanya izinkan image untuk payment attachment
        $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'pdf'];
        if (!in_array($extension, $allowedExtensions)) {
            Log::warning('OFFLINE_PAYMENT_SECURITY: Invalid extension', [
                'user_id' => $user->id,
                'filename' => $filename,
                'extension' => $extension,
                'ip' => request()->ip()
            ]);
            abort(422, 'Hanya file gambar (JPG, PNG, GIF, WebP, BMP) atau PDF yang diizinkan untuk bukti pembayaran.');
        }

        // Cek script berbahaya
        if (FileUploadHelper::isExecutableOrScript($filePath)) {
            Log::alert('OFFLINE_PAYMENT_SECURITY: Malicious script detected', [
                'user_id' => $user->id,
                'filename' => $filename,
                'ip' => request()->ip()
            ]);
            abort(422, 'File mengandung kode berbahaya.');
        }

        // Scan malware
        if (FileUploadHelper::scanForMalware($filePath)) {
            Log::alert('OFFLINE_PAYMENT_SECURITY: Malware detected', [
                'user_id' => $user->id,
                'filename' => $filename,
                'ip' => request()->ip()
            ]);
            abort(422, 'File terdeteksi mengandung malware.');
        }

        $storage = Storage::disk('public');

        $path = '/' . $user->id . '/offlinePayments';

        if (!$storage->exists($path)) {
            $storage->makeDirectory($path);
        }

        $img = Image::make($file);
        $name = time() . '.' . $file->getClientOriginalExtension();

        $path = $path . '/' . $name;

        $storage->put($path, (string)$img->encode());

        return $name;
    }

    private function echoRozerpayForm($order)
    {
        $generalSettings = getGeneralSettings();

        echo '<form action="/payments/verify/Razorpay" method="get">
            <input type="hidden" name="order_id" value="' . $order->id . '">

            <script src="/assets/default/js/app.js"></script>
            <script src="https://checkout.razorpay.com/v1/checkout.js"
                    data-key="' . env('RAZORPAY_API_KEY') . '"
                    data-amount="' . (int)($order->total_amount * 100) . '"
                    data-buttontext="product_price"
                    data-description="Rozerpay"
                    data-currency="' . currency() . '"
                    data-image="' . $generalSettings['logo'] . '"
                    data-prefill.name="' . $order->user->full_name . '"
                    data-prefill.email="' . $order->user->email . '"
                    data-theme.color="#43d477">
            </script>

            <style>
                .razorpay-payment-button {
                    opacity: 0;
                    visibility: hidden;
                }
            </style>

            <script>
                $(document).ready(function() {
                    $(".razorpay-payment-button").trigger("click");
                })
            </script>
        </form>';
        return '';
    }

    public function updateOfflinePayment(Request $request, $id)
    {
        $user = auth()->user();
        $offline = OfflinePayment::where('id', $id)
            ->where('user_id', $user->id)
            ->first();

        if (!empty($offline)) {
            $data = $request->all();

            $rules = [
                'amount' => 'required|numeric',
                'gateway' => 'required',
                'account' => 'required_if:gateway,offline',
                'referral_code' => 'required_if:gateway,offline',
                'date' => 'required_if:gateway,offline',
            ];

            if (!empty($request->file('attachment'))) {
                $rules['attachment'] = 'image|mimes:jpeg,png,jpg|max:10240';
            }

            $this->validate($request, $rules);

            $attachment = $offline->attachment;

            if (!empty($request->file('attachment'))) {
                $attachment = $this->handleUploadAttachment($user, $request->file('attachment'));
            }

            $date = convertTimeToUTCzone($data['date'], getTimezone());

            $offline->update([
                'amount' => $data['amount'],
                'bank' => $data['account'],
                'reference_number' => $data['referral_code'],
                'status' => OfflinePayment::$waiting,
                'attachment' => $attachment,
                'pay_date' => $date->getTimestamp(),
            ]);

            return redirect('/panel/financial/account');
        }

        abort(404);
    }

    public function deleteOfflinePayment($id)
    {
        $user = auth()->user();
        $offline = OfflinePayment::where('id', $id)
            ->where('user_id', $user->id)
            ->first();

        if (!empty($offline)) {
            $offline->delete();

            return response()->json([
                'code' => 200
            ], 200);
        }

        return response()->json([], 422);
    }
}
