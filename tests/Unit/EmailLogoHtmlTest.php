<?php

namespace Tests\Unit;

use App\Mail\AccessGrantedMail;
use App\Mail\PasswordResetMail;
use App\Support\EmailLogoHtml;
use App\Support\EmailLogoImage;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class EmailLogoHtmlTest extends TestCase
{
    public function test_wrap_uses_opaque_white_background_for_transparent_logos(): void
    {
        $html = EmailLogoHtml::wrap('https://cdn.example.com/logo.png');

        $this->assertStringContainsString('data-email-logo="1"', $html);
        $this->assertStringContainsString('bgcolor="#ffffff"', $html);
        $this->assertStringContainsString('background-color:#ffffff', $html);
        $this->assertStringContainsString('linear-gradient(#ffffff,#ffffff)', $html);
        $this->assertStringContainsString('max-height:120px', $html);
        $this->assertStringContainsString('max-width:280px', $html);
        $this->assertStringNotContainsString('max-height:64px', $html);
    }

    public function test_password_reset_mail_includes_logo_wrapper(): void
    {
        $mail = new PasswordResetMail('https://app.test/reset', 60, null);
        $mail->build();
        $html = $mail->render();

        $this->assertStringContainsString('color-scheme" content="light only"', $html);
        $this->assertStringContainsString('background-color:#ffffff', $html);
        $this->assertStringContainsString('Redefinir senha', $html);
    }

    public function test_access_granted_mail_forces_light_color_scheme(): void
    {
        $mail = new AccessGrantedMail('Assunto', '<p>Olá</p>');
        $html = $mail->render();

        $this->assertStringContainsString('color-scheme" content="light only"', $html);
        $this->assertStringContainsString('<p>Olá</p>', $html);
    }

    public function test_prepare_for_email_flattens_transparent_png_onto_white(): void
    {
        if (! function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD extension required');
        }

        $img = imagecreatetruecolor(80, 40);
        imagesavealpha($img, true);
        $transparent = imagecolorallocatealpha($img, 0, 0, 0, 127);
        imagefill($img, 0, 0, $transparent);
        $red = imagecolorallocate($img, 220, 20, 60);
        imagefilledrectangle($img, 10, 10, 70, 30, $red);

        $tmp = tempnam(sys_get_temp_dir(), 'logo');
        imagepng($img, $tmp);
        imagedestroy($img);

        $file = new UploadedFile($tmp, 'logo.png', 'image/png', null, true);
        [$binary, $ext] = EmailLogoImage::prepareForEmail($file);
        @unlink($tmp);

        $this->assertSame('png', $ext);
        $this->assertNotSame('', $binary);

        $processed = imagecreatefromstring($binary);
        $this->assertNotFalse($processed);
        // Canto deve ser branco opaco (não transparente).
        $rgb = imagecolorat($processed, 0, 0);
        $colors = imagecolorsforindex($processed, $rgb);
        $this->assertGreaterThan(240, $colors['red']);
        $this->assertGreaterThan(240, $colors['green']);
        $this->assertGreaterThan(240, $colors['blue']);
        imagedestroy($processed);
    }
}
