<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentChannel extends Model
{
    protected $table = 'payment_channels';
    protected $guarded = ['id'];
    public $timestamps = false;

    static $classes = [
        'Alipay', 'Authorizenet', 'Bitpay', 'Braintree', 'Cashu', 'Flutterwave',
        'Instamojo', 'Iyzipay', 'Izipay', 'KlarnaCheckout', 'MercadoPago',
        'Mollie', 'Ngenius', 'Payfort', 'Payhere', 'Payku', 'Paylink', 'Paypal',
        'Paysera', 'Paystack', 'Paytm', 'Payu', 'Razorpay', 'Robokassa', 'Sslcommerz',
        'Stripe', 'Toyyibpay', 'Voguepay', 'Zarinpal', 'JazzCash', 'IPay88', 'Xendit', 'Paytabs', 'Paymob', 'Cintepay', 'TapPayment', 'Flip', 'Espay'
    ];

    static $gatewayIgnoreRedirect = [
        'Paytm', 'Payu', 'Zarinpal', 'Stripe', 'Paysera', 'Cashu',
        'Payhere', 'Authorizenet', 'Voguepay', 'Payku', 'KlarnaCheckout', 'Izipay', 'Iyzipay',
        'JazzCash', 'IPay88', 'Paytabs', 'Paymob', 'Cintepay'
    ];

    static $paypal = 'Paypal';
    static $espay = 'Espay';
    static $paystack = 'Paystack';
    static $paytm = 'Paytm';
    static $payu = 'Payu';
    static $razorpay = 'Razorpay';
    static $zarinpal = 'Zarinpal';
    static $stripe = 'Stripe';
    static $paysera = 'Paysera';
    static $fastpay = 'Fastpay';
    static $twoCheckout = '2checkout';
    static $bitpay = 'Bitpay';
    static $adyen = 'Adyen';
    static $flutterwave = 'Flutterwave';
    static $payfort = 'Payfort';
    static $sslcommerz = 'Sslcommerz';
    static $instamojo = 'Instamojo';
    static $payhere = 'Payhere';
    static $ngenius = 'Ngenius';
    static $authorizenet = 'Authorizenet';
    static $voguepay = 'Voguepay';
    static $payku = 'Payku';
    static $toyyibpay = 'Toyyibpay';
    static $robokassa = 'Robokassa';
    static $klarnaCheckout = 'KlarnaCheckout';
    static $mollie = 'Mollie';
    static $alipay = 'Alipay';
    static $braintree = 'Braintree';
    static $izipay = 'Izipay';
    static $paylink = 'Paylink';
    static $jazzCash = 'JazzCash';
    static $redsys = 'Redsys';
    static $ipay88 = 'Ipay88';
    static $xendit = 'Xendit';
    static $paytabs = 'Paytabs';
    static $paymob = 'Paymob';
    static $cintepay = 'Cintepay';
    static $flip = 'Flip';


    public function getTitleAttribute($value)
    {
        if ($this->class_name === 'Flip' or mb_strtolower((string) $value) === 'flip') {
            return 'Pembayaran Online (Bank Transfer, E-Wallet)';
        }

        return $value;
    }

    public function getCredentialsAttribute()
    {
        $credentials = $this->attributes['credentials'];

        if (!empty($credentials)) {
            $credentials = json_decode($credentials, true);
        }

        return $credentials;
    }

    public function getCurrenciesAttribute()
    {
        if (!empty($this->attributes['currencies'])) {
            return json_decode($this->attributes['currencies'], true);
        }

        return [];
    }
}
