<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * CORS untuk endpoint SNAP Espay. Client Simulator di ASPI Dev Site memanggil endpoint
 * langsung dari browser sehingga butuh Access-Control-Allow-Origin/Headers: *.
 *
 * Setiap request + response juga dicatat ke storage/logs/espay-*.log
 * (header & body lengkap dibutuhkan untuk dokumen uji fungsional Espay).
 */
class EspaySnapCors
{
    public function handle(Request $request, Closure $next)
    {
        $response = $request->isMethod('OPTIONS') ? response('', 204) : $next($request);

        $response->headers->set('Access-Control-Allow-Origin', '*');
        $response->headers->set('Access-Control-Allow-Headers', '*');
        $response->headers->set('Access-Control-Allow-Methods', 'POST, OPTIONS');

        if (!$request->isMethod('OPTIONS')) {
            $headers = [];
            foreach (['X-TIMESTAMP', 'X-SIGNATURE', 'X-PARTNER-ID', 'X-EXTERNAL-ID', 'CHANNEL-ID', 'Content-Type', 'User-Agent'] as $name) {
                $headers[$name] = $request->header($name);
            }

            Log::channel('espay')->info(($request->isJson() ? '[SNAP IN] ' : '[NOTIF IN] ') . $request->method() . ' ' . $request->getPathInfo(), [
                'ip' => $request->header('CF-Connecting-IP') ?: $request->ip(),
                'headers' => $headers,
                // password notifikasi non-SNAP tidak ikut dicatat
                'body' => preg_replace('/(^|&)password=[^&]*/', '$1password=***', $request->getContent()),
                'response_status' => $response->getStatusCode(),
                'response_body' => $response->getContent(),
            ]);
        }

        return $response;
    }
}
