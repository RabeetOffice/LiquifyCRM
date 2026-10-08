<?php

declare(strict_types=1);

namespace App\Modules\Sites\Support;

/**
 * Reads the TLS certificate of a host (ported from LeadsHub's ssl_days_left).
 * Not final so tests can replace it.
 */
class CertificateReader
{
    /**
     * @return array{ok: bool, days: int, expires: string, issuer: string, error: string}
     */
    public function read(string $host, int $port = 443, int $timeout = 8): array
    {
        $context = stream_context_create(['ssl' => [
            'capture_peer_cert' => true,
            'verify_peer' => true,
            'verify_peer_name' => true,
            'SNI_enabled' => true,
        ]]);

        $errno = 0;
        $error = '';
        $socket = @stream_socket_client("ssl://{$host}:{$port}", $errno, $error, $timeout, STREAM_CLIENT_CONNECT, $context);

        if ($socket === false) {
            return ['ok' => false, 'days' => 0, 'expires' => '', 'issuer' => '', 'error' => $error !== '' ? $error : "connect failed ({$errno})"];
        }

        $params = stream_context_get_params($socket);
        fclose($socket);

        $certificate = $params['options']['ssl']['peer_certificate'] ?? null;
        $info = $certificate ? openssl_x509_parse($certificate) : false;

        if ($info === false) {
            return ['ok' => false, 'days' => 0, 'expires' => '', 'issuer' => '', 'error' => 'no certificate returned'];
        }

        $validTo = (int) ($info['validTo_time_t'] ?? 0);

        return [
            'ok' => true,
            'days' => (int) floor(($validTo - time()) / 86400),
            'expires' => date('j M Y', $validTo),
            'issuer' => (string) ($info['issuer']['O'] ?? $info['issuer']['CN'] ?? ''),
            'error' => '',
        ];
    }
}
