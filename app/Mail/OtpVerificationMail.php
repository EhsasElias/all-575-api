<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailable;

final class OtpVerificationMail extends Mailable
{
    public function __construct(
        public readonly string $code,
        public readonly string $locale,
    ) {}

    private function language(): string
    {
        return match (substr(strtolower(str_replace('_', '-', $this->locale)), 0, 2)) {
            'ar' => 'ar',
            'fr' => 'fr',
            default => 'en',
        };
    }

    /** @return array<string, string|int|null> */
    private function contentData(): array
    {
        $lang = $this->language();
        $minutes = max(1, (int) ceil((int) config('store.otp.ttl_seconds', 300) / 60));
        $support = (string) config('mail.support.address', '');
        $support = filter_var($support, FILTER_VALIDATE_EMAIL) ? $support : null;

        $copy = match ($lang) {
            'ar' => [
                'subject' => 'رمز التحقق لحسابك في ALL 5.75',
                'heading' => 'رمز التحقق الخاص بك',
                'greeting' => 'مرحبًا بك،',
                'intro' => 'تلقّينا طلبًا لتسجيل الدخول إلى حسابك في ALL 5.75. استخدم رمز التحقق التالي لإكمال العملية بأمان:',
                'codeCaption' => 'رمز التحقق لمرة واحدة',
                'expiry' => "هذا الرمز صالح لمدة {$minutes} دقائق فقط.",
                'security' => 'لحماية حسابك، لا تشارك هذا الرمز مع أي شخص، حتى لو ادّعى أنه من فريق الدعم.',
                'ignore' => 'إذا لم تطلب هذا الرمز، يمكنك تجاهل الرسالة. لن يتم تسجيل الدخول دون إدخال الرمز.',
                'supportLabel' => 'هل تحتاج إلى مساعدة؟ تواصل معنا عبر:',
                'signoff' => 'مع أطيب التحيات،',
                'team' => 'فريق ALL 5.75',
                'automatic' => 'هذه رسالة آلية لتأكيد عملية تسجيل الدخول.',
            ],
            'fr' => [
                'subject' => 'Votre code de vérification ALL 5.75',
                'heading' => 'Votre code de vérification',
                'greeting' => 'Bonjour,',
                'intro' => 'Nous avons reçu une demande de connexion à votre compte ALL 5.75. Utilisez le code ci-dessous pour terminer la vérification en toute sécurité :',
                'codeCaption' => 'Code à usage unique',
                'expiry' => "Ce code expire dans {$minutes} minutes.",
                'security' => 'Pour protéger votre compte, ne communiquez jamais ce code, même à une personne prétendant appartenir à notre assistance.',
                'ignore' => 'Si vous n’êtes pas à l’origine de cette demande, ignorez ce message. Aucune connexion ne sera effectuée sans le code.',
                'supportLabel' => 'Besoin d’aide ? Contactez notre assistance :',
                'signoff' => 'Cordialement,',
                'team' => 'L’équipe ALL 5.75',
                'automatic' => 'Ce message automatique concerne la vérification de votre connexion.',
            ],
            default => [
                'subject' => 'Your ALL 5.75 verification code',
                'heading' => 'Verify your sign-in',
                'greeting' => 'Hello,',
                'intro' => 'We received a request to sign in to your ALL 5.75 account. Enter the verification code below to securely complete your sign-in:',
                'codeCaption' => 'One-time verification code',
                'expiry' => "This code expires in {$minutes} minutes.",
                'security' => 'For your security, never share this code with anyone, including anyone claiming to be from our support team.',
                'ignore' => 'If you did not request this code, you can safely ignore this email. No one can sign in without entering the code.',
                'supportLabel' => 'Need help? Contact our support team:',
                'signoff' => 'Kind regards,',
                'team' => 'The ALL 5.75 Team',
                'automatic' => 'This automated message was sent to verify your sign-in.',
            ],
        };

        return [
            ...$copy,
            'code' => $this->code,
            'lang' => $lang,
            'direction' => $lang === 'ar' ? 'rtl' : 'ltr',
            'supportEmail' => $support,
            'brand' => 'ALL 5.75',
        ];
    }

    public function envelope(): Envelope
    {
        $support = (string) config('mail.support.address', '');

        return new Envelope(
            subject: (string) $this->contentData()['subject'],
            replyTo: filter_var($support, FILTER_VALIDATE_EMAIL) ? [new Address($support)] : [],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.otp-verification',
            text: 'mail.otp-verification-text',
            with: $this->contentData(),
        );
    }
}
