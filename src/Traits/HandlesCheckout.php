<?php

namespace Maxfactor\Checkout\Traits;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\Session;
use Maxfactor\Checkout\Handlers\Paypal;
use Illuminate\Support\Facades\Validator;
use Maxfactor\Checkout\Contracts\Postage;
use Maxfactor\Checkout\Contracts\Checkout;
use Maxfactor\Checkout\Handlers\PaymentWrapper;

trait HandlesCheckout
{
    /**
     * The keymap is used to map checkout keys to google tag manger keys
     *
     * @var array
     */
    protected $keyMap = [
        'id' => 'ref',
        'unitPrice' => 'price',
    ];

    protected $uid;

    /**
     * Get the current checkout id from the route object
     *
     * @return string
     */
    public function getCurrentCheckoutId()
    {
        return request()->route('uid') ? : '';
    }

    /**
     * Retrieve the checkout parameters from the Request or Session if available
     *
     * @return array
     */
    public function getCurrentCheckoutParams()
    {
        $uid = $this->getCurrentCheckoutId();

        return Request::has('uid')
            ? Request::all()
            : (optional(Session::get("checkout.{$uid}"))->toArray() ? : []);
    }

    /**
     * Process different stages of checkout. Sets the template and runs the
     * method for the checkout stage if it exists.
     *
     * @param string $stage
     * @return Model
     */
    public function stage(string $stage = null, string $mode = 'show')
    {
        $uid = $this->uid = Route::current()->parameter('uid');

        if (!$stage) {
            return $this;
        }

        $this->template($stage);
        $this->append('stage', $stage);

        Session::put('js_vars', collect([
            'uid' => $uid,
            "checkout.{$uid}" => $this->get('items'),
            "checkout.shipping.{$uid}" => $this->get('shipping'),
            "checkout.billing.{$uid}" => $this->get('billing'),
            "checkout.user.{$uid}" => $this->get('user'),
            "checkout.discount.{$uid}" => $this->get('discount'),
            "stage.{$uid}" => $this->getFirst('stage'),
            "serverStage.{$uid}" => $this->getFirst('serverStage'),
        ])->filter(function ($value, $key) {
            if ($value instanceof Collection) {
                return $value->count();
            }

            return $value !== "";
        })->all());

        /**
         * Ensure a customer cannot progress through the checkout if their order drops below the min value
         * This could happen if a product becomes unavailable and is removed from the cart
         * This is not being checked on the first stage as the finalValue will not have been updated here
         */
        if (!$this->hasValidOrderTotal($mode)) {
            Session::put('checkoutError', "Order value error ({$this->getFirst('finalTotalExcDiscount')})");
            Session::save();
            header('Location: ' . route('cart.index'));
            exit();
        }

        Session::put('checkoutUID', $this->getCurrentCheckoutId());

        if ($stage == 'default' && $mode == 'show' && Request::has('token') && Request::has('PayerID')) {
            $this->propagatePaypal();
        }

        $processCheckoutStage = sprintf("checkoutStage%s%s", ucfirst($mode), ucfirst($stage));
        if (method_exists($this, $processCheckoutStage)) {
            $this->{$processCheckoutStage}();
        }

        return $this;
    }

    /**
     * Validates if the final total passes the minimum order requirements.
     *
     * @param string $mode
     * @return boolean
     */
    protected function hasValidOrderTotal($mode)
    {
        if ($mode !== 'show') {
            return true;
        }

        if (in_array($this->getFirst('stage'), [
            'default',
            'complete',
            'paypalcomplete',
        ])) {
            return true;
        }

        if ($this->getFirst('finalTotalExcDiscount') >= config('maxfactor-checkout.minimum_order')) {
            return true;
        }

        return false;
    }

    private function syncSession()
    {
        if (Request::get("checkout")) {
            Session::put("checkout.{$this->uid}", collect(Request::all()));

            Session::put('js_vars', collect([
                'uid' => $this->uid,
                "checkout.{$this->uid}" => Request::get('checkout')['items'],
                "checkout.shipping.{$this->uid}" => Request::get('checkout')['shipping'],
                "checkout.billing.{$this->uid}" => Request::get('checkout')['billing'],
                "checkout.user.{$this->uid}" => Request::get('checkout')['user'],
                "checkout.discount.{$this->uid}" => Request::get('checkout')['discount'],
                "stage.{$this->uid}" => $this->getFirst('stage'),
                "serverStage.{$this->uid}" => $this->getFirst('serverStage'),
            ])->filter(function ($value, $key) {
                if ($value instanceof Collection) {
                    return $value->count();
                }

                return $value !== "";
            })->all());
        }
    }

    /**
     * Additional functionality required for the Shipping stage
     *
     * @return void
     */
    public function checkoutStageShowShipping()
    {
        $postage = App::make(Postage::class, [
            'content' => null,
            'params' => Session::get("checkout.{$this->uid}", collect(['checkout' => []]))->toArray()
        ]);

        $this->append('postageOptions', $postage->raw());
    }

    /**
     * Save the progress of the checkout with the submitted customer information
     * for the first stage of checkout.
     *
     * @return void
     */
    public function checkoutStageStoreShipping()
    {
        $this->syncSession();

        Validator::make(Request::get('checkout')['user'], [
            'email' => 'required|email',
            'telephone' => 'required',
        ])->validate();

        Validator::make(Request::get('checkout')['shipping'], [
            'firstname' => 'required|string',
            'surname' => 'required|string',
            'company' => 'nullable|string',
            'address' => 'required|string',
            'address_2' => 'nullable|string',
            'address_3' => 'nullable|string',
            'address_city' => 'required|string',
            'address_county' => 'nullable|string',
            'address_postcode' => [
                'required',
                'string',
                function ($attribute, $value, $fail) {
                    $alpha1 = "[abcdefghijklmnoprstuwyz]";                          // Character 1
                    $alpha2 = "[abcdefghklmnopqrstuvwxy]";                          // Character 2
                    $alpha3 = "[abcdefghjkstuw]";                                   // Character 3
                    $alpha4 = "[abehmnprvwxy]";                                     // Character 4
                    $alpha5 = "[abdefghjlnpqrstuwxyz]";                             // Character 5
                    
                    // Expression for postcodes: AN NAA, ANN NAA, AAN NAA, and AANN NAA with a space
                    // Or AN, ANN, AAN, AANN with no whitespace
                    $pcexp[0] = '^(' . $alpha1 . '{1}' . $alpha2 . '{0,1}[0-9]{1,2})([[:space:]]{0,})([0-9]{1}' . $alpha5 . '{2})?$';
                    
                    // Expression for postcodes: ANA NAA
                    // Or ANA with no whitespace
                    $pcexp[1] = '^(' . $alpha1 . '{1}[0-9]{1}' . $alpha3 . '{1})([[:space:]]{0,})([0-9]{1}' . $alpha5 . '{2})?$';
                    
                    // Expression for postcodes: AANA NAA
                    // Or AANA With no whitespace
                    $pcexp[2] = '^(' . $alpha1 . '{1}' . $alpha2 . '[0-9]{1}' . $alpha4 . ')([[:space:]]{0,})([0-9]{1}' . $alpha5 . '{2})?$';
                    
                    // Exception for the special postcode GIR 0AA
                    // Or just GIR
                    $pcexp[3] = '^(gir)([[:space:]]{0,})?(0aa)?$';
                    
                    // Standard BFPO numbers
                    $pcexp[4] = '^(bfpo)([[:space:]]{0,})([0-9]{1,4})$';
                    
                    // c/o BFPO numbers
                    $pcexp[5] = '^(bfpo)([[:space:]]{0,})(c\/o([[:space:]]{0,})[0-9]{1,3})$';
                    
                    // Overseas Territories
                    $pcexp[6] = '^([a-z]{4})([[:space:]]{0,})(1zz)$';
                    
                    // Anquilla
                    $pcexp[7] = '^(ai\-2640)$';
                    
                    // Load up the string to check, converting into lowercase
                    $postcode = strtolower($value);
                    
                    // Assume we are not going to find a valid postcode
                    $valid = false;
                    
                    // Check the string against the six types of postcodes
                    foreach ($pcexp as $regexp) {
                        if (preg_match('/' . $regexp . '/i', $postcode, $matches)) {
                    
                        // Load new postcode back into the form element
                        $postcode = strtoupper($matches[1]);
                        if (isset($matches[3])) {
                            $postcode .= ' ' . strtoupper($matches[3]);
                        }
                    
                        // Take account of the special BFPO c/o format
                        $postcode = preg_replace('/C\/O/', 'c/o ', $postcode);
                    
                        // Remember that we have found that the code is valid and break from loop
                        $valid = true;
                        break;
                        }
                    }

                    if (preg_match('/\s{2,}/', $value)) {
                        $valid = false;
                    }
                    
                    // Return with the reformatted valid postcode in uppercase if the postcode was 
                    // valid
                    if ($valid) {
                        $value = $postcode;
                    } else {
                        $fail('Must be a valid UK postcode.');
                    }
                }
            ],
            'address_country' => 'nullable|string',
        ],
        [
            'firstname.required' => 'First Name is required.',
            'surname.required' => 'Surname is required.',
            'address.required' => 'Address is required.',
            'address_city.required' => 'City is required.',
            'address_postcode.required' => 'Post Code is required.'
        ])->validate();
    }

    public function checkoutStageShowPayment()
    {
        return $this->append('stripePublishableKey', env('STRIPE_PUBLISHABLE_KEY') ? : '');
    }

    /**
     * The user has selected their shipping method. We need to store this
     * progress in the session and validate the method.
     *
     * @return void
     */
    public function checkoutStageStorePayment()
    {
        $this->syncSession();

        Validator::make(Request::get('checkout')['shippingMethod'], [
            'id' => 'required|integer|min:1',
        ], ['id.*' => 'Please select a delivery date'])->validate();

        if (App::environment(['local', 'staging', 'testing'])) {
            Session::put('dusk_vars', ['finalTotal' => floatval($this->getFirst('finalTotal'))]);
        }
    }

    /**
     * This is called after the client side is ready to process the actual
     * payment. We need to validate any fields, send the payment to the gateway
     * and return the result. The front-end will handle any redirects/ui.
     *
     * @return void
     */
    public function checkoutStageStoreComplete()
    {
        /**
         * Do not proceed if order has dropped below min value
         */
        if ($this->getFirst('finalTotalExcDiscount') < config('maxfactor-checkout.minimum_order')) {
            return $this;
        }

        $this->syncSession();

        $provider = $this->getProvider();

        // Call relevant validation form request based on $provider
        App::make(sprintf("\Maxfactor\Checkout\Requests\%sPaymentRequest", ucfirst($provider)));

        $checkout = App::make(Checkout::class, [
            'uid' => $this->getFirst('uid'),
        ]);

        if (!$checkout->isPaymentRequired()) {
            // This order has already been paid
            return $this;
        }

        // Pass to payment handler for processing payment
        $paymentResponseData = (new PaymentWrapper($provider))
            ->setAmount($this->getFirst('finalTotal'))
            ->setOrderID($this->getFirst('orderID'))
            ->setUid($this->uid)
            ->process();

        Session::put('paymentResponse', $paymentResponseData);
        $this->append('paymentResponse', $paymentResponseData);

        // Send the payment response to the Api for processing
        App::make(Checkout::class, [
            'uid' => $this->getFirst('uid'),
            'params' => [
                'checkout' => collect(Request::get('checkout'))->toArray(),
                'paymentResponse' => $paymentResponseData,
            ]
        ]);

        return $this;
    }

    /**
     * This step is called after the user has been redirected
     * to and completed the bank's 3DS authentication.
     *
     * @return void
     */
    public function checkoutStageShowSca()
    {
        $this->syncSession();

        $uid = Route::current()->parameter('uid');

        // Retrieve original data from session
        $paymentIntent = Session::get('paymentIntent');

        extract($paymentIntent);

        // Commit the purchase to the stripe payment gateway
        $paymentResponse = (new PaymentWrapper($this->getProvider()))
            ->setUid($uid)
            ->setAmount($amount)
            ->setOrderId($reference)
            ->confirm(Session::get('paymentIntentReference'));

        /**
         * Send the payment response to the Api for processing
         */
        App::make(Checkout::class, [
            'uid' => $uid,
            'params' => [
                'checkout' => $checkout,
                'paymentResponse' => $paymentResponse->getData(),
            ],
        ]);

        /**
         * We don't want to actually render this page, but instead redirect
         * to the order completed screen.
         */
        abort(302, '', ['Location' => route('checkout.show', [$uid, 'complete'])]);
    }

    /**
     * The final stage of the checkout. This is where we show the result
     * or confirmation to the user
     *
     * @return void
     */
    public function checkoutStageShowComplete()
    {
        $this->renderGoogleTagManager();

        return $this;
    }

    /**
     * The user has selected to pay with PayPal. Store in session and
     * continue to show method.
     *
     * @return void
     */
    public function checkoutStageStorePaypalauth()
    {
        $this->syncSession();

        return $this;
    }

    /**
     * Perform PayPal payment authorization.  This is being done in a show
     * method to avoid cross origin resource problems.
     *
     * @return void
     */
    public function checkoutStageShowPaypalauth()
    {
        $this->syncSession();

        $paypal = (new Paypal());

        $response = $paypal->authorize([
            'amount' => $paypal->formatAmount($this->getFirst('finalTotal')),
            'transactionId' => $this->getFirst('orderID'),
            'currency' => 'GBP',
            'cancelUrl' => $paypal->getCancelUrl(),
            'returnUrl' => $paypal->getReturnUrl($this->uid),
        ])->send();

        // This checks for success status.. not helpfully named
        if ($response->isRedirect()) {
            $response->redirect();
        } else {
            // There has been an error authorising with PayPal
            Log::info($response->getData());
            header('Location: ' . route('cart.index'), true, 303);
        }

        // Do not proceed to rendering
        exit();
    }

    /**
     * After PayPal authorization is complete, store response data in
     * Session to be accessable through JS variables.
     *
     * @return void
     */
    private function propagatePaypal()
    {
        $paypal = new PayPal;

        $request = $paypal->fetchExpressCheckout(Request::only('token'));

        $response = $request->send();

        if ($response->isSuccessful()) {
            $paypalData = $response->getData();

            Session::put('js_vars', [
                "uid" => $this->uid,
                "checkout.shipping.{$this->uid}" => $this->collectPayPalAddress($paypalData),
                "checkout.billing.{$this->uid}" => $this->collectPayPalAddress($paypalData),
                "checkout.user.{$this->uid}" => collect([
                    'email' => $paypalData['EMAIL'],
                ]),
                "stage.{$this->uid}" => 'default',
                "paypal.{$this->uid}" => collect([
                    'provider' => 'paypal',
                    'token' => $paypalData['TOKEN'],
                    'payerid' => $paypalData['PAYERID'],
                    'result' => '',
                ]),
            ]);
        }
    }

    /**
     * Collection of optional PayPal address fields
     *
     * @param array $paypalData
     * @return Collection
     */
    private function collectPayPalAddress($paypalData)
    {
        return collect([
            'firstname' => $paypalData['FIRSTNAME'] ?? '',
            'surname' => $paypalData['LASTNAME'] ?? '',
            'address' => $paypalData['SHIPTOSTREET'] ?? '',
            'address_city' => $paypalData['SHIPTOCITY'] ?? '',
            'address_county' => $paypalData['SHIPTOSTATE'] ?? '',
            'address_postcode' => $paypalData['SHIPTOZIP'] ?? '',
            'address_country' => $paypalData['SHIPTOCOUNTRYCODE'] ?? '',
        ]);
    }

    /**
     * Pushes details of the transaction into the GTM data layer which is then
     * rendered on the page.
     *
     * @return void
     */
    public function renderGoogleTagManager()
    {
        $productsOrdered = collect(Session::get("checkout.{$this->uid}.checkout")['items'])->map(function ($item) {
            return collect($item)->mapWithKeys(function ($value, $key) {
                return [array_key_exists($key, $this->keyMap) ? $this->keyMap[$key] : $key => $value];
            })->only([
                'ref',
                'name',
                'category',
                'price',
                'quantity',
            ]);
        });

        return [
            'transactionId' => $this->getFirst('orderID'),
            'transactionAffiliation' => config('app.name'),
            'transactionTotal' => floatval($this->getFirst('finalTotal')),
            'transactionTax' => floatval($this->getFirst('incTaxTotal') - $this->getFirst('exTaxTotal')),
            'transactionShipping' => floatval($this->getFirst('postageTotal')),
            'transactionProducts' => $productsOrdered,
        ];
    }

    /**
     * Get payment provider for checkout
     *
     * @return string
     */
    private function getProvider()
    {
        if ($this->getFirst('finalTotal') == 0) {
            return 'free';
        }

        return isset(Request::get('checkout')['payment']['provider']) ?
            Request::get('checkout')['payment']['provider'] : 'stripe';
    }
}
