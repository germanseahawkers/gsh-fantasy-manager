<?php

declare(strict_types=1);

namespace GSH\Fantasy;

use RuntimeException;

final class SleeperClient
{
    private const BASE_URL = 'https://api.sleeper.app/v1';

    public function user(string $username): array
    {
        $username = trim($username);
        if ($username === '' || strlen($username) > 100) {
            throw new RuntimeException('Bitte gib einen gültigen Sleeper-Namen ein.');
        }

        $response = $this->get('/user/' . rawurlencode($username));
        if (empty($response['user_id'])) {
            throw new RuntimeException('Dieser Sleeper-Name wurde nicht gefunden.');
        }

        return $response;
    }

    public function leagueUsers(string $leagueId): array
    {
        if (!preg_match('/^[0-9]+$/', $leagueId)) {
            return [];
        }

        $response = $this->get('/league/' . $leagueId . '/users', false);
        return array_is_list($response) ? $response : [];
    }

    private function get(string $path, bool $notFoundAsEmpty = true): array
    {
        $curl = curl_init(self::BASE_URL . $path);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'User-Agent: GSH-Fantasy-Manager/1.0'],
        ]);

        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if ($body === false || $status >= 500 || $status === 0) {
            throw new RuntimeException('Sleeper ist gerade nicht erreichbar. Bitte versuche es später erneut. ' . $error);
        }
        if ($status === 404) {
            if ($notFoundAsEmpty) {
                return [];
            }
            throw new RuntimeException('Die angegebene Sleeper-Liga wurde nicht gefunden.');
        }
        if ($status !== 200) {
            throw new RuntimeException('Sleeper konnte die Anfrage nicht verarbeiten.');
        }

        $decoded = json_decode((string) $body, true);
        if ($decoded === null && trim((string) $body) === 'null') {
            return [];
        }
        if (!is_array($decoded)) {
            throw new RuntimeException('Sleeper hat eine ungültige Antwort geliefert.');
        }
        return $decoded;
    }
}
