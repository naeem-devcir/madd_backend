<?php

namespace App\Jobs\Jobs\Webhook;

use App\Models\Integration\WebhookDelivery;
use App\Models\Integration\WebhookEndpoint;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ProcessWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $deliveryId;

    public $tries = 5;
    public $backoff = [30, 60, 120, 300, 600]; // 30s, 1m, 2m, 5m, 10m
    public $timeout = 30; // 30 seconds

    /**
     * Create a new job instance.
     */
    public function __construct(string $deliveryId)
    {
        $this->deliveryId = $deliveryId;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $delivery = WebhookDelivery::with('endpoint')->findOrFail($this->deliveryId);

        try {
            $startTime = microtime(true);

            // Send webhook request
            $response = Http::timeout(25)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'X-Webhook-Signature' => $this->generateSignature($delivery->payload, $delivery->endpoint->secret),
                    'X-Webhook-Id' => $delivery->id,
                    'X-Webhook-Event' => $delivery->event_type,
                    'X-Webhook-Attempt' => $delivery->attempt_count + 1,
                ])
                ->post($delivery->endpoint->url, $delivery->payload);

            $duration = (int)((microtime(true) - $startTime) * 1000);

            // Update delivery record
            $delivery->update([
                'response_status' => $response->status(),
                'response_body' => $response->body(),
                'duration_ms' => $duration,
                'status' => $response->successful() ? 'success' : 'failed',
                'attempt_count' => $delivery->attempt_count + 1,
            ]);

            if ($response->successful()) {
                Log::info('Webhook delivered successfully', [
                    'delivery_id' => $this->deliveryId,
                    'endpoint_id' => $delivery->endpoint_id,
                    'event_type' => $delivery->event_type,
                    'duration_ms' => $duration,
                ]);

                // Update endpoint last success time
                $delivery->endpoint->update([
                    'last_delivery_at' => now(),
                    'last_success_at' => now(),
                    'failure_count' => 0,
                ]);
            } else {
                Log::warning('Webhook delivery failed', [
                    'delivery_id' => $this->deliveryId,
                    'endpoint_id' => $delivery->endpoint_id,
                    'status_code' => $response->status(),
                    'attempt' => $delivery->attempt_count,
                ]);

                $this->handleFailure($delivery);
            }

        } catch (\Exception $e) {
            Log::error('Webhook delivery exception', [
                'delivery_id' => $this->deliveryId,
                'error' => $e->getMessage(),
                'attempt' => $delivery->attempt_count,
            ]);

            $delivery->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'attempt_count' => $delivery->attempt_count + 1,
            ]);

            $this->handleFailure($delivery);
        }
    }

    /**
     * Handle webhook failure and schedule retry.
     */
    protected function handleFailure(WebhookDelivery $delivery): void
    {
        $maxAttempts = 5;
        $currentAttempt = $delivery->attempt_count;

        if ($currentAttempt < $maxAttempts) {
            // Calculate next retry delay (exponential backoff)
            $delay = $this->backoff[$currentAttempt - 1] ?? 600;
            
            // Update endpoint failure count
            $delivery->endpoint->increment('failure_count');
            $delivery->endpoint->update(['last_delivery_at' => now()]);

            // Schedule retry
            self::dispatch($delivery->id)->delay(now()->addSeconds($delay));
            
            Log::info('Webhook retry scheduled', [
                'delivery_id' => $delivery->id,
                'attempt' => $currentAttempt + 1,
                'delay_seconds' => $delay,
            ]);
        } else {
            // Mark as permanently failed
            $delivery->update(['status' => 'failed']);
            
            Log::error('Webhook permanently failed after max attempts', [
                'delivery_id' => $delivery->id,
                'endpoint_id' => $delivery->endpoint_id,
                'max_attempts' => $maxAttempts,
            ]);

            // Notify endpoint owner
            $this->notifyEndpointOwner($delivery);
        }
    }

    /**
     * Generate webhook signature for verification.
     */
    protected function generateSignature(array $payload, string $secret): string
    {
        $payloadString = json_encode($payload);
        return hash_hmac('sha256', $payloadString, $secret);
    }

    /**
     * Notify endpoint owner about webhook failures.
     */
    protected function notifyEndpointOwner(WebhookDelivery $delivery): void
    {
        $endpoint = $delivery->endpoint;
        $vendor = $endpoint->vendor;

        if ($vendor && $vendor->user) {
            \App\Jobs\Jobs\Notification\SendVendorNotification::dispatch(
                $vendor->user,
                'webhook_failed',
                'Your webhook endpoint ' . $endpoint->url . ' has failed after ' . $delivery->attempt_count . ' attempts. Please check your endpoint configuration.'
            );
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::critical('Webhook processing job failed', [
            'delivery_id' => $this->deliveryId,
            'error' => $exception->getMessage(),
        ]);

        // Mark as failed in database
        WebhookDelivery::where('id', $this->deliveryId)->update([
            'status' => 'failed',
            'error_message' => 'Job failed: ' . $exception->getMessage(),
        ]);
    }
}