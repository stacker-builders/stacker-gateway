<?php

namespace App\Services;

use App\Models\MemberStudentActivityLog;
use App\Models\Product;
use App\Models\User;
use App\Support\PublicAppUrl;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Link da plataforma amarrado ao aluno (product_user.access_ref).
 * O destino real só sai no 302; e-mail e botões usam /a/{ref}.
 */
class DeliverableAccessLinkService
{
    public const REF_LENGTH = 12;

    /**
     * @return list<string>
     */
    public static function trackedProductTypes(): array
    {
        return [
            Product::TYPE_LINK,
            Product::TYPE_AREA_MEMBROS_EXTERNA,
            Product::TYPE_APLICATIVO,
        ];
    }

    public function tracks(Product $product): bool
    {
        return in_array($product->type, self::trackedProductTypes(), true);
    }

    public function destinationUrl(Product $product): ?string
    {
        $config = is_array($product->checkout_config) ? $product->checkout_config : [];
        $link = trim((string) ($config['deliverable_link'] ?? ''));
        if ($link === '' || ! preg_match('#^https?://#i', $link)) {
            return null;
        }

        return filter_var($link, FILTER_VALIDATE_URL) ? $link : null;
    }

    public function trackedUrl(User $user, Product $product): ?string
    {
        if (! $this->tracks($product) || $this->destinationUrl($product) === null) {
            return null;
        }

        $ref = $this->ensureRef($user, $product);
        if ($ref === null || $ref === '') {
            return null;
        }

        return rtrim(PublicAppUrl::base(), '/').'/a/'.$ref;
    }

    public function ensureRef(User $user, Product $product): ?string
    {
        if (! Schema::hasTable('product_user') || ! Schema::hasColumn('product_user', 'access_ref')) {
            return null;
        }

        $user->products()->syncWithoutDetaching([(string) $product->id]);

        $row = DB::table('product_user')
            ->where('product_id', $product->id)
            ->where('user_id', $user->id)
            ->first();

        if (! $row) {
            return null;
        }

        $existing = is_string($row->access_ref ?? null) ? trim((string) $row->access_ref) : '';
        if ($existing !== '') {
            return $existing;
        }

        for ($i = 0; $i < 8; $i++) {
            $ref = $this->newRef();
            try {
                $updated = DB::table('product_user')
                    ->where('product_id', $product->id)
                    ->where('user_id', $user->id)
                    ->where(function ($q) {
                        $q->whereNull('access_ref')->orWhere('access_ref', '');
                    })
                    ->update(['access_ref' => $ref]);
                if ($updated) {
                    return $ref;
                }
            } catch (\Throwable $e) {
                continue;
            }
        }

        $again = DB::table('product_user')
            ->where('product_id', $product->id)
            ->where('user_id', $user->id)
            ->value('access_ref');

        return is_string($again) && $again !== '' ? $again : null;
    }

    /**
     * @return array{user: User, product: Product, destination: string}|null
     */
    public function resolveRef(string $ref): ?array
    {
        $ref = strtolower(trim($ref));
        if ($ref === '' || ! Schema::hasTable('product_user') || ! Schema::hasColumn('product_user', 'access_ref')) {
            return null;
        }

        $row = DB::table('product_user')->where('access_ref', $ref)->first();
        if (! $row) {
            return null;
        }

        $user = User::query()->find($row->user_id);
        $product = Product::query()->find($row->product_id);
        if (! $user || ! $product || ! $this->tracks($product)) {
            return null;
        }

        try {
            $destination = $this->destinationUrl($product);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
        if ($destination === null) {
            return null;
        }

        return [
            'user' => $user,
            'product' => $product,
            'destination' => $destination,
        ];
    }

    /**
     * Registra o clique no entregável. Nunca deve lançar: falha de log não pode
     * impedir o redirect para o conteúdo externo.
     */
    public function recordClick(User $user, Product $product, Request $request): void
    {
        try {
            if (! Schema::hasTable('member_student_activity_logs')) {
                return;
            }

            $ip = (string) $request->ip();
            $recent = MemberStudentActivityLog::query()
                ->where('user_id', $user->id)
                ->where('product_id', (string) $product->id)
                ->where('event', MemberStudentActivityLog::EVENT_EXTERNAL_LINK_CLICKED)
                ->where('ip', $ip !== '' ? $ip : null)
                ->where('created_at', '>=', now()->subSeconds(15))
                ->exists();
            if ($recent) {
                return;
            }

            app(MemberStudentActivityLogService::class)->record(
                $user,
                $product,
                MemberStudentActivityLog::EVENT_EXTERNAL_LINK_CLICKED,
                $request,
                null,
                $product->name,
                null,
                [
                    'source' => 'access_ref',
                    'logged_in' => $request->user()?->id === $user->id,
                ],
            );
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function newRef(): string
    {
        $alphabet = 'abcdefghjkmnpqrstvwxyz23456789';
        $out = '';
        for ($i = 0; $i < self::REF_LENGTH; $i++) {
            $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $out;
    }
}
