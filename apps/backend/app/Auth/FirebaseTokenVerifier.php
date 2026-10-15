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
            $certificates = Cache::remember('familyguard:firebase-id-certs', now()->addMinutes(30), function (): array {
                $response = Http::timeout(8)->get(self::CERTS_URL)->throw();
                $certs = $response->json();
                if (! is_array($certs)) {
                    throw new \RuntimeException('Firebase signing certificates unavailable');
                }
                return $certs;
            });

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

        $now = time();
        $iat = $payload['iat'] ?? null;
        $exp = $payload['exp'] ?? null;
        $authTime = $payload['auth_time'] ?? null;
        $uid = $payload['sub'] ?? null;
        $email = $payload['email'] ?? null;

        if (($payload['aud'] ?? null) !== $projectId ||
            ($payload['iss'] ?? null) !== 'https://securetoken.google.com/'.$projectId ||
            ! is_int($iat) || ! is_int($exp) || ! is_int($authTime) ||
            $iat > $now + 60 || $authTime > $now + 60 ||
            $iat <= 0 || $authTime <= 0 || $exp <= $now ||
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
