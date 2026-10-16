<?php

namespace App\Services;

use App\Exceptions\MemberPdfWatermarkException;

class MemberLessonPdfDrmValidator
{
    public const PRODUCER_REJECT_MESSAGE = 'Não foi possível ativar a proteção DRM neste PDF. Remova senha (se houver), exporte novamente como PDF (Imprimir → Salvar como PDF) ou use uma ferramenta de reparo/conversão, e envie de novo.';

    public const PRODUCER_READ_MESSAGE = 'Não foi possível ler este PDF para ativar a proteção DRM. Confirme se o arquivo ainda está disponível no armazenamento e tente enviar novamente.';

    public function __construct(
        private MemberLessonPdfSource $pdfSource,
        private MemberLessonPdfWatermarker $pdfWatermarker,
    ) {}

    /**
     * @param  list<array{url?: string, name?: string}>  $files
     */
    public function assertFilesCompatible(array $files, ?int $tenantId): void
    {
        foreach ($files as $file) {
            if (! $this->isPdf($file)) {
                continue;
            }

            $url = trim((string) ($file['url'] ?? ''));
            if ($url === '') {
                continue;
            }

            try {
                $binary = $this->pdfSource->read($url, $tenantId);
                $this->pdfWatermarker->stamp($binary, $this->probeIdentity());
            } catch (MemberPdfWatermarkException $e) {
                throw new MemberPdfWatermarkException(
                    $this->isReadFailure($e) ? self::PRODUCER_READ_MESSAGE : self::PRODUCER_REJECT_MESSAGE
                );
            }
        }
    }

    /**
     * @param  array{url?: string, name?: string}  $file
     */
    public function isPdf(array $file): bool
    {
        $name = strtolower(trim((string) ($file['name'] ?? '')));
        if (str_ends_with($name, '.pdf')) {
            return true;
        }

        $path = parse_url((string) ($file['url'] ?? ''), PHP_URL_PATH);

        return is_string($path) && str_ends_with(strtolower($path), '.pdf');
    }

    private function isReadFailure(MemberPdfWatermarkException $e): bool
    {
        $message = $e->getMessage();

        return str_contains($message, 'Não foi possível ler')
            || str_contains($message, 'Não foi possível obter')
            || str_contains($message, 'Material não encontrado')
            || str_contains($message, 'não pode ser acessado')
            || str_contains($message, 'Não foi possível localizar');
    }

    /**
     * @return array{name: string, email: string, cpf: string, customer_number: string}
     */
    private function probeIdentity(): array
    {
        return [
            'name' => 'Validacao DRM',
            'email' => 'drm@validacao.local',
            'cpf' => '00000000000',
            'customer_number' => 'DRM-CHECK',
        ];
    }
}
