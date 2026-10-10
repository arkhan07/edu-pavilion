<?php

namespace App\Http\Controllers;

use App\Services\Espay\EspayClient;
use App\Services\Espay\EspayDisbursementService;
use App\Services\Espay\EspayException;
use App\Services\Espay\SnapRequestValidator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Callback Espay Disbursement (Espay -> Merchant):
 *  - Transfer Confirmation (service 96): Espay meminta konfirmasi sebelum dana dikirim.
 *    Selain 2009600, Espay membatalkan transfer (4011701/4011801 "Invalid Transfer Confirmation").
 *  - Transfer Notification (service 97): hasil akhir transfer.
 */
class EspayDisbursementController extends Controller
{
    private const SERVICE_CONFIRMATION = '96';
    private const SERVICE_NOTIFICATION = '97';

    public function confirmation(Request $request)
    {
        $service = self::SERVICE_CONFIRMATION;

        try {
            $body = $this->validateIncoming($request, $service);
            $reference = $this->reference($body, $service);

            $merchantId = trim((string) data_get($body, 'merchantId', ''));
            $client = new EspayClient();
            if ($merchantId !== '' and !in_array(strtoupper($merchantId), array_map('strtoupper', array_filter([$client->merchantCode(), $client->disbursementPartnerId()])), true)) {
                throw new EspayException(404, $service, '08', 'Invalid Merchant');
            }

            $data = app(EspayDisbursementService::class)->confirm($reference, $this->amount($body), $service, $body);

            return response()->json(array_merge([
                'responseCode' => '200' . $service . '00',
                'responseMessage' => 'Successful',
            ], $data), 200, [], JSON_UNESCAPED_SLASHES);
        } catch (EspayException $e) {
            return $this->errorResponse($request, $e);
        } catch (\Throwable $e) {
            Log::channel('espay')->error('[Payout] confirmation error', ['message' => $e->getMessage()]);

            return $this->errorResponse($request, new EspayException(500, $service, '00', 'General Error'));
        }
    }

    public function notification(Request $request)
    {
        $service = self::SERVICE_NOTIFICATION;

        try {
            $body = $this->validateIncoming($request, $service);
            $reference = $this->reference($body, $service);
            $disbursement = app(EspayDisbursementService::class);

            $payout = EspayDisbursementService::findByReference($reference);
            if (empty($payout)) {
                throw new EspayException(404, $service, '12', 'invalid partnerReferenceNo');
            }

            $amount = $this->amount($body);
            if ($amount !== null and $amount !== '' and abs((float) $amount - (float) $payout->amount) > 0.001) {
                throw new EspayException(404, $service, '13', 'Invalid Amount');
            }

            // latestTransactionStatus: 00 Success, 01 Init, 03 Pending, 05 Canceled, 06 Failed
            $status = trim((string) data_get($body, 'latestTransactionStatus', ''));
            if ($status === '') {
                throw new EspayException(400, $service, '02', 'Invalid Mandatory Field {latestTransactionStatus}');
            }

            $data = ['source' => 'notification', 'status' => $status, 'body' => $body];

            if (in_array($status, EspayDisbursementService::STATUS_SUCCESS, true)) {
                $disbursement->complete($payout, true, $data);
            } elseif (in_array($status, EspayDisbursementService::STATUS_FAILED, true)) {
                $disbursement->complete($payout, false, $data);
            } else {
                // status antara (01/02/03): tunggu notifikasi berikutnya / cek status
                Log::channel('espay')->info('[Payout] notification status antara', ['payout_id' => $payout->id, 'status' => $status]);
            }

            return response()->json([
                'responseCode' => '200' . $service . '00',
                'responseMessage' => 'Successful',
            ], 200, [], JSON_UNESCAPED_SLASHES);
        } catch (EspayException $e) {
            return $this->errorResponse($request, $e);
        } catch (\Throwable $e) {
            Log::channel('espay')->error('[Payout] notification error', ['message' => $e->getMessage()]);

            return $this->errorResponse($request, new EspayException(500, $service, '00', 'General Error'));
        }
    }

    /**
     * @throws EspayException
     */
    private function validateIncoming(Request $request, string $service): array
    {
        $client = new EspayClient();

        return SnapRequestValidator::validate($request, $service, [], [$client->merchantCode(), $client->disbursementPartnerId()]);
    }

    /**
     * @throws EspayException
     */
    private function reference(array $body, string $service): string
    {
        // Confirmation: partnerReferenceNo; Notification: originalPartnerReferenceNo
        $reference = trim((string) (data_get($body, 'partnerReferenceNo') ?? data_get($body, 'originalPartnerReferenceNo') ?? ''));

        if ($reference === '') {
            throw new EspayException(400, $service, '02', 'Invalid Mandatory Field {partnerReferenceNo}');
        }

        if (!preg_match('/^[A-Za-z0-9]{1,64}$/', $reference)) {
            throw new EspayException(400, $service, '01', 'Invalid Field Format {partnerReferenceNo}');
        }

        return $reference;
    }

    private function amount(array $body): ?string
    {
        $value = data_get($body, 'amount.value') ?? data_get($body, 'transAmount.value');

        return $value === null ? null : (string) $value;
    }

    private function errorResponse(Request $request, EspayException $e)
    {
        Log::channel('espay')->warning('[Payout] SNAP callback rejected', [
            'response_code' => $e->responseCode,
            'message' => $e->getMessage(),
            'external_id' => $request->header('X-EXTERNAL-ID'),
            'partner_reference_no' => $request->json('partnerReferenceNo') ?? $request->json('originalPartnerReferenceNo'),
        ]);

        return response()->json([
            'responseCode' => $e->responseCode,
            'responseMessage' => $e->getMessage(),
        ], $e->httpCode, [], JSON_UNESCAPED_SLASHES);
    }
}
