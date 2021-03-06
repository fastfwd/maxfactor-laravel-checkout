<div class="checkout__summary">    
    <div class="checkout__discount-error" v-if="currentCheckout.discount.error">
        <span>@{{ currentCheckout.discount.error }}</span>
    </div>
    <div class="checkout__subtotal">
        @lang('Net total:')<span>@{{ cartNetTotal | money }}</span>
    </div>
    <div class="checkout__subtotal" v-if="cartDiscountTotal > 0">
        @lang('Discount applied:')
        <template v-if="currentCheckout.discount.percentage && currentCheckout.discount.percentage > 0">@{{ currentCheckout.discount.percentage | percentage }}</template>
        <span>@{{ cartDiscountTotal | money }}</span>
    </div>
    {{-- TODO: Display only after step 1 has been completed   --}}
    <div class="checkout__shipping">
        <p>@lang('maxfactor::checkout.shipping'):<span>@{{ cartShippingTotal() | money | default(isCartShippingPoa ? ' POA' : '0.00') }}</span></p>
        @if (isset($editable) && $editable || !isset($editable))
            <a href="{{ route('checkout.show', ['uid' => $uid, 'stage' => 'shipping']) }}">@lang('maxfactor::checkout.change_shipping')</a>
        @endif
    </div>
    <div class="checkout__subtotal">
        @lang('Tax total:')<span>@{{ cartTaxTotal | money | default(' N/A') }}</span>
    </div>
    <div class="checkout__total">
        @lang('Sub total:')<span>@{{ cartSubTotal | money }}</span>
    </div>
</div>
