<?php

namespace App\Services;

use App\Models\User;
use App\Support\DiditKycSettings;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class DiditSessionClient
{
    /**
     * @return array{session_id: string, url: string}
     */
    public function create(User $user, string $callback): array
    {
        $payload = [
            'workflow_id' => DiditKycSettings::workflowId(),
            'vendor_data' => (string) $user->id,
            'callback' => $callback,
            'callback_method' => 'both',
            'language' => 'pt',
            'metadata' => [
                'user_id' => $user->id,
            ],
        ];

        $contact = array_filter([
            'email' => is_string($user->email) ? trim($user->email) : '',
        ], fn (string $value) => $value !== '');
        if ($contact !== []) {
            $payload['contact_details'] = $contact;
        }

        $expected = $this->expectedDetails($user);
        if ($expected !== []) {
            $payload['expected_details'] = $expected;
        }

        $response = Http::withHeaders([
            'x-api-key' => DiditKycSettings::apiKey(),
            'Accept' => 'application/json',
        ])->timeout(20)->post('https://verification.didit.me/v3/session/', $payload);

        if (! $response->successful()) {
            throw new RuntimeException('Didit recusou a abertura da sessão.');
        }

        $sessionId = $response->json('session_id');
        $url = $response->json('url');
        if (! is_string($sessionId) || $sessionId === '' || ! is_string($url) || $url === '') {
            throw new RuntimeException('Didit não devolveu o link da verificação.');
        }

        return [
            'session_id' => $sessionId,
            'url' => $url,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function expectedDetails(User $user): array
    {
        $name = trim((string) ($user->name ?? ''));
        if ($name === '') {
            return [];
        }

        $parts = preg_split('/\s+/u', $name) ?: [];
        $first = (string) array_shift($parts);
        $last = $parts !== [] ? implode(' ', $parts) : $first;

        $details = [
            'first_name' => $first,
            'last_name' => $last,
        ];

        if ($user->birth_date !== null) {
            $details['date_of_birth'] = $user->birth_date->format('Y-m-d');
        }

        return $details;
    }
}
