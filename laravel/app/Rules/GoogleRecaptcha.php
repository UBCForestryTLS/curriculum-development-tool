<?php

namespace App\Rules;

use Closure;
use GuzzleHttp\Client;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Config;

class GoogleRecaptcha implements ValidationRule
{
    /**
     * Create a new rule instance.
     *
     * @return void
     */
    public function __construct()
    {
        //
    }

    /**
     * Run the validation rule.
     *
     * @param mixed $value
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $client = new Client();
        $response = $client->post('https://www.google.com/recaptcha/api/siteverify',
            [
                'form_params' => [
                    'secret' => Config::get('app.captcha_private_key'),
                    'remoteip' => request()->getClientIp(),
                    'response' => $value,
                ],
            ]
        );
        $body = json_decode((string) $response->getBody());

        //return $body->success;
        if(!$body->success) {
            $fail('reCAPTCHA validation failed. Are you a robot?');
        }
    }
}
