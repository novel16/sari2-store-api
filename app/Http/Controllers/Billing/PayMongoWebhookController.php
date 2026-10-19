<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Jobs\Billing\ProcessPayMongoEvent;
use App\Models\Billing\PayMongoWebhookEvent;
use App\Services\Payments\PayMongo\PayMongoConfigurationException;
use App\Services\Payments\PayMongo\PayMongoPayloadException;
use App\Services\Payments\PayMongo\PayMongoSignatureException;
use App\Services\Payments\PayMongo\PayMongoWebhook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class PayMongoWebhookController extends Controller
{
    public function __invoke(Request $request, PayMongoWebhook $webhook): JsonResponse
    {
        $body = $request->getContent();
        if (strlen($body) > 1048576) {
            return response()->json(['code' => 'webhook_too_large'], 413);
        }
        try {
            $verified = $webhook->verify($body, $request->header('Paymongo-Signature'));
        } catch (PayMongoConfigurationException) {
            return response()->json(['code' => 'webhook_not_configured'], 503);
        } catch (PayMongoSignatureException) {
            return response()->json(['code' => 'invalid_webhook_signature'], 401);
        } catch (PayMongoPayloadException) {
            return response()->json(['code' => 'invalid_webhook_payload'], 400);
        }

        $hash = hash('sha256', $body);
        $event = DB::transaction(fn () => PayMongoWebhookEvent::firstOrCreate(['event_id' => $verified['id']], [
            'event_type' => $verified['type'], 'livemode' => $verified['livemode'],
            'checkout_session_id' => $verified['session']['id'] ?? null, 'payload' => $verified['session'] ?? [],
            'payload_hash' => $hash, 'status' => $verified['type'] === 'checkout_session.payment.paid' ? 'received' : 'ignored',
            'received_at' => now(), 'processed_at' => $verified['type'] === 'checkout_session.payment.paid' ? null : now(),
        ]));
        if (! hash_equals($event->payload_hash, $hash)) {
            Log::warning('billing.webhook_identity_conflict', ['event' => $event->event_id]);

            return response()->json(['code' => 'webhook_identity_conflict'], 409);
        }
        if (! in_array($event->status, ['ignored', 'rejected'], true)) {
            try {
                // The inbox transaction is committed before queue dispatch. If dispatch
                // fails, retain the evidence and let PayMongo/reconciliation retry it.
                ProcessPayMongoEvent::dispatch($event->id);
            } catch (Throwable $error) {
                Log::error('billing.webhook_dispatch_failed', ['event' => $event->event_id, 'error_type' => $error::class]);

                return response()->json(['code' => 'webhook_processing_retry'], 503);
            }
        }

        return response()->json(['received' => true]);
    }
}
