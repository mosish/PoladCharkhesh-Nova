<?php

declare(strict_types=1);

namespace Nova\Inquiry;

use PHPMailer\PHPMailer\Exception as MailerException;
use PHPMailer\PHPMailer\PHPMailer;
use RuntimeException;

/**
 * SMTP through PHPMailer (E-3). The mailbox is a launch input; the dev stack
 * sends to the local mail catcher and never outside. A short timeout keeps a
 * slow server from holding the visitor's request: the inquiry is already
 * stored, and a failure is retried from the Admin or the command line.
 */
final readonly class SmtpMailer implements Mailer
{
    public function __construct(
        private SmtpSettings $smtp,
        private string $from,
        private int $timeout = 10,
    ) {}

    public function send(string $to, string $subject, string $body): void
    {
        if (!$this->smtp->configured()) {
            throw new RuntimeException('No SMTP server is configured.');
        }
        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host = $this->smtp->host;
            $mail->Port = $this->smtp->port;
            $mail->Timeout = $this->timeout;
            $mail->SMTPAuth = $this->smtp->user !== '';
            $mail->Username = $this->smtp->user;
            $mail->Password = $this->smtp->password;
            $mail->SMTPSecure = match ($this->smtp->encryption) {
                'tls' => PHPMailer::ENCRYPTION_STARTTLS,
                'ssl' => PHPMailer::ENCRYPTION_SMTPS,
                default => '',
            };
            $mail->CharSet = PHPMailer::CHARSET_UTF8;
            $mail->Encoding = PHPMailer::ENCODING_BASE64;
            $mail->setFrom($this->from, 'Polad Charkhesh Nova');
            $mail->addAddress($to);
            $mail->Subject = $subject;
            $mail->Body = $body;
            $mail->isHTML(false);
            $mail->send();
        } catch (MailerException $e) {
            // ErrorInfo names the failing step; it never contains the password.
            throw new RuntimeException('SMTP: ' . ($mail->ErrorInfo !== '' ? $mail->ErrorInfo : $e->getMessage()), 0, $e);
        }
    }
}
