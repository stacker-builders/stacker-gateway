<?php

namespace App\Services\MetricsTracking;

use App\Jobs\EnrichMetricsEventGeoJob;
use App\Models\MetricsEvent;
use App\Models\MetricsSession;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductAffiliateEnrollment;
use App\Models\ProductCoproducer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class MetricsCaptureService
{
    public function __construct(
        private readonly MetricsGeoResolver $geoResolver,
    ) {}

    public function enabled(): bool
    {
        return (bool) config('metrics_tracking.enabled', true)
            && Schema::hasTable('metrics_events')
            && Schema::hasTable('metrics_sessions');
    }

    /**
     * Captura rápida (não deve lançar). Retorna session_key ou null.
     *
     * @param  array<string, mixed>  $payload
     */
    public function capture(Request $request, array $payload = []): ?string
    {
        if (! $this->enabled()) {
            return null;
        }

        try {
            return $this->captureUnsafe($request, $payload);
        } catch (\Throwable $e) {
            Log::warning('metrics.capture_failed', ['message' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function captureUnsafe(Request $request, array $payload): string
    {
        $eventName = (string) ($payload['event_name'] ?? MetricsEvent::PAGE_VIEW);
        $eventId = (string) ($payload['event_id'] ?? Str::uuid());

        if (MetricsEvent::query()->where('event_id', $eventId)->exists()) {
            $existing = MetricsEvent::query()->where('event_id', $eventId)->first();

            return (string) ($existing?->session_key ?? '');
        }

        $ua = (string) ($payload['user_agent'] ?? $request->userAgent() ?? '');
        $client = MetricsClientParser::fromUserAgent($ua);
        $ip = (string) ($payload['ip'] ?? MetricsClientParser::resolveClientIp($request));
        $ipHash = MetricsClientParser::hashIp($ip);
        $ipMasked = MetricsClientParser::maskIp($ip);
        $cfGeo = MetricsClientParser::geoFromCloudflareHeaders($request);

        $tracking = MetricsClientParser::trackingFromRequest(
            $request,
            is_array($payload['tracking'] ?? null) ? $payload['tracking'] : null
        );

        $sessionKey = $this->resolveSessionKey($request, $payload);
        $visitorKey = $this->resolveVisitorKey($request, $payload);

        $productId = isset($payload['product_id']) && is_string($payload['product_id']) && $payload['product_id'] !== ''
            ? $payload['product_id']
            : null;
        $tenantId = isset($payload['tenant_id']) ? (int) $payload['tenant_id'] : null;
        if ($tenantId === null && $productId) {
            $tenantId = Product::query()->where('id', $productId)->value('tenant_id');
            $tenantId = $tenantId !== null ? (int) $tenantId : null;
        }

        $affiliateRef = $tracking['ref'] ?? ($payload['affiliate_ref'] ?? null);
        $affiliateRef = is_string($affiliateRef) && $affiliateRef !== '' ? $affiliateRef : null;
        $affiliateUserId = isset($payload['affiliate_user_id']) ? (int) $payload['affiliate_user_id'] : null;
        if (! $affiliateUserId && $affiliateRef) {
            $affiliateUserId = $this->resolveAffiliateUserIdFromRef($affiliateRef, $productId);
        }
        $campaignCode = $tracking['campaign_code'] ?? $tracking['campaign'] ?? $tracking['utm_campaign'] ?? null;
        $campaignCode = is_string($campaignCode) ? Str::limit($campaignCode, 120, '') : null;

        $now = now();
        $session = MetricsSession::query()->where('session_key', $sessionKey)->first();
        if (! $session) {
            $session = MetricsSession::query()->create([
                'session_key' => $sessionKey,
                'visitor_key' => $visitorKey,
                'tenant_id' => $tenantId,
                'product_id' => $productId,
                'offer_id' => $payload['offer_id'] ?? null,
                'plan_id' => $payload['plan_id'] ?? null,
                'affiliate_user_id' => $affiliateUserId,
                'affiliate_ref' => $affiliateRef,
                'coproducer_user_id' => $payload['coproducer_user_id'] ?? null,
                'campaign_code' => $campaignCode,
                'landing_url' => Str::limit((string) ($payload['destination_url'] ?? $request->fullUrl()), 2048, ''),
                'referrer' => Str::limit((string) ($payload['referrer'] ?? $request->headers->get('referer') ?? ''), 2048, ''),
                'utm_source' => $tracking['utm_source'] ?? null,
                'utm_medium' => $tracking['utm_medium'] ?? null,
                'utm_campaign' => $tracking['utm_campaign'] ?? null,
                'utm_content' => $tracking['utm_content'] ?? null,
                'utm_term' => $tracking['utm_term'] ?? null,
                'fbclid' => $tracking['fbclid'] ?? null,
                'gclid' => $tracking['gclid'] ?? null,
                'ttclid' => $tracking['ttclid'] ?? null,
                'src' => $tracking['src'] ?? null,
                'sck' => $tracking['sck'] ?? null,
                'subid' => $tracking['subid'] ?? null,
                'subid2' => $tracking['subid2'] ?? null,
                'subid3' => $tracking['subid3'] ?? null,
                'tracking_params' => $tracking,
                'device_type' => $client['device_type'],
                'os_name' => $client['os_name'],
                'browser_name' => $client['browser_name'],
                'user_agent' => Str::limit($ua, 1024, ''),
                'ip_hash' => $ipHash,
                'ip_masked' => $ipMasked,
                'country' => $cfGeo['country'] ?? null,
                'region' => $cfGeo['region'] ?? null,
                'city' => $cfGeo['city'] ?? null,
                'first_touch_at' => $now,
                'last_touch_at' => $now,
                'events_count' => 0,
                'clicks_count' => 0,
            ]);
        } else {
            $this->mergeStickyAttribution($session, $tracking, $payload, $affiliateRef, $campaignCode, $productId, $tenantId);
            $this->fillSessionGeoIfEmpty($session, $cfGeo);
            $session->last_touch_at = $now;
            $session->save();
        }

        $isClick = in_array($eventName, [MetricsEvent::LINK_CLICKED, MetricsEvent::PAGE_VIEW, MetricsEvent::CHECKOUT_VIEW], true);

        $event = MetricsEvent::query()->create([
            'event_id' => $eventId,
            'event_name' => $eventName,
            'metrics_session_id' => $session->id,
            'session_key' => $session->session_key,
            'visitor_key' => $session->visitor_key,
            'tenant_id' => $session->tenant_id,
            'product_id' => $session->product_id,
            'offer_id' => $session->offer_id,
            'plan_id' => $session->plan_id,
            'order_id' => $payload['order_id'] ?? null,
            'checkout_session_id' => $payload['checkout_session_id'] ?? null,
            'affiliate_user_id' => $session->affiliate_user_id,
            'affiliate_ref' => $session->affiliate_ref,
            'coproducer_user_id' => $session->coproducer_user_id,
            'campaign_code' => $session->campaign_code,
            'destination_url' => Str::limit((string) ($payload['destination_url'] ?? $request->fullUrl()), 2048, ''),
            'referrer' => Str::limit((string) ($payload['referrer'] ?? $request->headers->get('referer') ?? ''), 2048, ''),
            'utm_source' => $session->utm_source,
            'utm_medium' => $session->utm_medium,
            'utm_campaign' => $session->utm_campaign,
            'utm_content' => $session->utm_content,
            'utm_term' => $session->utm_term,
            'fbclid' => $session->fbclid,
            'gclid' => $session->gclid,
            'ttclid' => $session->ttclid,
            'src' => $session->src,
            'sck' => $session->sck,
            'subid' => $session->subid,
            'subid2' => $session->subid2,
            'subid3' => $session->subid3,
            'tracking_params' => $session->tracking_params,
            'device_type' => $session->device_type,
            'os_name' => $session->os_name,
            'browser_name' => $session->browser_name,
            'user_agent' => $session->user_agent,
            'ip_hash' => $ipHash,
            'ip_masked' => $ipMasked,
            'country' => $session->country ?: ($cfGeo['country'] ?? null),
            'region' => $session->region ?: ($cfGeo['region'] ?? null),
            'city' => $session->city ?: ($cfGeo['city'] ?? null),
            'latitude' => $cfGeo['latitude'] ?? null,
            'longitude' => $cfGeo['longitude'] ?? null,
            'timezone' => $cfGeo['timezone'] ?? null,
            'conversion_status' => $payload['conversion_status'] ?? null,
            'amount' => $payload['amount'] ?? null,
            'currency' => $payload['currency'] ?? null,
            'properties' => is_array($payload['properties'] ?? null) ? $payload['properties'] : null,
            'occurred_at' => $payload['occurred_at'] ?? $now,
            'geo_enriched' => false,
        ]);

        MetricsSession::query()->whereKey($session->id)->update([
            'events_count' => $session->events_count + 1,
            'clicks_count' => $session->clicks_count + ($isClick ? 1 : 0),
            'last_touch_at' => $now,
        ]);

        $this->queueGeoEnrichment($event->id, $ip);

        $this->queueCookies($sessionKey, $visitorKey);

        return $sessionKey;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $tracking
     */
    private function mergeStickyAttribution(
        MetricsSession $session,
        array $tracking,
        array $payload,
        ?string $affiliateRef,
        ?string $campaignCode,
        ?string $productId,
        ?int $tenantId,
    ): void {
        // First-touch wins for UTMs; fill only empty fields.
        foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'fbclid', 'gclid', 'ttclid', 'src', 'sck', 'subid', 'subid2', 'subid3'] as $key) {
            if (empty($session->{$key}) && ! empty($tracking[$key])) {
                $session->{$key} = $tracking[$key];
            }
        }

        if (! $session->affiliate_ref && $affiliateRef) {
            $session->affiliate_ref = $affiliateRef;
        }
        if (! $session->campaign_code && $campaignCode) {
            $session->campaign_code = $campaignCode;
        }
        if (! $session->product_id && $productId) {
            $session->product_id = $productId;
        }
        if ($session->tenant_id === null && $tenantId !== null) {
            $session->tenant_id = $tenantId;
        }
        if (! $session->offer_id && ! empty($payload['offer_id'])) {
            $session->offer_id = $payload['offer_id'];
        }
        if (! $session->plan_id && ! empty($payload['plan_id'])) {
            $session->plan_id = $payload['plan_id'];
        }
        if (! $session->affiliate_user_id && ! empty($payload['affiliate_user_id'])) {
            $session->affiliate_user_id = $payload['affiliate_user_id'];
        }
        if (! $session->affiliate_user_id && ($session->affiliate_ref || $affiliateRef)) {
            $session->affiliate_user_id = $this->resolveAffiliateUserIdFromRef(
                (string) ($session->affiliate_ref ?: $affiliateRef),
                $session->product_id ?: $productId
            );
        }

        $merged = array_merge(is_array($session->tracking_params) ? $session->tracking_params : [], $tracking);
        $session->tracking_params = $merged;
    }

    /**
     * @param  array<string, mixed>|null  $geo
     */
    private function fillSessionGeoIfEmpty(MetricsSession $session, ?array $geo): void
    {
        if (! is_array($geo)) {
            return;
        }

        foreach (['country', 'region', 'city'] as $field) {
            if (empty($session->{$field}) && ! empty($geo[$field])) {
                $session->{$field} = $geo[$field];
            }
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveSessionKey(Request $request, array $payload): string
    {
        $fromPayload = $payload['session_key'] ?? null;
        if (is_string($fromPayload) && Str::isUuid($fromPayload)) {
            return $fromPayload;
        }

        $cookieName = (string) config('metrics_tracking.cookie_session', 'gf_msid');
        $fromCookie = $request->cookie($cookieName);
        if (is_string($fromCookie) && Str::isUuid($fromCookie)) {
            return $fromCookie;
        }

        return (string) Str::uuid();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveVisitorKey(Request $request, array $payload): string
    {
        $fromPayload = $payload['visitor_key'] ?? null;
        if (is_string($fromPayload) && Str::isUuid($fromPayload)) {
            return $fromPayload;
        }

        $cookieName = (string) config('metrics_tracking.cookie_visitor', 'gf_vid');
        $fromCookie = $request->cookie($cookieName);
        if (is_string($fromCookie) && Str::isUuid($fromCookie)) {
            return $fromCookie;
        }

        return (string) Str::uuid();
    }

    private function queueCookies(string $sessionKey, string $visitorKey): void
    {
        $days = max(1, (int) config('metrics_tracking.cookie_days', 30));
        $minutes = $days * 24 * 60;

        Cookie::queue(cookie(
            (string) config('metrics_tracking.cookie_session', 'gf_msid'),
            $sessionKey,
            $minutes,
            '/',
            null,
            false,
            false,
            false,
            'Lax'
        ));
        Cookie::queue(cookie(
            (string) config('metrics_tracking.cookie_visitor', 'gf_vid'),
            $visitorKey,
            $minutes,
            '/',
            null,
            false,
            false,
            false,
            'Lax'
        ));
    }

    private function queueGeoEnrichment(int $eventId, string $ip): void
    {
        try {
            $queue = (string) config('metrics_tracking.queue', 'metrics-tracking');
            EnrichMetricsEventGeoJob::dispatch($eventId, $ip)->onQueue($queue);
        } catch (\Throwable $e) {
            Log::warning('metrics.geo_dispatch_failed', ['message' => $e->getMessage()]);
        }
    }

    public function recordOrderEvent(
        Order $order,
        string $eventName,
        ?string $conversionStatus = null,
        ?\DateTimeInterface $occurredAt = null,
    ): void {
        if (! $this->enabled()) {
            return;
        }

        try {
            $this->recordOrderEventUnsafe($order, $eventName, $conversionStatus, $occurredAt);
        } catch (\Throwable $e) {
            Log::warning('metrics.order_event_failed', [
                'order_id' => $order->id,
                'event' => $eventName,
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function recordOrderEventUnsafe(
        Order $order,
        string $eventName,
        ?string $conversionStatus,
        ?\DateTimeInterface $occurredAt = null,
    ): void {
        $sessionKey = null;
        if (Schema::hasColumn('orders', 'metrics_session_key')) {
            $sessionKey = $order->metrics_session_key;
        }
        if (! $sessionKey && is_array($order->metadata ?? null)) {
            $sessionKey = $order->metadata['metrics_session_key'] ?? null;
        }

        $session = null;
        if (is_string($sessionKey) && $sessionKey !== '') {
            $session = MetricsSession::query()->where('session_key', $sessionKey)->first();
        }

        $eventId = 'order-'.$order->id.'-'.$eventName;
        if (MetricsEvent::query()->where('event_id', $eventId)->exists()) {
            return;
        }

        $occurred = $occurredAt
            ? \Illuminate\Support\Carbon::parse($occurredAt)
            : ($order->updated_at?->copy() ?? now());

        $seconds = null;
        if ($session?->first_touch_at) {
            $seconds = max(0, $session->first_touch_at->diffInSeconds($occurred));
        }

        $status = $conversionStatus ?? match ($eventName) {
            MetricsEvent::PAYMENT_APPROVED => 'approved',
            MetricsEvent::PIX_CREATED => 'pix_created',
            MetricsEvent::PAYMENT_REFUSED => 'refused',
            MetricsEvent::PAYMENT_REFUNDED => 'refunded',
            MetricsEvent::CHARGEBACK_RECEIVED => 'chargeback',
            MetricsEvent::MED_RECEIVED => 'med',
            MetricsEvent::PAYMENT_CANCELLED => 'cancelled',
            MetricsEvent::PAYMENT_PENDING => 'pending',
            MetricsEvent::CHECKOUT_STARTED => 'checkout_started',
            default => null,
        };

        $meta = is_array($order->metadata) ? $order->metadata : [];
        $affiliateUserId = isset($meta['affiliate_user_id']) ? (int) $meta['affiliate_user_id'] : ($session?->affiliate_user_id);
        $affiliateRef = $meta['affiliate_ref'] ?? $session?->affiliate_ref;
        if (! $affiliateUserId && is_string($affiliateRef) && $affiliateRef !== '') {
            $affiliateUserId = $this->resolveAffiliateUserIdFromRef($affiliateRef, $order->product_id);
        }
        $coproducerUserId = $session?->coproducer_user_id
            ?? (isset($meta['coproducer_user_id']) ? (int) $meta['coproducer_user_id'] : null)
            ?? $this->resolvePrimaryCoproducerUserId($order->product_id);

        MetricsEvent::query()->create([
            'event_id' => $eventId,
            'event_name' => $eventName,
            'metrics_session_id' => $session?->id,
            'session_key' => $session?->session_key ?? $sessionKey,
            'visitor_key' => $session?->visitor_key,
            'tenant_id' => $order->tenant_id,
            'product_id' => $order->product_id,
            'offer_id' => $session?->offer_id,
            'plan_id' => $session?->plan_id,
            'order_id' => $order->id,
            'checkout_session_id' => null,
            'affiliate_user_id' => $affiliateUserId,
            'affiliate_ref' => $affiliateRef,
            'coproducer_user_id' => $coproducerUserId,
            'campaign_code' => $session?->campaign_code,
            'utm_source' => $session?->utm_source,
            'utm_medium' => $session?->utm_medium,
            'utm_campaign' => $session?->utm_campaign,
            'utm_content' => $session?->utm_content,
            'utm_term' => $session?->utm_term,
            'fbclid' => $session?->fbclid,
            'gclid' => $session?->gclid,
            'ttclid' => $session?->ttclid,
            'src' => $session?->src,
            'sck' => $session?->sck,
            'subid' => $session?->subid,
            'subid2' => $session?->subid2,
            'subid3' => $session?->subid3,
            'tracking_params' => $session?->tracking_params,
            'device_type' => $session?->device_type,
            'os_name' => $session?->os_name,
            'browser_name' => $session?->browser_name,
            'user_agent' => $session?->user_agent,
            'ip_hash' => $session?->ip_hash,
            'ip_masked' => $session?->ip_masked,
            'country' => $session?->country,
            'region' => $session?->region,
            'city' => $session?->city,
            'conversion_status' => $status,
            'amount' => $order->amount,
            'currency' => $order->currency ?? 'BRL',
            'seconds_to_convert' => $seconds,
            'geo_enriched' => (bool) $session?->country,
            'properties' => [
                'payment_method' => $order->payment_method ?? null,
                'gateway' => $order->gateway ?? null,
                'status' => $order->status,
            ],
            'occurred_at' => $occurred,
        ]);

        if ($eventName === MetricsEvent::PAYMENT_APPROVED && $session) {
            $session->converted_at = $session->converted_at ?? $occurred;
            $session->save();
        }
    }

    private function resolveAffiliateUserIdFromRef(string $ref, ?string $productId = null): ?int
    {
        $ref = trim($ref);
        if ($ref === '') {
            return null;
        }

        $q = ProductAffiliateEnrollment::query()
            ->whereRaw('LOWER(public_ref) = ?', [Str::lower($ref)])
            ->where('status', ProductAffiliateEnrollment::STATUS_APPROVED)
            ->whereNotNull('affiliate_user_id');

        if ($productId) {
            $q->where('product_id', $productId);
        }

        $uid = $q->value('affiliate_user_id');

        return $uid ? (int) $uid : null;
    }

    private function resolvePrimaryCoproducerUserId(?string $productId): ?int
    {
        if (! $productId) {
            return null;
        }

        $uid = ProductCoproducer::query()
            ->where('product_id', $productId)
            ->where('status', ProductCoproducer::STATUS_ACTIVE)
            ->whereNotNull('co_producer_user_id')
            ->where(function ($q) {
                $q->whereNull('starts_at')->orWhere('starts_at', '<=', now());
            })
            ->where(function ($q) {
                $q->whereNull('ends_at')->orWhere('ends_at', '>', now());
            })
            ->orderByDesc('accepted_at')
            ->value('co_producer_user_id');

        return $uid ? (int) $uid : null;
    }
}
