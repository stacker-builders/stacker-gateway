<?php

namespace App\Services\Meta;

use App\Jobs\Meta\SendMetaTrackingEventJob;
use App\Models\CheckoutSession;
use App\Models\MetaTrackingEvent;
use App\Models\Order;
use App\Models\Product;
use App\Services\AffiliateConversionPixels;
use Illuminate\Support\Facades\Log;

class MetaTrackingService
{
    public function __construct(
        private MetaUserDataHasher $hasher,
        private MetaEventContextResolver $contextResolver,
        private MetaConversionsApiClient $apiClient,
    ) {}

    public static function eventId(string $templateKey, array $replacements): string
    {
        $template = (string) config("meta_tracking.event_ids.{$templateKey}", '');

        foreach ($replacements as $key => $value) {
            $template = str_replace('{'.$key.'}', (string) $value, $template);
        }

        return $template;
    }

    public function isEventEnabled(string $eventName): bool
    {
        $events = config('meta_tracking.events', []);

        return (bool) ($events[$eventName]['enabled'] ?? false);
    }

    public function isServerEnabled(string $eventName): bool
    {
        if (! $this->isEventEnabled($eventName)) {
            return false;
        }

        $events = config('meta_tracking.events', []);

        return (bool) ($events[$eventName]['server'] ?? false);
    }

    /**
     * @return array<int, array{pixel_id: string, access_token: string, flags: array<string, mixed>}>
     */
    public function eligibleMetaEntries(array $pixels, string $eventName, string $triggerType = 'approved'): array
    {
        $meta = is_array($pixels['meta'] ?? null) ? $pixels['meta'] : [];
        if (! ($meta['enabled'] ?? false)) {
            return [];
        }

        $entries = isset($meta['entries']) && is_array($meta['entries']) ? $meta['entries'] : [];
        $out = [];

        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $pixelId = preg_replace('/\D/', '', trim((string) ($entry['pixel_id'] ?? ''))) ?? '';
            $accessToken = trim((string) ($entry['access_token'] ?? ''));
            if ($pixelId === '' || $accessToken === '') {
                continue;
            }
            if ($eventName === 'Purchase' && ! $this->shouldSendPurchaseForEntry($entry, $triggerType)) {
                continue;
            }
            $out[] = [
                'pixel_id' => $pixelId,
                'access_token' => $accessToken,
                'flags' => $entry,
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    public function shouldSendPurchaseForEntry(array $entry, string $triggerType): bool
    {
        if ($triggerType === 'pix' && ($entry['fire_purchase_on_pix'] ?? true) === false) {
            return false;
        }
        if ($triggerType === 'boleto' && ($entry['fire_purchase_on_boleto'] ?? true) === false) {
            return false;
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public function queueSessionEvent(
        CheckoutSession $session,
        string $eventName,
        string $eventId,
        array $overrides = [],
    ): array {
        if (! $this->isServerEnabled($eventName)) {
            return [];
        }

        // Persist attribution before queueing: the send job rebuilds context from the session only.
        $this->persistSessionAttribution($session, $overrides);
        $session->refresh();

        $session->loadMissing('product');
        $product = $session->product;
        if (! $product) {
            return [];
        }

        $pixels = $this->pixelsForSession($session, $product);
        $entries = $this->eligibleMetaEntries($pixels, $eventName);

        return $this->queueEventsForContext(
            entries: $entries,
            eventName: $eventName,
            eventId: $eventId,
            contextType: MetaTrackingEvent::CONTEXT_SESSION,
            contextId: (int) $session->id,
            tenantId: $session->tenant_id ? (int) $session->tenant_id : null,
            context: $this->contextResolver->forCheckoutSession($session, $overrides),
        );
    }

    /**
     * Server-side backup: queue PageView + InitiateCheckout on checkout GET when ad traffic detected.
     *
     * @param  array<string, mixed>  $pixels
     * @return array{pageview: array<int, int>, initiate_checkout: array<int, int>}
     */
    public function queueCheckoutLandingEvents(
        CheckoutSession $session,
        Product $product,
        array $pixels,
        float $value,
        string $currency = 'BRL',
        ?string $eventSourceUrl = null,
    ): array {
        if (! $this->shouldQueueCheckoutLandingBackup($session)) {
            return ['pageview' => [], 'initiate_checkout' => []];
        }

        $entries = $this->eligibleMetaEntries($pixels, 'PageView');
        if ($entries === []) {
            return ['pageview' => [], 'initiate_checkout' => []];
        }

        $contentIds = [];
        if ($session->checkout_slug) {
            $contentIds[] = (string) $session->checkout_slug;
        } elseif ($product->checkout_slug) {
            $contentIds[] = (string) $product->checkout_slug;
        }

        $overrides = array_filter([
            'value' => $value,
            'currency' => strtoupper($currency),
            'content_ids' => $contentIds,
            'content_name' => $product->name,
            'event_source_url' => $eventSourceUrl,
            'user_agent' => request()->userAgent(),
        ], fn ($v) => $v !== null && $v !== '');

        $token = (string) $session->session_token;
        $pageview = $this->queueSessionEvent(
            $session,
            'PageView',
            self::eventId('pageview', ['session_token' => $token]),
            $overrides,
        );
        $initiate = $this->queueSessionEvent(
            $session,
            'InitiateCheckout',
            self::eventId('initiate_checkout', ['session_token' => $token]),
            $overrides,
        );

        if (config('meta_tracking.debug')) {
            Log::debug('Meta checkout landing backup queued', [
                'checkout_session_id' => $session->id,
                'pageview_queued' => count($pageview),
                'initiate_checkout_queued' => count($initiate),
            ]);
        }

        return [
            'pageview' => $pageview,
            'initiate_checkout' => $initiate,
        ];
    }

    public function shouldQueueCheckoutLandingBackup(CheckoutSession $session): bool
    {
        if (trim((string) ($session->meta_fbclid ?? '')) !== '') {
            return true;
        }

        foreach (['utm_source', 'utm_medium', 'utm_campaign', 'src', 'sck'] as $key) {
            $value = $session->{$key} ?? null;
            if (is_string($value) && trim($value) !== '') {
                return true;
            }
        }

        return false;
    }

    public function queuePurchaseForOrder(Order $order): array
    {
        if (! $this->isServerEnabled('Purchase')) {
            return [];
        }

        $order->loadMissing(['product', 'user', 'checkoutSession']);

        $meta = is_array($order->metadata) ? $order->metadata : [];
        if (! empty($meta['meta_capi_sent_purchase'])) {
            return [];
        }

        $pixels = AffiliateConversionPixels::forOrder($order);
        $triggerType = $this->triggerTypeForOrder($order);
        $entries = $this->eligibleMetaEntries($pixels, 'Purchase', $triggerType);

        if ($entries === []) {
            $metaPixels = is_array($pixels['meta'] ?? null) ? $pixels['meta'] : [];
            $rawEntries = isset($metaPixels['entries']) && is_array($metaPixels['entries']) ? $metaPixels['entries'] : [];
            $hasTokenMissing = false;
            foreach ($rawEntries as $entry) {
                if (! is_array($entry)) {
                    continue;
                }
                $pixelId = preg_replace('/\D/', '', trim((string) ($entry['pixel_id'] ?? ''))) ?? '';
                $accessToken = trim((string) ($entry['access_token'] ?? ''));
                if ($pixelId !== '' && $accessToken === '') {
                    $hasTokenMissing = true;
                    break;
                }
            }

            $this->recordOrderSkippedReason($order, $hasTokenMissing ? 'missing_access_token' : 'no_eligible_pixels');

            return [];
        }

        $eventId = self::eventId('purchase', ['order_id' => $order->id]);

        return $this->queueEventsForContext(
            entries: $entries,
            eventName: 'Purchase',
            eventId: $eventId,
            contextType: MetaTrackingEvent::CONTEXT_ORDER,
            contextId: (int) $order->id,
            tenantId: $order->tenant_id ? (int) $order->tenant_id : null,
            context: $this->contextResolver->forOrder($order),
        );
    }

    /**
     * @param  array<int, array{pixel_id: string, access_token: string, flags: array<string, mixed>}>  $entries
     * @return array<int, int> MetaTrackingEvent IDs queued
     */
    private function queueEventsForContext(
        array $entries,
        string $eventName,
        string $eventId,
        string $contextType,
        int $contextId,
        ?int $tenantId,
        MetaEventContext $context,
    ): array {
        $queued = [];

        foreach ($entries as $entry) {
            $pixelId = $entry['pixel_id'];

            $record = MetaTrackingEvent::query()->firstOrCreate(
                [
                    'event_id' => $eventId,
                    'pixel_id' => $pixelId,
                ],
                [
                    'tenant_id' => $tenantId,
                    'event_name' => $eventName,
                    'context_type' => $contextType,
                    'context_id' => $contextId,
                    'status' => MetaTrackingEvent::STATUS_PENDING,
                    'attempts' => 0,
                ]
            );

            if ($record->status === MetaTrackingEvent::STATUS_SENT) {
                continue;
            }

            SendMetaTrackingEventJob::dispatch($record->id)
                ->onQueue((string) config('meta_tracking.queue'))
                ->afterResponse();
            $queued[] = $record->id;
        }

        return $queued;
    }

    /**
     * Send a single audit record to Meta Graph API.
     *
     * @return array{ok: bool, status: int|null, body: string|null, error: string|null}
     */
    public function sendTrackingEventRecord(MetaTrackingEvent $record): array
    {
        $record->loadMissing([]);

        $context = $this->resolveContextForRecord($record);
        if ($context === null) {
            return [
                'ok' => false,
                'status' => null,
                'body' => null,
                'error' => 'context_not_found',
            ];
        }

        $pixels = $this->pixelsForRecord($record);
        $entries = $this->eligibleMetaEntries(
            $pixels,
            $record->event_name,
            $record->event_name === 'Purchase' ? $this->triggerTypeForRecord($record) : 'approved',
        );

        $entry = null;
        foreach ($entries as $e) {
            if ($e['pixel_id'] === $record->pixel_id) {
                $entry = $e;
                break;
            }
        }

        if ($entry === null) {
            return [
                'ok' => false,
                'status' => null,
                'body' => null,
                'error' => 'pixel_not_eligible',
            ];
        }

        $payload = $this->buildPayload($record->event_name, $record->event_id, $context);

        return $this->apiClient->send($entry['pixel_id'], $entry['access_token'], $payload);
    }

    public function buildPayload(string $eventName, string $eventId, MetaEventContext $context): array
    {
        $customData = array_filter([
            'currency' => $context->currency,
            'value' => $context->value !== null ? round(max(0, $context->value), 2) : null,
            'content_type' => 'product',
            'content_ids' => $context->contentIds !== [] ? $context->contentIds : null,
            'content_name' => $context->contentName,
            'num_items' => $context->numItems,
            'order_id' => $eventName === 'Purchase' && $context->contentIds !== []
                ? ($context->contentIds[0] ?? null)
                : null,
        ], fn ($v) => $v !== null && $v !== '');

        $event = array_filter([
            'event_name' => $eventName,
            'event_time' => $context->eventTime ?? time(),
            'event_id' => $eventId,
            'action_source' => 'website',
            'event_source_url' => $context->eventSourceUrl,
            'user_data' => $this->hasher->buildUserData($context),
            'custom_data' => $customData !== [] ? $customData : null,
        ], fn ($v) => $v !== null && $v !== '');

        return ['data' => [$event]];
    }

    private function resolveContextForRecord(MetaTrackingEvent $record): ?MetaEventContext
    {
        if ($record->context_type === MetaTrackingEvent::CONTEXT_SESSION) {
            $session = CheckoutSession::query()->find($record->context_id);
            if (! $session) {
                return null;
            }

            return $this->contextResolver->forCheckoutSession($session);
        }

        if ($record->context_type === MetaTrackingEvent::CONTEXT_ORDER) {
            $order = Order::query()->find($record->context_id);
            if (! $order) {
                return null;
            }

            return $this->contextResolver->forOrder($order);
        }

        return null;
    }

    private function pixelsForRecord(MetaTrackingEvent $record): array
    {
        if ($record->context_type === MetaTrackingEvent::CONTEXT_SESSION) {
            $session = CheckoutSession::query()->with('product')->find($record->context_id);
            if (! $session || ! $session->product) {
                return Product::defaultConversionPixels();
            }

            return $this->pixelsForSession($session, $session->product);
        }

        if ($record->context_type === MetaTrackingEvent::CONTEXT_ORDER) {
            $order = Order::query()->find($record->context_id);
            if (! $order) {
                return Product::defaultConversionPixels();
            }

            return AffiliateConversionPixels::forOrder($order);
        }

        return Product::defaultConversionPixels();
    }

    private function pixelsForSession(CheckoutSession $session, Product $product): array
    {
        $ref = trim((string) ($session->affiliate_ref ?? ''));

        return AffiliateConversionPixels::forProductAndRef($product, $ref !== '' ? $ref : null);
    }

    private function triggerTypeForRecord(MetaTrackingEvent $record): string
    {
        if ($record->context_type !== MetaTrackingEvent::CONTEXT_ORDER) {
            return 'approved';
        }

        $order = Order::query()->find($record->context_id);

        return $order ? $this->triggerTypeForOrder($order) : 'approved';
    }

    public function triggerTypeForOrder(Order $order): string
    {
        $method = (string) ($order->payment_method ?? '');
        if ($method === 'boleto') {
            return 'boleto';
        }
        if (in_array($method, ['pix', 'pix_auto'], true)) {
            return 'pix';
        }

        return 'approved';
    }

    public function markOrderPurchaseSent(Order $order): void
    {
        $meta = is_array($order->metadata) ? $order->metadata : [];
        $meta['meta_capi_sent_purchase'] = true;
        $meta['meta_capi_sent_purchase_at'] = now()->toIso8601String();
        unset($meta['meta_capi_failed'], $meta['meta_capi_failed_at'], $meta['meta_capi_last_error'], $meta['meta_capi_skipped_reason']);
        $order->update(['metadata' => $meta]);
    }

    public function recordOrderSkippedReason(Order $order, string $reason): void
    {
        $meta = is_array($order->metadata) ? $order->metadata : [];
        if (($meta['meta_capi_skipped_reason'] ?? null) === $reason) {
            return;
        }

        $meta['meta_capi_skipped_reason'] = $reason;
        $meta['meta_capi_skipped_at'] = now()->toIso8601String();
        $order->update(['metadata' => $meta]);

        Log::warning('Meta CAPI purchase skipped', [
            'order_id' => $order->id,
            'tenant_id' => $order->tenant_id,
            'reason' => $reason,
        ]);
    }

    /**
     * Persist Meta attribution on checkout session from browser mirror.
     *
     * @param  array<string, mixed>  $data
     */
    public function persistSessionAttribution(CheckoutSession $session, array $data): void
    {
        $updates = [];
        $maxLengths = [
            'meta_fbp' => 512,
            'meta_fbc' => 512,
            'meta_user_agent' => 1024,
            'meta_page_url' => 2048,
        ];

        foreach (['fbp' => 'meta_fbp', 'fbc' => 'meta_fbc', 'user_agent' => 'meta_user_agent', 'event_source_url' => 'meta_page_url'] as $key => $column) {
            if (! isset($data[$key]) || ! is_string($data[$key])) {
                continue;
            }
            $value = trim($data[$key]);
            if ($value === '') {
                continue;
            }
            $max = $maxLengths[$column] ?? 2048;
            if (mb_strlen($value) > $max) {
                $value = mb_substr($value, 0, $max);
            }
            // Keep the first page URL for the session; later mirrors may be thank-you pages.
            if ($column === 'meta_page_url' && is_string($session->meta_page_url) && trim($session->meta_page_url) !== '') {
                continue;
            }
            $updates[$column] = $value;
        }

        if ($updates !== []) {
            $session->update($updates);
        }
    }

    /**
     * Merge session attribution into order metadata (form payload takes priority).
     *
     * @param  array<string, mixed>  $orderMetadata
     * @return array<string, mixed>
     */
    public function mergeSessionAttributionIntoOrder(CheckoutSession $session, array $orderMetadata): array
    {
        if (empty($orderMetadata['fbp']) && $session->meta_fbp) {
            $orderMetadata['fbp'] = $session->meta_fbp;
        }
        if (empty($orderMetadata['fbc']) && $session->meta_fbc) {
            $orderMetadata['fbc'] = $session->meta_fbc;
        }
        if (empty($orderMetadata['user_agent']) && $session->meta_user_agent) {
            $orderMetadata['user_agent'] = $session->meta_user_agent;
        }
        if (empty($orderMetadata['event_source_url']) && $session->meta_page_url) {
            $orderMetadata['event_source_url'] = $session->meta_page_url;
        }

        return $orderMetadata;
    }
}
