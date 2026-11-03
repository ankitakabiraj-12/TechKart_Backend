<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Env;
use RuntimeException;

class MailService
{
    public static function isConfigured(): bool
    {
        $enabled = Env::get('SMTP_ENABLED', true);
        if ($enabled === false || $enabled === 0 || $enabled === 'false' || $enabled === '0' || $enabled === 'off' || $enabled === 'no') {
            return false;
        }
        return trim((string)Env::get('SMTP_HOST', '')) !== '';
    }

    public static function sendPasswordResetCode(
        string $email,
        string $name,
        string $code,
        int $expiryMinutes = 10
    ): void {
        $safeName = trim($name) !== '' ? trim($name) : 'Customer';
        $body = "Hello {$safeName},\r\n\r\n"
            . "We received a request to reset the password for your TechKart account.\r\n\r\n"
            . "Your TechKart password reset verification code is:\r\n\r\n"
            . "  [ {$code} ]\r\n\r\n"
            . "This code is valid for {$expiryMinutes} minutes. For security, never share this code with anyone.\r\n\r\n"
            . "If you did not request a password reset, you can safely ignore this email.\r\n\r\n"
            . "Warm regards,\r\n"
            . "TechKart Security & Trust Team\r\n"
            . "https://techkart.in";

        self::send($email, 'TechKart: Your Password Reset Verification Code', $body);
    }

    public static function sendRegistrationOtp(
        string $email,
        string $name,
        string $code,
        int $expiryMinutes = 10
    ): void {
        $safeName = trim($name) !== '' ? trim($name) : 'Customer';
        $body = "Dear {$safeName},\r\n\r\n"
            . "Thank you for joining TechKart — Discover Tech. Shop Smart.\r\n\r\n"
            . "To complete your customer registration and verify your email address, please enter the following 6-digit verification code:\r\n\r\n"
            . "  [ {$code} ]\r\n\r\n"
            . "This verification code will expire in {$expiryMinutes} minutes.\r\n\r\n"
            . "If you did not initiate this registration, please disregard this email.\r\n\r\n"
            . "Warm regards,\r\n"
            . "TechKart Customer Experience Team\r\n"
            . "https://techkart.in";

        self::send($email, 'TechKart: Verify Your Email Address to Complete Registration', $body);
    }

    public static function send(string $toEmail, string $subject, string $body): void
    {
        if (!self::isConfigured()) {
            throw new RuntimeException('SMTP is not configured. Set SMTP_HOST and related mail settings.');
        }

        $toEmail = strtolower(trim($toEmail));
        if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Invalid recipient email address.');
        }

        $host = trim((string)Env::get('SMTP_HOST', ''));
        $port = (int)Env::get('SMTP_PORT', 587);
        $encryption = strtolower(trim((string)Env::get('SMTP_ENCRYPTION', 'tls')));
        $username = trim((string)Env::get('SMTP_USERNAME', ''));
        $password = (string)Env::get('SMTP_PASSWORD', '');
        $fromEmail = strtolower(trim((string)Env::get('SMTP_FROM_EMAIL', 'no-reply@techkart.local')));
        $fromName = trim((string)Env::get('SMTP_FROM_NAME', 'TechKart'));

        if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Invalid SMTP_FROM_EMAIL configuration.');
        }

        $transportHost = $encryption === 'ssl' ? 'ssl://' . $host : $host;
        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client(
            $transportHost . ':' . $port,
            $errno,
            $errstr,
            15,
            STREAM_CLIENT_CONNECT
        );

        if (!is_resource($socket)) {
            throw new RuntimeException("SMTP connection failed: {$errstr} ({$errno}).");
        }

        stream_set_timeout($socket, 15);

        try {
            self::expect($socket, self::readResponse($socket), [220], 'SMTP greeting');

            $ehloHost = gethostname() ?: 'localhost';
            $ehloHost = preg_replace('/[^a-zA-Z0-9.\-]/', '', $ehloHost) ?: 'localhost';
            $ehlo = self::command($socket, 'EHLO ' . $ehloHost, [250], 'EHLO');

            if ($encryption === 'tls') {
                if (stripos($ehlo, 'STARTTLS') === false) {
                    throw new RuntimeException('SMTP server does not advertise STARTTLS.');
                }
                self::command($socket, 'STARTTLS', [220], 'STARTTLS');

                if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new RuntimeException('Unable to start SMTP TLS encryption.');
                }

                $ehlo = self::command($socket, 'EHLO ' . $ehloHost, [250], 'EHLO after STARTTLS');
            }

            if ($username !== '') {
                if (stripos($ehlo, 'AUTH') !== false && stripos($ehlo, 'LOGIN') !== false) {
                    self::command($socket, 'AUTH LOGIN', [334], 'AUTH LOGIN');
                    self::command($socket, base64_encode($username), [334], 'SMTP username');
                    self::command($socket, base64_encode($password), [235], 'SMTP password');
                } else {
                    $authPayload = base64_encode("\0{$username}\0{$password}");
                    self::command($socket, 'AUTH PLAIN ' . $authPayload, [235], 'SMTP authentication');
                }
            }

            self::command($socket, 'MAIL FROM:<' . $fromEmail . '>', [250], 'MAIL FROM');
            self::command($socket, 'RCPT TO:<' . $toEmail . '>', [250, 251], 'RCPT TO');
            self::command($socket, 'DATA', [354], 'DATA');

            $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
            $domain = substr($fromEmail, strpos($fromEmail, '@') + 1);
            $headers = [
                'Date: ' . date(DATE_RFC2822),
                'From: ' . self::formatMailbox($fromName, $fromEmail),
                'To: <' . $toEmail . '>',
                'Subject: ' . $encodedSubject,
                'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $domain . '>',
                'MIME-Version: 1.0',
                'Content-Type: text/plain; charset=UTF-8',
                'Content-Transfer-Encoding: 8bit',
            ];

            $normalizedBody = str_replace(["\r\n", "\r"], "\n", $body);
            $normalizedBody = preg_replace('/^\./m', '..', $normalizedBody) ?? $normalizedBody;
            $message = implode("\r\n", $headers) . "\r\n\r\n"
                . str_replace("\n", "\r\n", $normalizedBody) . "\r\n.";

            self::writeAll($socket, $message);
            self::expect($socket, self::readResponse($socket), [250], 'message body');
            self::command($socket, 'QUIT', [221], 'QUIT');
        } finally {
            fclose($socket);
        }
    }

    private static function formatMailbox(string $name, string $email): string
    {
        if ($name === '') {
            return '<' . $email . '>';
        }

        $safeName = str_replace(['\\', '"', "\r", "\n"], ['\\\\', '\\"', '', ''], $name);
        return '"' . $safeName . '" <' . $email . '>';
    }

    private static function command($socket, string $command, array $expectedCodes, string $label): string
    {
        self::writeAll($socket, $command . "\r\n");
        $response = self::readResponse($socket);
        self::expect($socket, $response, $expectedCodes, $label);
        return $response;
    }

    private static function expect($socket, string $response, array $expectedCodes, string $label): void
    {
        $code = (int)substr($response, 0, 3);
        if (!in_array($code, $expectedCodes, true)) {
            $detail = trim(preg_replace('/\s+/', ' ', $response) ?? $response);
            throw new RuntimeException("SMTP {$label} failed: {$detail}");
        }
    }

    private static function readResponse($socket): string
    {
        $response = '';

        while (($line = fgets($socket, 2048)) !== false) {
            $response .= $line;
            if (strlen($line) >= 4 && $line[3] === ' ') {
                break;
            }
        }

        if ($response === '') {
            throw new RuntimeException('SMTP server closed the connection unexpectedly.');
        }

        return $response;
    }

    private static function writeAll($socket, string $data): void
    {
        $length = strlen($data);
        $written = 0;

        while ($written < $length) {
            $result = fwrite($socket, substr($data, $written));
            if ($result === false || $result === 0) {
                throw new RuntimeException('Unable to write to the SMTP connection.');
            }
            $written += $result;
        }
    }
}
