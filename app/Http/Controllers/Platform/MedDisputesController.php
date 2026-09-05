<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\MedDispute;
use App\Models\User;
use App\Services\CajuPay\CajuPayMedService;
use App\Services\Med\MedDefenseDossierService;
use App\Services\Med\MedPolicyService;
use App\Services\Med\MedResolutionService;
use App\Services\Versell\VersellMedService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class MedDisputesController extends Controller
{
    public function __construct(
        protected CajuPayMedService $medService,
        protected VersellMedService $versellMedService,
        protected MedResolutionService $resolutionService,
        protected MedDefenseDossierService $dossierService,
        protected MedPolicyService $policy,
    ) {}

    public function index(Request $request): Response
    {
        $status = $request->query('status', 'open');
        $party = $request->query('party', 'platform');
        $tenantId = $request->query('tenant_id');

        $q = MedDispute::query()
            ->with(['order.product', 'tenantOwner'])
            ->orderByDesc('id');

        if ($party === 'platform') {
            $q->platformManaged();
        } elseif ($party === 'tenant') {
            $q->tenantManaged();
        }

        if ($status === 'open') {
            $q->open();
        } elseif ($status === 'resolved') {
            $q->whereNotIn('status', [MedDispute::STATUS_OPEN, MedDispute::STATUS_DEFENSE_SUBMITTED]);
        }

        if ($tenantId !== null && $tenantId !== '') {
            $q->where('tenant_id', (int) $tenantId);
        }

        $rows = $q->paginate(30)->withQueryString();

        // API PIX: se a Caju já encerrou e o webhook falhou, concilia ao listar "Abertas".
        if ($status === 'open' && $party === 'tenant') {
            $this->medService->reconcileOpenDisputesFromRemote($rows->getCollection());
            $rows->setCollection(
                $rows->getCollection()
                    ->map(fn (MedDispute $d) => $d->fresh() ?? $d)
                    ->filter(fn (MedDispute $d) => $d->isOpen())
                    ->values()
            );
        }

        return Inertia::render('Platform/MedDisputes/Index', [
            'disputes' => $rows->through(fn (MedDispute $d) => $this->serialize($d)),
            'filters' => [
                'status' => $status,
                'party' => $party,
                'tenant_id' => $tenantId,
            ],
            'pageTitle' => 'Disputas MED',
        ]);
    }

    public function show(MedDispute $dispute): Response
    {
        $dispute->load(['order.product', 'order.user', 'tenantOwner', 'resolvedBy']);
        if ($dispute->isTenantManaged() && $dispute->isOpen()) {
            $dispute = $this->medService->reconcileFromRemoteIfNeeded($dispute);
            $dispute->load(['order.product', 'order.user', 'tenantOwner', 'resolvedBy']);
        }

        return Inertia::render('Platform/MedDisputes/Show', [
            'dispute' => $this->serialize($dispute, true),
            'pageTitle' => 'Disputa MED #'.$dispute->id,
        ]);
    }

    public function generateDossier(MedDispute $dispute): RedirectResponse
    {
        try {
            $this->dossierService->generate($dispute);
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', 'Não foi possível gerar o dossiê: '.$e->getMessage());
        }

        return back()->with('success', 'Dossiê PDF gerado com sucesso.');
    }

    public function downloadDossier(MedDispute $dispute): BinaryFileResponse|RedirectResponse
    {
        if (! $this->dossierService->isAvailable($dispute)) {
            try {
                $this->dossierService->generate($dispute);
                $dispute->refresh();
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

    public function submitDefense(Request $request, MedDispute $dispute): RedirectResponse
    {
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

        return back()->with('success', 'Defesa enviada ao gateway.');
    }

    public function resolve(Request $request, MedDispute $dispute): RedirectResponse
    {
        $validated = $request->validate([
            'outcome' => ['required', 'string', 'in:won,lost,cancelled'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $this->resolutionService->resolve(
                $dispute,
                $validated['outcome'],
                $request->user(),
                $validated['note'] ?? null
            );
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', $e->getMessage() ?: 'Não foi possível resolver a disputa.');
        }

        return redirect()
            ->route('plataforma.disputas.show', $dispute)
            ->with('success', 'Disputa MED resolvida.');
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(MedDispute $dispute, bool $detail = false): array
    {
        $order = $dispute->order;
        $owner = $dispute->tenantOwner;

        $data = [
            'id' => $dispute->id,
            'responsible_party' => $dispute->responsible_party,
            'cajupay_dispute_id' => $dispute->cajupay_dispute_id,
            'cajupay_payment_id' => $dispute->cajupay_payment_id,
            'status' => $dispute->status,
            'outcome' => $dispute->outcome,
            'amount_cents' => $dispute->amount_cents,
            'txid' => $dispute->txid,
            'reason' => $dispute->reason,
            'reason_code' => $dispute->reason_code,
            'defense_text' => $dispute->defense_text,
            'defended_at' => $dispute->defended_at?->toIso8601String(),
            'opened_at' => $dispute->opened_at?->toIso8601String(),
            'resolved_at' => $dispute->resolved_at?->toIso8601String(),
            'resolution_note' => $dispute->resolution_note,
            'has_dossier' => $this->dossierService->isAvailable($dispute),
            'is_open' => $dispute->isOpen(),
            'order_origin' => $order ? $this->policy->orderOriginLabel($order) : null,
            'tenant' => $owner ? [
                'id' => $owner->id,
                'name' => $owner->name,
                'email' => $owner->email,
            ] : null,
            'order' => $order ? [
                'id' => $order->id,
                'public_reference' => $order->public_reference,
                'amount' => (float) $order->amount,
                'status' => $order->status,
                'gateway' => $order->gateway,
            ] : null,
        ];

        if ($detail && $order) {
            $data['order']['email'] = $order->email;
            $data['order']['product_name'] = $order->product?->name;
        }

        if ($detail && $dispute->resolvedBy) {
            $data['resolved_by'] = [
                'id' => $dispute->resolvedBy->id,
                'name' => $dispute->resolvedBy->name,
            ];
        }

        return $data;
    }
}
