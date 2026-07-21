<?php

declare(strict_types=1);

namespace GSH\Fantasy;

use RuntimeException;

final class SmtpClient
{
    /** @var resource|null */
    private $socket = null;

    public function send(
        string $recipient,
        string $subject,
        string $body,
        string $fromAddress,
        string $fromName,
        string $replyTo
    ): void {
        $host = Config::get('SMTP_HOST');
        $port = (int) Config::get('SMTP_PORT', '587');
        $encryption = mb_strtolower(Config::get('SMTP_ENCRYPTION', 'tls'));
        $timeout = max(1, (int) Config::get('SMTP_TIMEOUT', '15'));

        if (!preg_match('/^[a-z0-9.-]+$/i', $host)) {
            throw new RuntimeException('SMTP_HOST ist nicht gültig.');
        }
        if ($port < 1 || $port > 65535) {
            throw new RuntimeException('SMTP_PORT ist nicht gültig.');
        }
        if (!in_array($encryption, ['', 'none', 'tls', 'ssl'], true)) {
            throw new RuntimeException('SMTP_ENCRYPTION muss tls, ssl oder none sein.');
        }
        foreach ([$recipient, $fromAddress, $replyTo] as $address) {
            if (!filter_var($address, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $address)) {
                throw new RuntimeException('Eine Mailadresse ist nicht gültig.');
            }
        }
        if (preg_match('/[\r\n]/', $fromName)) {
            throw new RuntimeException('MAIL_FROM_NAME enthält ungültige Zeichen.');
        }

        $context = stream_context_create([
            'ssl' => [
                'peer_name' => $host,
                'verify_peer' => true,
                'verify_peer_name' => true,
                'allow_self_signed' => false,
            ],
        ]);
        $scheme = $encryption === 'ssl' ? 'ssl' : 'tcp';
        $errorCode = 0;
        $errorMessage = '';
        $this->socket = @stream_socket_client(
            "{$scheme}://{$host}:{$port}",
            $errorCode,
            $errorMessage,
            $timeout,
            STREAM_CLIENT_CONNECT,
            $context
        );
        if (!is_resource($this->socket)) {
            throw new RuntimeException("SMTP-Verbindung fehlgeschlagen ({$errorCode}): {$errorMessage}");
        }
        stream_set_timeout($this->socket, $timeout);

        try {
            $this->expect([220], 'Verbindungsaufbau');
            $this->hello();

            if ($encryption === 'tls') {
                $this->command('STARTTLS', [220], 'TLS-Aushandlung');
                if (!stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new RuntimeException('SMTP-TLS konnte nicht aktiviert werden.');
                }
                $this->hello();
            }

            $username = Config::get('SMTP_USERNAME');
            if ($username !== '') {
                $this->command('AUTH LOGIN', [334], 'SMTP-Anmeldung');
                $this->command(base64_encode($username), [334], 'SMTP-Benutzername');
                $this->command(base64_encode(Config::get('SMTP_PASSWORD')), [235], 'SMTP-Passwort');
            }

            $this->command("MAIL FROM:<{$fromAddress}>", [250], 'Absender');
            $this->command("RCPT TO:<{$recipient}>", [250, 251], 'Empfänger');
            $this->command('DATA', [354], 'Nachrichteninhalt');

            $message = $this->message($recipient, $subject, $body, $fromAddress, $fromName, $replyTo);
            $message = preg_replace('/^\./m', '..', $message) ?? $message;
            $this->write($message . "\r\n.\r\n");
            $this->expect([250], 'Versand');
            $this->write("QUIT\r\n");
        } finally {
            fclose($this->socket);
            $this->socket = null;
        }
    }

    private function hello(): void
    {
        $hostname = (string) parse_url(Config::get('APP_URL'), PHP_URL_HOST);
        if (!preg_match('/^[a-z0-9.-]+$/i', $hostname)) {
            $hostname = 'localhost';
        }
        $this->command("EHLO {$hostname}", [250], 'SMTP-Begrüßung');
    }

    private function message(
        string $recipient,
        string $subject,
        string $body,
        string $fromAddress,
        string $fromName,
        string $replyTo
    ): string {
        $host = (string) parse_url(Config::get('APP_URL'), PHP_URL_HOST);
        if (!preg_match('/^[a-z0-9.-]+$/i', $host)) {
            $host = 'localhost';
        }
        $encodedName = '=?UTF-8?B?' . base64_encode($fromName) . '?=';
        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $normalizedBody = preg_replace("/\r\n|\r|\n/", "\r\n", $body) ?? $body;
        $headers = [
            'Date: ' . date(DATE_RFC2822),
            "From: {$encodedName} <{$fromAddress}>",
            "Reply-To: {$replyTo}",
            "To: {$recipient}",
            "Subject: {$encodedSubject}",
            'Message-ID: <' . bin2hex(random_bytes(16)) . "@{$host}>",
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: quoted-printable',
            'X-Mailer: GSH Fantasy Manager',
        ];

        return implode("\r\n", $headers) . "\r\n\r\n" . quoted_printable_encode($normalizedBody);
    }

    /** @param list<int> $codes */
    private function command(string $command, array $codes, string $context): void
    {
        if (preg_match('/[\r\n]/', $command)) {
            throw new RuntimeException('Ungültiger SMTP-Befehl.');
        }
        $this->write($command . "\r\n");
        $this->expect($codes, $context);
    }

    private function write(string $data): void
    {
        if (!is_resource($this->socket)) {
            throw new RuntimeException('SMTP-Verbindung ist nicht geöffnet.');
        }
        $length = strlen($data);
        $written = 0;
        while ($written < $length) {
            $result = fwrite($this->socket, substr($data, $written));
            if ($result === false || $result === 0) {
                throw new RuntimeException('SMTP-Verbindung wurde beim Schreiben unterbrochen.');
            }
            $written += $result;
        }
    }

    /** @param list<int> $allowedCodes */
    private function expect(array $allowedCodes, string $context): void
    {
        [$code, $response] = $this->response();
        if (!in_array($code, $allowedCodes, true)) {
            throw new RuntimeException("{$context} fehlgeschlagen: SMTP {$code} {$response}");
        }
    }

    /** @return array{int, string} */
    private function response(): array
    {
        if (!is_resource($this->socket)) {
            throw new RuntimeException('SMTP-Verbindung ist nicht geöffnet.');
        }

        $lines = [];
        $code = 0;
        do {
            $line = fgets($this->socket, 8192);
            if ($line === false) {
                $metadata = stream_get_meta_data($this->socket);
                throw new RuntimeException(!empty($metadata['timed_out'])
                    ? 'Zeitüberschreitung bei der SMTP-Antwort.'
                    : 'SMTP-Verbindung wurde unerwartet geschlossen.');
            }
            $lines[] = trim($line);
            if (!preg_match('/^(\d{3})([ -])/', $line, $matches)) {
                throw new RuntimeException('Der SMTP-Server hat eine ungültige Antwort gesendet.');
            }
            $code = (int) $matches[1];
        } while ($matches[2] === '-');

        return [$code, implode(' | ', $lines)];
    }
}
