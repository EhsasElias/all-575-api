<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Identity\Enums\OtpChannel;
use App\Domain\Identity\Services\MailOtpSender;
use App\Mail\OtpVerificationMail;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

final class ProfessionalOtpEmailTest extends TestCase
{
    public function test_verification_email_uses_correct_sender_subject_and_reply_to(): void
    {
        config([
            'mail.default' => 'smtp',
            'mail.from.address' => 'verified-sender@example.test',
            'mail.from.name' => 'ALL 5.75',
            'mail.support.address' => 'helpdesk@example.test',
        ]);
        Mail::fake();

        (new MailOtpSender)->send(OtpChannel::Email, 'buyer@example.test', '428615', 'en');

        Mail::assertSent(OtpVerificationMail::class, function (OtpVerificationMail $mail): bool {
            $recipients = $mail->to;
            $replyTo = $mail->envelope()->replyTo;
            $html = $mail->render();

            return ($recipients[0]['address'] ?? null) === 'buyer@example.test'
                && $mail->envelope()->subject === 'Your ALL 5.75 verification code'
                && ($replyTo[0]->address ?? null) === 'helpdesk@example.test'
                && str_contains($html, '428615')
                && str_contains($html, 'helpdesk@example.test')
                && str_contains($html, 'This code expires in 5 minutes.')
                && str_contains($html, 'ignore this email');
        });
    }

    public function test_email_is_localized_and_support_is_not_invented(): void
    {
        config(['mail.support.address' => '', 'store.otp.ttl_seconds' => 300]);

        foreach ([
            'ar' => ['رمز التحقق الخاص بك', 'dir="rtl"', '5 دقائق'],
            'en' => ['Verify your sign-in', 'dir="ltr"', '5 minutes'],
            'fr' => ['Votre code de vérification', 'dir="ltr"', '5 minutes'],
        ] as $locale => [$heading, $direction, $expiry]) {
            $mail = new OtpVerificationMail('563209', $locale);
            $html = $mail->render();
            $this->assertStringContainsString($heading, $html);
            $this->assertStringContainsString($direction, $html);
            $this->assertStringContainsString($expiry, $html);
            $this->assertStringContainsString('563209', $html);
            $this->assertStringNotContainsString('mailto:', $html);
        }
    }

    public function test_plain_text_version_includes_security_information(): void
    {
        $mail = new OtpVerificationMail('389410', 'en');
        $data = $mail->content()->with;
        $text = view('mail.otp-verification-text', $data)->render();

        $this->assertStringContainsString('389410', $text);
        $this->assertStringContainsString('never share this code', $text);
        $this->assertStringContainsString('did not request', $text);
    }
}
