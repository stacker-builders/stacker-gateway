<?php

namespace App\Support;

use App\Models\Product;
use App\Models\ProductAffiliateEnrollment;
use App\Models\ProductOffer;
use App\Models\SubscriptionPlan;
use Illuminate\Support\Facades\Schema;

/**
 * Links de checkout com ?ref= para afiliados (principal + ofertas/planos do produto).
 */
final class AffiliateCheckoutLinks
{
    public static function appendRef(string $url, string $ref): string
    {
        $ref = trim($ref);
        if ($ref === '' || $url === '') {
            return $url;
        }

        return $url.(str_contains($url, '?') ? '&' : '?').'ref='.urlencode($ref);
    }

    public static function mainLink(Product $product, string $publicRef): ?string
    {
        $publicRef = trim($publicRef);
        if ($publicRef === '' || ! $product->checkout_slug) {
            return null;
        }

        return self::appendRef(url('/c/'.$product->checkout_slug), $publicRef);
    }

    public static function offerLink(Product $product, ProductOffer $offer, string $publicRef): ?string
    {
        $publicRef = trim($publicRef);
        if ($publicRef === '') {
            return null;
        }

        if ($offer->checkout_slug) {
            return self::appendRef(url('/c/'.$offer->checkout_slug), $publicRef);
        }

        if (! $product->checkout_slug) {
            return null;
        }

        $base = url('/c/'.$product->checkout_slug).'?offer_id='.(int) $offer->id;

        return self::appendRef($base, $publicRef);
    }

    public static function planLink(Product $product, SubscriptionPlan $plan, string $publicRef): ?string
    {
        $publicRef = trim($publicRef);
        if ($publicRef === '') {
            return null;
        }

        if ($plan->checkout_slug) {
            return self::appendRef(url('/c/'.$plan->checkout_slug), $publicRef);
        }

        if (! $product->checkout_slug) {
            return null;
        }

        $base = url('/c/'.$product->checkout_slug).'?plan_id='.(int) $plan->id;

        return self::appendRef($base, $publicRef);
    }

    /**
     * @return list<array{type: string, id: int|null, label: string, price: float|null, currency: string|null, url: string}>
     */
    public static function linksForEnrollment(Product $product, ProductAffiliateEnrollment $enrollment): array
    {
        $ref = trim((string) ($enrollment->public_ref ?? ''));
        if ($ref === '') {
            return [];
        }

        $out = [];
        $main = self::mainLink($product, $ref);
        if ($main !== null) {
            $out[] = [
                'type' => 'main',
                'id' => null,
                'label' => 'Checkout principal',
                'price' => is_numeric($product->price) ? (float) $product->price : null,
                'currency' => $product->currency ?? 'BRL',
                'url' => $main,
            ];
        }

        $offers = $product->relationLoaded('offers')
            ? $product->offers
            : $product->offers()->orderBy('position')->orderBy('id')->get();

        $hasShareColumn = Schema::hasColumn('product_offers', 'affiliate_share_enabled');
        $anyExplicitlyShared = $hasShareColumn && $offers->contains(
            fn ($offer) => $offer instanceof ProductOffer
                && filter_var($offer->affiliate_share_enabled ?? false, FILTER_VALIDATE_BOOLEAN)
        );

        foreach ($offers as $offer) {
            if (! $offer instanceof ProductOffer) {
                continue;
            }
            if ($anyExplicitlyShared && ! filter_var($offer->affiliate_share_enabled ?? false, FILTER_VALIDATE_BOOLEAN)) {
                continue;
            }
            $url = self::offerLink($product, $offer, $ref);
            if ($url === null) {
                continue;
            }
            $out[] = [
                'type' => 'offer',
                'id' => (int) $offer->id,
                'label' => (string) ($offer->name ?: 'Oferta #'.$offer->id),
                'price' => is_numeric($offer->price) ? (float) $offer->price : null,
                'currency' => $offer->getCurrencyOrDefault(),
                'url' => $url,
            ];
        }

        $plans = $product->relationLoaded('subscriptionPlans')
            ? $product->subscriptionPlans
            : $product->subscriptionPlans()->orderBy('position')->orderBy('id')->get();

        foreach ($plans as $plan) {
            if (! $plan instanceof SubscriptionPlan) {
                continue;
            }
            $url = self::planLink($product, $plan, $ref);
            if ($url === null) {
                continue;
            }
            $interval = SubscriptionPlan::intervalLabels()[$plan->interval] ?? $plan->interval;
            $out[] = [
                'type' => 'plan',
                'id' => (int) $plan->id,
                'label' => (string) ($plan->name ?: 'Plano #'.$plan->id).($interval ? ' · '.$interval : ''),
                'price' => is_numeric($plan->price) ? (float) $plan->price : null,
                'currency' => $plan->getCurrencyOrDefault(),
                'url' => $url,
            ];
        }

        return $out;
    }
}
