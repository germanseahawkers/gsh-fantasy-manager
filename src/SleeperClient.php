<?php

declare(strict_types=1);

namespace GSH\Fantasy;

use RuntimeException;

class SleeperClient
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
        if (!array_is_list($response)) {
            throw new RuntimeException('Sleeper hat eine ungültige Mitgliederliste geliefert.');
        }
        return $response;
    }

    public function leagueInfo(string $leagueId): array
    {
        if (!preg_match('/^[0-9]+$/', $leagueId)) {
            return [];
        }
        return $this->get('/league/' . $leagueId, false);
    }

    public function nflState(): array
    {
        return $this->get('/state/nfl', false);
    }

    public function leagueRosters(string $leagueId): array
    {
        if (!preg_match('/^[0-9]+$/', $leagueId)) {
            return [];
        }

        $response = $this->get('/league/' . $leagueId . '/rosters', false);
        if (!array_is_list($response)) {
            throw new RuntimeException('Sleeper hat eine ungültige Roster-Liste geliefert.');
        }
        return $response;
    }

    public function leagueMatchups(string $leagueId, int $week): array
    {
        if (!preg_match('/^[0-9]+$/', $leagueId) || $week < 1 || $week > 25) {
            return [];
        }

        $response = $this->get('/league/' . $leagueId . '/matchups/' . $week, false);
        if (!array_is_list($response)) {
            throw new RuntimeException('Sleeper hat eine ungültige Matchup-Liste geliefert.');
        }
        return $response;
    }

    /** @return array<string, array<string, mixed>> */
    public function nflPlayers(): array
    {
        $response = $this->get('/players/nfl', false, 30);
        if (array_is_list($response)) {
            throw new RuntimeException('Sleeper hat eine ungültige Spielerliste geliefert.');
        }
        return $response;
    }

    /** @return list<string> */
    public function leagueRosterOwners(string $leagueId): array
    {
        if (!preg_match('/^[0-9]+$/', $leagueId)) {
            return [];
        }

        $response = $this->leagueRosters($leagueId);

        $ownerIds = [];
        foreach ($response as $roster) {
            $ownerId = (string) ($roster['owner_id'] ?? '');
            if ($ownerId !== '') {
                $ownerIds[$ownerId] = true;
            }
        }
        return array_keys($ownerIds);
    }

    private function get(string $path, bool $notFoundAsEmpty = true, int $timeout = 8): array
    {
        $curl = curl_init(self::BASE_URL . $path);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
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
