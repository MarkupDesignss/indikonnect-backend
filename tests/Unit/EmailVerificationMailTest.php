<?php

namespace Tests\Unit;

use App\Mail\EmailVerificationMail;
use App\Models\User;
use PHPUnit\Framework\TestCase;

class EmailVerificationMailTest extends TestCase
{
    public function test_it_builds_expected_email_content(): void
    {
        $user = new User([
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'email' => 'jane@example.com',
        ]);

        $mail = new EmailVerificationMail($user, 'test-token');
        $content = $mail->content();

        $this->assertSame('emails.email-verification', $content->view);
        $this->assertSame($user->email, $content->with['user']->email);
        $this->assertStringContainsString('test-token', $content->with['verificationUrl']);
    }
}
