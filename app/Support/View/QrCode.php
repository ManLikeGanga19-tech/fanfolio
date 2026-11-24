<?php

declare(strict_types=1);

namespace App\Support\View;

use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode as Generator;
use chillerlan\QRCode\QROptions;

/**
 * Inline SVG QR codes, made on the server (no outside service, no script). The SVG has
 * no colours of its own: the dark modules take the text colour, so views colour it with
 * design tokens.
 */
final class QrCode
{
    public static function svg(string $data): string
    {
        $options = new QROptions([
            'outputType' => QROutputInterface::MARKUP_SVG,
            'outputBase64' => false,
            'svgAddXmlHeader' => false,
            'svgUseFillAttributes' => false,
            'drawLightModules' => false,
            'connectPaths' => true,
            'eccLevel' => EccLevel::M,
            'addQuietzone' => true,
            'cssClass' => 'size-full',
        ]);

        $svg = new Generator($options)->render($data);

        return is_string($svg) ? $svg : '';
    }
}
