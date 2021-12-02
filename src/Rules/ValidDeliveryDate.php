<?php
namespace Maxfactor\Checkout\Rules;

use Carbon\Carbon;
use Illuminate\Contracts\Validation\Rule;

class ValidDeliveryDate implements Rule
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
        $invalidDate = Carbon::now();
        $invalidDate->addDays(2);
        $deliveryDate = Carbon::parse($value);

        return $deliveryDate->gt($invalidDate);
    }

    /**
     * Get the validation error message.
     *
     * @return string
     */
    public function message()
    {
        return 'Same-day and next-day delivery are not available. Please click "Return to delivery" and select a delivery date.';
    }
}