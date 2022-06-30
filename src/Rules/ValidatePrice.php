<?php
namespace Maxfactor\Checkout\Rules;

use Illuminate\Contracts\Validation\Rule;

class ValidatePrice implements Rule
{

    protected $skuModel;

    public function __construct()
    {
        $skuModelPath = config('maxfactor-checkout.sku');
        $this->skuModel = new $skuModelPath;
    }
    /**
     * Determine if the validation rule passes.
     *
     * @param  string  $attribute
     * @param  mixed  $value
     * @return bool
     */
    public function passes($attribute, $value)
    {
        foreach ($value as $item) {
            $dbSkuPrice = $this->skuModel->find($item['id'])->current_price;
            if ($item['unitPrice'] != $dbSkuPrice) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get the validation error message.
     *
     * @return string
     */
    public function message()
    {
        return 'Items in your cart have changed price. Please return to the <a href="/cart">cart</a>'
            .' and verify your order before completing checkout.';
    }
}
