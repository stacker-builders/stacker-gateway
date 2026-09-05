<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\LogsSellerActivity;
use App\Models\MedDispute;
use App\Services\CajuPay\CajuPayMedService;
use App\Services\Med\MedDefenseDossierService;
use App\Services\SellerActivityLogService;
use App\Services\Versell\VersellMedService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class SellerMedDisputesController extends Controller
{
    use LogsSellerActivity;

    public function __construct(
        protected CajuPayMedService $medService,
        protected VersellMedService $versellMedService,
        protected MedDefenseDossierService $dossierService,
    ) {}

    public function index(Request $request): Response
    {
        $tenantId = (int) $request->user()->tenant_id;
        $status = $request->query('status', 'open');
        $disputes = $this->medService->listForTenant($tenantId, $status === 'all' ? null : $status);

        return Inertia::render('Disputas/Index', [
            'disputes' => array_map(fn (MedDispute $d) => $this->serializeDispute($d), $disputes),
            'filter_status' => $status,
            'open_count' => $this->medService->openCountForTenant($tenantId),
            'pageTitle' => 'Disputas MED',
        ]);
    }

    public function show(Request $request, MedDispute $dispute): Response
    {
        $tenantId = (int) $request->user()->tenant_id;
        if ((int) $dispute->tenant_id !== $tenantId || ! $dispute->isTenantManaged()) {
            abort(404);
        }
        $dispute = $this->medService->getForTenant($tenantId, $dispute);

        return Inertia::render('Disputas/Show', [
            'dispute' => $this->serializeDispute($dispute, true),
            'pageTitle' => 'Disputa MED #'.$dispute->id,
        ]);
    }

    public function submitDefense(Request $request, MedDispute $dispute): RedirectResponse
    {
        $tenantId = (int) $request->user()->tenant_id;
        if ((int) $dispute->tenant_id !== $tenantId) {
            abort(403);
        }

        $validated = $request->validate([
            'text' => ['required', 'string', 'min:10', 'max:10000'],
            'attachments' => ['nullable', 'array', 'max:10'],
            'attachments.*' => ['file', 'max:8192', 'mimes:pdf,jpg,jpeg,png,webp'],
        ]);

        try {
            if (VersellMedService::isVersellDispute($dispute)) {
                $this->versellMedService->submitDefense(
                    $dispute,
                    $validated['text'],
                    $request->file('attachments', []) ?? []
                );
            } else {
                $this->medService->submitDefense(
                    $dispute,
                    $validated['text'],
                    $request->file('attachments', []) ?? []
                );
            }
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', $e->getMessage() ?: 'Não foi possível enviar a defesa.');
        }

        $this->logSellerActivity(SellerActivityLogService::DISPUTE_DEFENSE_SUBMITTED, $dispute, [
            'order_id' => $dispute->order_id,
            'dispute_id' => $dispute->id,
        ]);

        return redirect()->route('disputas.show', $dispute)
            ->with('success', 'Defesa enviada ao gateway.');
    }

    public function generateDossier(Request $request, MedDispute $dispute): RedirectResponse
    {
        $tenantId = (int) $request->user()->tenant_id;
        if ((int) $dispute->tenant_id !== $tenantId || ! $dispute->isTenantManaged()) {
            abort(403);
        }

        try {
            $this->dossierService->generate($dispute);
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', 'Não foi possível gerar o dossiê.');
        }

        $this->logSellerActivity(SellerActivityLogService::DISPUTE_DOSSIER_GENERATED, $dispute, [
            'order_id' => $dispute->order_id,
            'dispute_id' => $dispute->id,
        ]);

        return back()->with('success', 'Dossiê PDF gerado.');
    }

    public function downloadDossier(Request $request, MedDispute $dispute): BinaryFileResponse|RedirectResponse
    {
        $tenantId = (int) $request->user()->tenant_id;
        if ((int) $dispute->tenant_id !== $tenantId || ! $dispute->isTenantManaged()) {
            abort(403);
        }

        if (! $this->dossierService->isAvailable($dispute)) {
            try {
                $this->dossierService->generate($dispute);
                $dispute->refresh();
                $this->logSellerActivity(SellerActivityLogService::DISPUTE_DOSSIER_GENERATED, $dispute, [
                    'order_id' => $dispute->order_id,
                    'dispute_id' => $dispute->id,
                ]);
            } catch (\Throwable $e) {
                report($e);

                return back()->with('error', 'Dossiê indisponível.');
            }
        }

        $path = $this->dossierService->downloadPath($dispute);
        if ($path === null) {
            return back()->with('error', 'Dossiê indisponível.');
        }

        return response()->download($path, 'med-dossie-'.$dispute->id.'.pdf');
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeDispute(MedDispute $dispute, bool $withRemote = false): array
    {
        $dispute->loadMissing('order.product', 'order.user');
        $order = $dispute->order;

        $data = [
            'id' => $dispute->id,
            'cajupay_dispute_id' => $dispute->cajupay_dispute_id,
            'cajupay_payment_id' => $dispute->cajupay_payment_id,
            'status' => $dispute->status,
            'outcome' => $dispute->outcome,
            'amount_cents' => $dispute->amount_cents,
            'currency' => $dispute->currency,
            'txid' => $dispute->txid,
            'defense_text' => $dispute->defense_text,
            'defended_at' => $dispute->defended_at?->toIso8601String(),
            'opened_at' => $dispute->opened_at?->toIso8601String(),
            'resolved_at' => $dispute->resolved_at?->toIso8601String(),
            'is_open' => $dispute->isOpen(),
            'reason' => $dispute->reason,
            'has_dossier' => $this->dossierService->isAvailable($dispute),
            'order' => $order ? [
                'id' => $order->id,
                'public_reference' => $order->public_reference,
                'amount' => (float) $order->amount,
                'status' => $order->status,
                'email' => $order->email,
                'product_name' => $order->product?->name,
            ] : null,
        ];

        if ($withRemote && $dispute->getAttribute('remote_detail') !== null) {
            $data['remote_detail'] = $dispute->getAttribute('remote_detail');
        }

        return $data;
    }
}
