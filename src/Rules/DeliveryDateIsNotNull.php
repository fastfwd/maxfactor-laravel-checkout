<?php
namespace Maxfactor\Checkout\Rules;

use Carbon\Carbon;
use Illuminate\Contracts\Validation\Rule;

class DeliveryDateIsNotNull implements Rule
{

    /**
     * Determine if the validation rule passes.
     *
     * @param  string  $attribute
     * @param  mixed  $value
     * @return bool
     */
    public function passes($attribute, $value)
    {
        return !empty($value);
    }

    /**
     * Get the validation error message.
     *
     * @return string
     */
    public function message()
    {
        return 'Please select a delivery date.';
    }
}