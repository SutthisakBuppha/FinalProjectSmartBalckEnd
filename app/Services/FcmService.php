<?php

namespace App\Services;

use App\Models\DriverFcmToken;
use Google\Client as GoogleClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;


class FcmService
{
    public function sendPushToDriver(string $driverId, string $title, string $body, array $data = []): void
    {
        $tokens = DriverFcmToken::where('driver_id', $driverId)->get();
        if ($tokens->isEmpty()) return;

        $accessToken = $this->getAccessToken();
        if (!$accessToken) return;

        $projectId = config('services.firebase.project_id', env('FIREBASE_PROJECT_ID'));
        $url = "https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send";

        foreach ($tokens as $item) {
            $payload = [
                'message' => [
                    'token' => $item->token,
                    'notification' => [
                        'title' => $title,
                        'body' => $body,
                    ],
                    'data' => array_map('strval', $data),
                    'android' => [
                        'priority' => 'HIGH',
                        'notification' => ['channel_id' => 'drive_guard_alerts', 'sound' => 'default'],
                    ],
                    'apns' => [
                        'payload' => ['aps' => ['sound' => 'default', 'badge' => 1]],
                    ],
                ],
            ];

            $res = Http::withHeaders([
                'Authorization' => "Bearer {$accessToken}",
                'Content-Type' => 'application/json',
            ])->post($url, $payload);

            if ($res->failed()) {
                $err = $res->json();
                Log::error("FCM Send Error [Token: {$item->fcm_token_id}]", $err);

                // ลบ Token ที่หมดอายุหรือยกเลิกการติดตั้งแล้วอัตโนมัติ
                if (isset($err['error']['details'])) {
                    foreach ($err['error']['details'] as $detail) {
                        if (in_array($detail['errorCode'] ?? '', ['UNREGISTERED', 'INVALID_ARGUMENT'])) {
                            DriverFcmToken::where('fcm_token_id', $item->fcm_token_id)->delete();
                        }
                    }
                }
            }
        }
    }

    private function getAccessToken(): ?string
    {
        $path = storage_path('app/firebase/service-account.json');
        if (!file_exists($path)) {
            Log::error("FCM Error: ไม่พบไฟล์ {$path}");
            return null;
        }

        try {
            $client = new GoogleClient();
            $client->setAuthConfig($path);
            $client->addScope('https://www.googleapis.com/auth/firebase.messaging');
            $token = $client->fetchAccessTokenWithAssertion();
            return $token['access_token'] ?? null;
        } catch (\Exception $e) {
            Log::error('FCM Token Exception: ' . $e->getMessage());
            return null;
        }
    }
}
