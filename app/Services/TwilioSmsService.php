<?php

namespace App\Services;

use Twilio\Rest\Client;
use Illuminate\Support\Facades\Log;
use Throwable;

class TwilioSmsService
{
    protected ?Client $client = null;

    public function __construct()
    {
        $sid   = config('services.twilio.sid');
        $token = config('services.twilio.token');

        if ($sid && $token) {
            $this->client = new Client($sid, $token);
        }
    }

    /**
     * Send an SMS message.
     *
     * @param  string  $to       Recipient phone (E.164 recommended)
     * @param  string  $message  Full message body
     * @return bool
     */
    public function send(string $to, string $message): bool
    {
        if (!$this->client) {
            Log::warning('TwilioSmsService: Twilio credentials not configured.');
            return false;
        }

        $from = config('services.twilio.from');

        if (!$from) {
            Log::warning('TwilioSmsService: TWILIO_FROM number not configured.');
            return false;
        }

        try {
            $this->client->messages->create($to, [
                'from' => $from,
                'body' => $message,
            ]);

            return true;
        } catch (Throwable $e) {
            Log::error('TwilioSmsService: Failed to send SMS', [
                'to'      => $to,
                'message' => $message,
                'error'   => $e->getMessage(),
            ]);

            return false;
        }
    }
}
