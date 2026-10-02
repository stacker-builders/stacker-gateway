<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class AccessGrantedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $subjectLine,
        public string $htmlBody
    ) {
        $this->subject($this->subjectLine);
    }

    public function build()
    {
        return $this->html($this->wrapLightDocument($this->htmlBody));
    }

    /**
     * Força esquema claro no cliente de e-mail (evita tarja preta em logos / fundos).
     */
    private function wrapLightDocument(string $bodyHtml): string
    {
        if (stripos($bodyHtml, '<html') !== false) {
            return $bodyHtml;
        }

        return '<!DOCTYPE html>'
            .'<html lang="pt-BR">'
            .'<head>'
            .'<meta charset="utf-8">'
            .'<meta name="viewport" content="width=device-width">'
            .'<meta name="color-scheme" content="light only">'
            .'<meta name="supported-color-schemes" content="light">'
            .'<style type="text/css">:root{color-scheme:light only;}body{margin:0;padding:0;background-color:#ffffff !important;}</style>'
            .'</head>'
            .'<body style="margin:0;padding:0;background-color:#ffffff !important;">'
            .$bodyHtml
            .'</body></html>';
    }
}
