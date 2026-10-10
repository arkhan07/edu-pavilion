<form action="{{ route('espay.pay', ['order' => $order->id]) }}" method="post" class="js-espay-pay-form">
    {{ csrf_field() }}
    <input type="hidden" name="bank_code" value="{{ $method['bank_code'] }}">
    <input type="hidden" name="product_code" value="{{ $method['code'] }}">
    <button type="submit" class="espay-method {{ !empty($selected) ? 'is-selected' : '' }}">
        <span class="espay-method__logo"><img src="{{ $method['logo'] }}" alt="{{ $method['name'] }}" loading="lazy"></span>
        <span class="espay-method__text">
            <span class="espay-method__name">{{ $method['name'] }}</span>
            <span class="espay-method__hint">{{ $method['hint'] }}</span>
        </span>
        <span class="espay-method__arrow">&rsaquo;</span>
    </button>
</form>
