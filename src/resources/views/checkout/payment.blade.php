@extends('layouts.default')

@section('header')
    {{--  Do not include the default header  --}}
@endsection

@section('asideheader')
    {{--  Do not include the default aside header  --}}
@endsection

@section('menu')
    {{--  Do not include the default mega menu  --}}
@show

@section('siteModifier', 'site--checkout')

@section('main')

    <section class="checkout">
        <form>
        @include('maxfactor::checkout.components.header')
        <div class="checkout__group">
            <div class="checkout__left">
                <div class="checkout__left-content">
                    <div class="checkout__customer-info">
                        <h1>@lang('Customer information')</h1>
                        <h3>@lang('Payment details')</h3>
                        <template v-if="currentCheckout.payment.provider == 'paypal'">
                            <label>Paying with PayPal Express</label>
                        </template>
                        <template v-else-if="currentCheckout.payment.provider == 'free'">
                            <div class="checkout__free">
                                <label>No payment required for order.</label>
                                <label v-if="currentCheckout.discount.message">@{{ currentCheckout.discount.message }}</label>
                            </div>
                        </template>
                        <template v-else>
                            <mx-stripe class="user__field"
                                publishableKey="{{ $stripePublishableKey }}"
                                v-model="currentCheckout.payment"
                            ><label>@lang('Card details')</label></mx-stripe>
                            <div class="user__field">
                                <label for="nameoOnCard" class="label--required">@lang('Name on card')</label>
                                <input id="nameoOnCard" type="text" v-model="currentCheckout.billing.nameoncard" required>
                                <v-form-error field="checkout.billing.nameoncard"></v-form-error>
                            </div>
                        </template>
                    </div>
                    <div class="checkout__terms">
                        <label>
                            <input type="checkbox" v-model="currentCheckout.user.terms">
                            @lang('Please check the box to agree to our')
                            <a class="checkout__terms-link" href="{{ route('termsConditions') }}" target="_blank">
                                @lang('terms &amp; conditions')
                            </a>
                        </label>
                        <v-form-error field="checkout.user.terms"></v-form-error>
                    </div>
                
                    <template v-if="currentCheckout.payment.provider != 'free'">
                        <template v-if="currentCheckout.payment.provider == 'paypal'">
                            <div class="checkout__customer-info checkout__customer-info--pad-bot-sml">
                                <h3>@lang('Billing address')</h3>
                                <label>Provided by PayPal</label>
                            </div>
                        </template>
                        <template v-else>
                            <div class="checkout__customer-info checkout__customer-info--pad-bot-sml">
                                <h3>@lang('Billing address')</h3>
                                <div class="checkout__shipping-option">
                                    <input name="billingUseShipping" id="billingUseShipping" type="radio" v-model="currentCheckout.useShipping" :value="true">
                                    <label for="billingUseShipping">@lang('maxfactor::checkout.same_as_shipping')</label>
                                </div>
                                <div class="checkout__shipping-option">
                                    <input name="billingUseDifferent" id="billingUseDifferent" type="radio" v-model="currentCheckout.useShipping" :value="false">
                                    <label for="billingUseDifferent">@lang('maxfactor::checkout.use_different_billing')</label>
                                </div>
                            </div>
                            <div class="checkout__customer-info checkout__customer-info--no-border" v-if="!currentCheckout.useShipping">
                                <div class="user__field user__field--half">
                                    <label for="addressFirstName" class="label--required">@lang('First name')</label>
                                    <input id="addressFirstName" type="text" v-model="billingCollection.firstname" required>
                                    <v-form-error field="checkout.billing.firstname"></v-form-error>
                                </div>
                                <div class="user__field user__field--half">
                                    <label for="addressSurname" class="label--required">@lang('Surname')</label>
                                    <input id="addressSurname" type="text" v-model="billingCollection.surname" required>
                                    <v-form-error field="checkout.billing.surname"></v-form-error>
                                </div>

                                <mx-address v-model="billingCollection" api-key="{{ config('maxfactor-checkout.pca_key') }}" class="checkout__customer-info">
                                    <template slot="selection" slot-scope="props">
                                        <postcode-modal
                                            :addresses="props.addresses"
                                            :value="props.haveAddresses"
                                            @input="props.clearAddresses"
                                            @select="props.select"
                                        >
                                        </postcode-modal>
                                    </template>

                                    <template slot-scope="props">
                                        <div class="user__field">
                                            <label for="addressCompany">@lang('Company')</label>
                                            <input id="addressCompany" type="text" v-model="props.address.company" :disabled="!canEditShipping">
                                            <v-form-error field="checkout.billing.company"></v-form-error>
                                        </div>
                                        <div class="user__field user__field--half sort-order1">
                                            <label for="addressAddress" class="label--required">@lang('Address')</label>
                                            <input id="addressAddress" type="text" v-model="props.address.address" :disabled="!canEditShipping" required>
                                            <v-form-error field="checkout.billing.address"></v-form-error>
                                        </div>
                                        <div class="user__field user__field--half sort-order2">
                                            <label for="addressAddress2">@lang('Address 2')</label>
                                            <input id="addressAddress2" type="text" v-model="props.address.address_2" :disabled="!canEditShipping">
                                            <v-form-error field="checkout.billing.address_2"></v-form-error>
                                        </div>
                                        <div class="user__field user__field--half sort-order3">
                                            <label for="addressAddress3">@lang('Address 3')</label>
                                            <input id="addressAddress3" type="text" v-model="props.address.address_3" :disabled="!canEditShipping">
                                            <v-form-error field="checkout.billing.address_3"></v-form-error>
                                        </div>
                                        <div class="user__field user__field--half sort-order4">
                                            <label for="addressCity" class="label--required">@lang('City')</label>
                                            <input id="addressCity" type="text" v-model="props.address.address_city" :disabled="!canEditShipping" required>
                                            <v-form-error field="checkout.billing.address_city"></v-form-error>
                                        </div>
                                        <div class="user__field user__field--half sort-order1">
                                            <label for="addressCounty">@lang('County')</label>
                                            <input id="addressCounty" type="text" v-model="props.address.address_county" :disabled="!canEditShipping" required>
                                            <v-form-error field="checkout.billing.address_county"></v-form-error>
                                        </div>
                                        <div class="user__field user__field--half user__field--postcode sort-order2">
                                            <label for="addressPostcode" class="label--required">@lang('Post code')</label>
                                            <input id="addressPostcode" type="text" v-model="props.address.address_postcode" :disabled="!canEditShipping" required>
                                            <v-form-error field="checkout.billing.address_postcode"></v-form-error>
                                            <button @click.prevent="props.changeAddress">Change address</button>
                                        </div>
                                        <div class="user__field user__field--half sort-order3 user__field--country">
                                            <label for="addressCountry" class="label--required">@lang('Country')</label>
                                            <div class="select">
                                                <select v-model="props.address.address_country" id="addressCountry" :disabled="!canEditShipping" required>
                                                    <option selected disabled>Please select</option>
                                                    @foreach (Maxfactor::countries() as $code => $item)
                                                        <option value="{{ $code }}">{{ $item }}</option>
                                                    @endforeach
                                                </select>
                                            </div>
                                            <v-form-error field="checkout.billing.address_country"></v-form-error>
                                        </div>
                                    </template>
                                </mx-address>

                            </div>
                        </template>
                    </template>

                    @component('maxfactor::checkout.components.actions')
                        @slot('continueLabel', __('Place order'))
                        @slot('continueUrl', route('checkout.show', ['uid' => $uid, 'stage' => 'complete']))
                        @slot('returnLabel', __('maxfactor::checkout.return_to_shipping'))
                        @slot('returnUrl', route('checkout.show', ['uid' => $uid, 'stage' => 'shipping']))
                        @slot('onClick', 'processCheckout')
                    @endcomponent

                    <v-form-error field="message"></v-form-error>
                    @include('maxfactor::checkout.components.legal')
                </div>
            </div>
            <div class="checkout__right">
                @include('maxfactor::checkout.components.items')
                @include('maxfactor::checkout.components.summary')
            </div>
        </div>
        </form>
    </section>

@endsection

@section('footer')
    {{--  Do not include the default footer  --}}
@endsection
