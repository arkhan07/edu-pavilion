<?php

namespace App\Services\Espay;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Validasi request SNAP dari Espay ke merchant (Inquiry/Payment VA, Transfer Confirmation/Notification):
 * header wajib -> format timestamp & external id -> JSON -> signature -> partner id -> X-EXTERNAL-ID unik per hari
 * -> field wajib di body.
 */
class SnapRequestValidator
{
    /**
     * @param string[] $partnerIds X-PARTNER-ID yang diterima
     * @return array body request
     * @throws EspayException
     */
    public static function validate(Request $request, string $service, array $mandatoryFields, array $partnerIds): array
    {
        $client = new EspayClient();

        foreach (['X-TIMESTAMP', 'X-SIGNATURE', 'X-EXTERNAL-ID', 'X-PARTNER-ID', 'CHANNEL-ID'] as $header) {
            if (trim((string) $request->header($header)) === '') {
                throw new EspayException(400, $service, '02', "Invalid Mandatory Field {{$header}}");
            }
        }

        $timestamp = (string) $request->header('X-TIMESTAMP');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?([+-]\d{2}:?\d{2}|Z)$/', $timestamp)) {
            throw new EspayException(400, $service, '01', 'Invalid Field Format {X-TIMESTAMP}');
        }

        // Espay mengirim angka atau UUID (contoh: b2acead4-6dd6-41b0-b484-ea1c9f9e8b79)
        if (!preg_match('/^[A-Za-z0-9-]{1,36}$/', (string) $request->header('X-EXTERNAL-ID'))) {
            throw new EspayException(400, $service, '01', 'Invalid Field Format {X-EXTERNAL-ID}');
        }

        $rawBody = (string) $request->getContent();
        $body = json_decode($rawBody, true);
        if (!is_array($body)) {
            throw new EspayException(400, $service, '01', 'Invalid Field Format {body}');
        }

        $relativeUrl = parse_url($request->getRequestUri(), PHP_URL_PATH) ?: '/' . ltrim($request->path(), '/');

        if (!$client->verifyIncoming($request->method(), $relativeUrl, $rawBody, $timestamp, (string) $request->header('X-SIGNATURE'))) {
            throw new EspayException(401, $service, '00', 'Unauthorized Signature');
        }

        $partnerId = trim((string) $request->header('X-PARTNER-ID'));
        $accepted = array_filter(array_map(fn ($id) => strtoupper((string) $id), $partnerIds));
        if (!in_array(strtoupper($partnerId), $accepted, true)) {
            throw new EspayException(401, $service, '00', 'Unauthorized. [Unknown client]');
        }

        // X-EXTERNAL-ID harus unik dalam hari yang sama
        $externalKey = 'espay.external_id.' . $service . '.' . now()->format('Ymd') . '.' . $request->header('X-EXTERNAL-ID');
        if (!Cache::add($externalKey, 1, now()->endOfDay())) {
            throw new EspayException(409, $service, '00', 'Conflict');
        }

        foreach ($mandatoryFields as $field) {
            $value = data_get($body, $field);
            if (!is_scalar($value) or trim((string) $value) === '') {
                throw new EspayException(400, $service, '02', "Invalid Mandatory Field {{$field}}");
            }
        }

        return $body;
    }
}
