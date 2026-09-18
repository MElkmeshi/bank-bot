<?php

namespace App\Services;

use App\Data\Firebase\FirebaseInstallationData;
use App\Enums\Bank;
use App\Exceptions\FirebaseBlockedException;
use Illuminate\Support\Facades\Http;

class FirebaseService
{
    /**
     * Register a Firebase installation for the bank's app and return its installation ID (FID).
     */
    public function getInstallationId(Bank $bank): string
    {
        $project = $bank->config('firebase_project');
        $apiKey = $bank->config('firebase_api_key');
        $appId = $bank->config('firebase_app_id');

        $payload = FirebaseInstallationData::from([
            'appId' => $appId,
            'authVersion' => 'FIS_v2',
            'sdkVersion' => 'a:17.0.0',
        ]);

        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
            'x-goog-api-key' => $apiKey,
        ])->post(
            "https://firebaseinstallations.googleapis.com/v1/projects/{$project}/installations",
            $payload->toArray()
        );

        if ($response->status() === 400 && $response->json('error.status') === 'INVALID_ARGUMENT') {
            throw new FirebaseBlockedException;
        }

        $response->throw();

        return $response->json('fid');
    }

    /**
     * Sign in anonymously with Firebase Auth and return the ID token.
     */
    public function anonymousIdToken(Bank $bank): string
    {
        $apiKey = $bank->config('firebase_api_key');

        $response = Http::acceptJson()->post(
            "https://identitytoolkit.googleapis.com/v1/accounts:signUp?key={$apiKey}",
            ['returnSecureToken' => true]
        );

        if ($response->status() === 400) {
            throw new FirebaseBlockedException;
        }

        $response->throw();

        return $response->json('idToken');
    }
}
