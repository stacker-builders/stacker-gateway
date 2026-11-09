<?php

namespace App\Http\Controllers;

use App\Services\Telegram\TelegramSellerSubscribersService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TelegramSubscribersController extends Controller
{
    public function __construct(
        private TelegramSellerSubscribersService $subscribers,
    ) {}

    public function index(Request $request): Response
    {
        $user = auth()->user();
        $tenantId = (int) ($user?->tenant_id ?? $user?->id ?? 0);
        if ($tenantId <= 0) {
            abort(403);
        }

        $products = $this->subscribers->telegramProductsForTenant($tenantId, $user);
        if ($products === []) {
            abort(404);
        }

        $productId = trim((string) $request->query('product_id', ''));
        $email = trim((string) $request->query('email', ''));
        $name = trim((string) $request->query('name', ''));

        $grants = $this->subscribers->paginate(
            $tenantId,
            $productId !== '' ? $productId : null,
            $email !== '' ? $email : null,
            $name !== '' ? $name : null,
            20,
            $user,
        );

        return Inertia::render('Telegram/Subscribers', [
            'products' => $products,
            'grants' => $grants,
            'filters' => [
                'product_id' => $productId,
                'email' => $email,
                'name' => $name,
            ],
        ]);
    }
}
