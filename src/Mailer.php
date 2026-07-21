<?php

declare(strict_types=1);

namespace GSH\Fantasy;

final class Mailer
{
    public function sendAssignment(array $participant, array $league, array $season): array
    {
        $subject = (string) $season['email_subject'];
        $intro = trim((string) ($season['email_intro'] ?? ''));
        $draft = empty($season['draft_at']) ? 'wird noch bekannt gegeben' : date('d.m.Y \u\m H:i \U\h\r', strtotime($season['draft_at']));
        $inviteUrl = (string) ($league['invite_url'] ?? '');

        $lines = [
            'Moin ' . $participant['name'] . ',',
            '',
            $intro !== '' ? $intro : 'deine Zuteilung für die GSH Fantasy Football Saison steht fest.',
            '',
            'Liga: ' . $league['name'],
            'Draft: ' . $draft,
            'Sleeper-Account: ' . $participant['sleeper_username'],
            '',
            $inviteUrl !== '' ? 'Liga beitreten: ' . $inviteUrl : 'Den Einladungslink erhältst du separat.',
            '',
            'Bitte tritt ausschließlich der dir zugeteilten Liga bei.',
            '',
            'GO HAWKS!',
            'German Sea Hawkers',
        ];

        return $this->send((string) $participant['email'], $subject, implode("\r\n", $lines));
    }

    public function sendTest(string $recipient): array
    {
        return $this->send(
            $recipient,
            'Testmail · GSH Fantasy Manager',
            "Moin,\r\n\r\nder SMTP-Versand des GSH Fantasy Managers funktioniert.\r\n\r\nGO HAWKS!\r\nGerman Sea Hawkers"
        );
    }

    private function send(string $recipient, string $subject, string $body): array
    {
        $from = Config::get('MAIL_FROM');
        $fromName = Config::get('MAIL_FROM_NAME', 'GSH Fantasy');
        $transport = mb_strtolower(Config::get('MAIL_TRANSPORT', 'mail'));

        if ($transport === 'smtp') {
            try {
                (new SmtpClient())->send($recipient, $subject, $body, $from, $fromName, $from);
                return ['sent' => true, 'error' => null];
            } catch (\Throwable $exception) {
                return ['sent' => false, 'error' => $exception->getMessage()];
            }
        }
        if ($transport !== 'mail') {
            return ['sent' => false, 'error' => 'MAIL_TRANSPORT muss smtp oder mail sein.'];
        }

        $headers = [
            "From: {$fromName} <{$from}>",
            "Reply-To: {$from}",
            'Content-Type: text/plain; charset=UTF-8',
            'X-Mailer: GSH Fantasy Manager',
        ];

        $sent = mail($recipient, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, implode("\r\n", $headers));
        return ['sent' => $sent, 'error' => $sent ? null : 'PHP mail() hat die Nachricht nicht angenommen.'];
    }
}
