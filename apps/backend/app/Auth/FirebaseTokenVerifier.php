<?php

namespace App\Auth;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

class FirebaseTokenVerifier
{
    private const CERTS_URL = 'https://www.googleapis.com/robot/v1/metadata/x509/securetoken@system.gserviceaccount.com';

    /**
     * Verify a Firebase ID token using Google's published signing certificates.
     * Never trust the payload before RSA signature verification.
     */
    public function verify(?string $jwt): ?VerifiedFirebaseParent
    {
        $projectId = config('services.firebase.project_id');

        if (! is_string($projectId) || $projectId === '' || ! is_string($jwt) || strlen($jwt) > 8192) {
            return null;
        }

        $pieces = explode('.', $jwt);
        if (count($pieces) !== 3) {
            return null;
        }

        [$encodedHeader, $encodedPayload, $encodedSignature] = $pieces;
        $header = $this->decodePart($encodedHeader);
        $payload = $this->decodePart($encodedPayload);
        $signature = $this->decodeBase64Url($encodedSignature);

        if (! is_array($header) || ! is_array($payload) || $signature === null) {
            return null;
        }
        if (($header['alg'] ?? null) !== 'RS256' || ($header['typ'] ?? null) !== 'JWT') {
            return null;
        }
        $kid = $header['kid'] ?? null;
        if (! is_string($kid) || $kid === '' || strlen($kid) > 200) {
            return null;
        }

        try {
            $certificates = $this->certificates($kid);

            $pem = $certificates[$kid] ?? null;
            if (! is_string($pem) || ! str_contains($pem, 'BEGIN CERTIFICATE')) {
                return null;
            }
            if (openssl_verify($encodedHeader.'.'.$encodedPayload, $signature, $pem, OPENSSL_ALGO_SHA256) !== 1) {
                return null;
            }
        } catch (Throwable) {
            // Network and crypto failures must never grant access.
            return null;
        }

        $now = now()->timestamp;
        $iat = $payload['iat'] ?? null;
        $exp = $payload['exp'] ?? null;
        $authTime = $payload['auth_time'] ?? null;
        $uid = $payload['sub'] ?? null;
        $email = $payload['email'] ?? null;
        $notBefore = $payload['nbf'] ?? null;

        if (($payload['aud'] ?? null) !== $projectId ||
            ($payload['iss'] ?? null) !== 'https://securetoken.google.com/'.$projectId ||
            ! is_int($iat) || ! is_int($exp) || ! is_int($authTime) ||
            $iat > $now + 60 || $authTime > $now + 60 ||
            $iat <= 0 || $authTime <= 0 || $exp <= $now || $exp <= $iat || $authTime > $iat ||
            ($notBefore !== null && (! is_int($notBefore) || $notBefore > $now + 60)) ||
            ! is_string($uid) || $uid === '' || strlen($uid) > 128 ||
            ! is_string($email) || strlen($email) > 254 ||
            filter_var($email, FILTER_VALIDATE_EMAIL) === false ||
            ($payload['email_verified'] ?? null) !== true) {
            return null;
        }

        $name = $payload['name'] ?? '';
        if (! is_string($name) || trim($name) === '') {
            $name = explode('@', $email)[0];
        }
        $name = mb_substr(trim($name), 0, 100);

        return new VerifiedFirebaseParent($uid, $email, $name);
    }

    /** Respect Google's key lifetime and bound refreshes for unknown signing keys. */
    private function certificates(string $kid): array
    {
        $key = 'familyguard:firebase-id-certs:v2';
        $usable = static function ($entry) use ($kid): bool {
            return is_array($entry) && is_array($entry['certificates'] ?? null)
                && ($entry['expires_at'] ?? 0) > now()->timestamp
                && (isset($entry['certificates'][$kid]) || ($entry['refreshed_at'] ?? 0) > now()->timestamp - 60);
        };
        $cached = Cache::get($key);
        if ($usable($cached)) {
            return $cached['certificates'];
        }

        // The default file cache provides a cross-process lock on this host.
        // Multi-host deployments must configure a shared lock-capable cache.
        return Cache::lock($key.':refresh', 12)->block(3, function () use ($key, $usable): array {
            $cached = Cache::get($key);
            if ($usable($cached)) {
                return $cached['certificates'];
            }
            $response = Http::connectTimeout(3)->timeout(8)->withoutRedirecting()->get(self::CERTS_URL)->throw();
            $certificates = $response->json();
            if (! is_array($certificates) || $certificates === [] || count($certificates) > 100) {
                throw new \RuntimeException('Firebase signing certificates unavailable');
            }
            foreach ($certificates as $kid => $pem) {
                if (! is_string($kid) || ! is_string($pem) || openssl_x509_read($pem) === false) {
                    throw new \RuntimeException('Invalid Firebase signing certificate response');
                }
            }
            $control = $response->header('Cache-Control');
            $ttl = preg_match('/max-age=(\d+)/i', $control, $match) ? min(3600, (int) $match[1]) : 60;
            $ttl = max(0, $ttl - max(0, (int) $response->header('Age')));
            if (preg_match('/no-store|no-cache/i', $control)) {
                $ttl = 0;
            }
            if ($ttl > 0) {
                Cache::put($key, ['certificates' => $certificates, 'expires_at' => now()->timestamp + $ttl, 'refreshed_at' => now()->timestamp], $ttl);
            }

            return $certificates;
        });
    }

    private function decodePart(string $part): ?array
    {
        $decoded = $this->decodeBase64Url($part);
        if ($decoded === null) {
            return null;
        }
        $json = json_decode($decoded, true);

        return is_array($json) ? $json : null;
    }

    private function decodeBase64Url(string $part): ?string
    {
        if ($part === '' || preg_match('/[^A-Za-z0-9_-]/', $part) || strlen($part) % 4 === 1) {
            return null;
        }
        $decoded = base64_decode(strtr($part, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}
