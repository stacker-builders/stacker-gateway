<?php

namespace App\Support;

final class EmailLogoHtml
{
    /**
     * Logo para e-mails com fundo claro fixo (evita PNG transparente virar “caixa preta” no modo escuro do cliente).
     * Dimensões pensadas para exibição nítida (~280×120) sem esmagar com max-height baixo.
     */
    public static function wrap(string $logoUrl): string
    {
        $src = e($logoUrl);

        return '<div data-email-logo="1" style="margin:0 auto 24px;text-align:center;background-color:#ffffff !important;background:#ffffff !important;">'
            .'<!--[if mso]><table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" bgcolor="#ffffff"><tr><td bgcolor="#ffffff" style="padding:10px 16px;background-color:#ffffff;"><![endif]-->'
            .'<table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" bgcolor="#ffffff" style="margin:0 auto;background-color:#ffffff !important;background-image:linear-gradient(#ffffff,#ffffff);mso-table-lspace:0pt;mso-table-rspace:0pt;">'
            .'<tr><td bgcolor="#ffffff" align="center" style="padding:10px 16px;background-color:#ffffff !important;background-image:linear-gradient(#ffffff,#ffffff);">'
            .'<img src="'.$src.'" alt="Logo" width="280" style="display:block;margin:0 auto;max-width:280px;max-height:120px;width:auto;height:auto;border:0;outline:none;text-decoration:none;-ms-interpolation-mode:bicubic;" />'
            .'</td></tr></table>'
            .'<!--[if mso]></td></tr></table><![endif]-->'
            .'</div>';
    }
}
