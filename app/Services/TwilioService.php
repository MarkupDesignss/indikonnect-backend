<?php

namespace App\Services;

use Twilio\Rest\Client;

class TwilioService
{
    protected $client;
    protected $from;

    public function __construct()
    {
        $this->client = new Client(
            env('TWILIO_ACCOUNT_SID'),
            env('TWILIO_AUTH_TOKEN')
        );
        $this->from = env('TWILIO_5R');
    }

    public function sendOtp($phone, $otp)
    {
        try {
            $message = $this->client->messages->create(
                $phone,
                [
                    'from' => $this->from,
                    'body' => "Your verification OTP is: {$otp}. This OTP is valid for 10 minutes."
                ]
            );
            return $message;
        } catch (\Exception $e) {
            throw $e;
        }
    }
}
