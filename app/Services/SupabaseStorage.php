<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class SupabaseStorage
{
    public function upload(UploadedFile $file, string $path): string
    {
        $url = rtrim((string) config('services.supabase.url'), '/');
        $key = (string) config('services.supabase.secret_key');
        $bucket = (string) config('services.supabase.bucket');

        if ($url === '' || $key === '' || $bucket === '') {
            throw new RuntimeException('Supabase Storage is not configured.');
        }

        $mimeType = $file->getMimeType() ?: 'application/octet-stream';
        $response = Http::withHeaders([
            'apikey' => $key,
            'Authorization' => "Bearer {$key}",
            'Content-Type' => $mimeType,
            'x-upsert' => 'false',
        ])->withBody(file_get_contents($file->getRealPath()), $mimeType)
            ->put("{$url}/storage/v1/object/{$bucket}/{$path}");

        if ($response->failed()) {
            throw new RuntimeException('Supabase upload failed: ' . $response->body());
        }

        return "{$url}/storage/v1/object/public/{$bucket}/{$path}";
    }

    public function delete(string $path): void
    {
        $url = rtrim((string) config('services.supabase.url'), '/');
        $key = (string) config('services.supabase.secret_key');
        $bucket = (string) config('services.supabase.bucket');

        if ($url === '' || $key === '' || $bucket === '' || $path === '') {
            return;
        }

        $response = Http::withHeaders([
            'apikey' => $key,
            'Authorization' => "Bearer {$key}",
        ])->delete("{$url}/storage/v1/object/{$bucket}", [
            'prefixes' => [$path],
        ]);

        if ($response->failed() && $response->status() !== 404) {
            throw new RuntimeException('Supabase delete failed: ' . $response->body());
        }
    }
}
