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

        $headers = [
            'From: ' . Config::get('MAIL_FROM_NAME', 'GSH Fantasy') . ' <' . Config::get('MAIL_FROM') . '>',
            'Reply-To: ' . Config::get('MAIL_FROM'),
            'Content-Type: text/plain; charset=UTF-8',
            'X-Mailer: GSH Fantasy Manager',
        ];

        $sent = mail((string) $participant['email'], '=?UTF-8?B?' . base64_encode($subject) . '?=', implode("\r\n", $lines), implode("\r\n", $headers));
        return ['sent' => $sent, 'error' => $sent ? null : 'PHP mail() hat die Nachricht nicht angenommen.'];
    }
}
