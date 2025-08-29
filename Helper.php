<?php

// Import additionnal class into the global namespace
use \LaswitchTech\Core\Abstracts\Helper;

class FaviconHelper extends Helper {

    /**
     * Retrieve the Favicon URL
     */
    public function url(string $url): string
    {
        // Sanitize the domain removing any protocol and any path
        $domain = str_replace('https://', '', $url);
        $domain = str_replace('http://', '', $domain);
        $domain = explode('/', $domain)[0];

        // Return the Favicon
        return 'https://icons.duckduckgo.com/ip3/' . $domain . '.ico';
    }

    /**
     * Retrieve the Favicon Content
     */
    public function content(string $url): string
    {
        $ctx = stream_context_create([
            'http' => [
                'timeout' => 5,
                'header'  => "User-Agent: FavHelper/1.0\r\n",
            ]
        ]);
        $data = @file_get_contents($this->url($url), false, $ctx);
        if ($data === false || $data === '') {
            return '';
        }
        return $data;
    }

    /**
     * Detect MIME type from a binary string (blob)
     *
     * @param string $bytes  Raw file contents
     * @return string       Detected MIME type, e.g. "image/png"
     * @throws RuntimeException if Fileinfo isn’t available
     */
    public function mimeType(string $bytes): string
    {
        if (function_exists('finfo_buffer')) {
            $fi = new \finfo(FILEINFO_MIME_TYPE);
            $mime = $fi->buffer($bytes);
            if ($mime) { return $mime; }
        }

        // Minimal magic detection for ICO and PNG fallbacks.
        // ICO header starts with 00 00 01 00
        if (substr($bytes, 0, 4) === "\x00\x00\x01\x00") {
            return 'image/x-icon';
        }
        // PNG header 89 50 4E 47 0D 0A 1A 0A
        if (substr($bytes, 0, 8) === "\x89PNG\x0D\x0A\x1A\x0A") {
            return 'image/png';
        }
        return 'application/octet-stream';
    }

    /**
     * Convert an image to a different format and size (center fit).
     *
     * - Supports input as raw bytes or data URI in $logo['content'].
     * - $logo['type'] should be a MIME type if you have it; otherwise it's auto-detected.
     * - Resizes to fit INSIDE $width x $height keeping aspect ratio, then pads
     *   to exact size with transparent background (PNG/WebP/GIF) or white (JPEG/BMP).
     * - Uses Imagick when available; falls back to GD.
     *
     * @param array  $logo    ['type' => 'image/png'|..., 'content' => raw-bytes|string data:uri]
     * @param string $format  'png'|'jpeg'|'gif'|'webp'|'bmp'
     * @param int    $width
     * @param int    $height
     * @return array ['type' => mime-type, 'content' => raw-bytes]
     * @throws Exception on invalid inputs or unsupported cases.
     */
    public function convert(array $logo, string $format, int $width, int $height): array
    {
        $format = strtolower($format);
        if ($format === 'jpg') $format = 'jpeg';

        $allowed = ['png','jpeg','gif','webp','bmp'];
        if (!in_array($format, $allowed, true)) {
            throw new Exception("Unsupported target format: {$format}");
        }
        if ($width <= 0 || $height <= 0) {
            throw new Exception("Width and height must be positive integers.");
        }

        $targetMime = [
            'png'  => 'image/png',
            'jpeg' => 'image/jpeg',
            'gif'  => 'image/gif',
            'webp' => 'image/webp',
            'bmp'  => 'image/bmp',
        ][$format];

        // Check if the logo type and the requested format are the same
        if (array_key_exists('type', $logo) && $logo['type'] === $targetMime) {
            return $logo;
        }

        $bytes   = $this->normalizeBytes($logo['content'] ?? null);
        $srcMime = $logo['type'] ?? $this->mimeType($bytes);

        // Quick guard against HTML/unknown blobs (e.g., 404 page).
        // Reject obvious non-image blobs (e.g., HTML error pages)
        if (
            !$this->isIcoMime($srcMime) &&
            !in_array(strtolower($srcMime), ['image/png','image/jpeg','image/gif','image/webp','image/bmp'], true)
        ) {
            throw new Exception("Unsupported or undetected source MIME: {$srcMime}");
        }

        // ---------- Prefer Imagick if it can actually decode this ----------
        if (class_exists(\Imagick::class)) {
            $img = new \Imagick();
            $tmpPath = null; // ensure we can clean up
            try {
                // First try blob with a filename hint (works most of the time)
                $hint = $this->isIcoMime($srcMime) ? 'favicon.ico' : ('x.' . ($this->extFromMime($srcMime) ?? 'img'));
                $img->readImageBlob($bytes, $hint);
            } catch (\ImagickException $e1) {
                // Blob sniffing can still fail for ICOs; try file-based read with .ico suffix.
                try {
                    if ($this->isIcoMime($srcMime)) {
                        $tmpPath = $this->writeTemp($bytes, '.ico');
                        // Both of these normally work; extension usually suffices:
                        // $img->readImage("ico:$tmpPath");
                        $img->readImage($tmpPath);
                    } else {
                        // Non-ICO: still try a file with the right extension if available
                        $ext = '.' . ($this->extFromMime($srcMime) ?? 'img');
                        $tmpPath = $this->writeTemp($bytes, $ext);
                        $img->readImage($tmpPath);
                    }
                } catch (\ImagickException $e2) {
                    // Give up on Imagick; fall through to GD path
                    $img->clear(); $img->destroy();
                    if ($tmpPath && file_exists($tmpPath)) @unlink($tmpPath);
                    $eMessage = strtolower($e2->getMessage());
                    $recoverable = (
                        str_contains($eMessage, 'no decode delegate') ||
                        str_contains($eMessage, 'improper image header') ||
                        str_contains($eMessage, 'insufficient image data') ||
                        str_contains($eMessage, 'corrupt image')
                    );
                    if (!$recoverable) {
                        // non-ICO/non-recoverable -> bubble up
                        throw $e2;
                    }
                    // else: continue into GD fallback below
                    $img = null;
                }
            }

            if ($img instanceof \Imagick) {
                try {
                    // If it's an ICO (or multi-frame), pick the largest frame.
                    $chosen = $this->pickLargestFrame($img);

                    // Resize (fit inside box; keep aspect).
                    $chosen->thumbnailImage($width, $height, true);

                    // Compose onto exact-size canvas.
                    $canvas = new \Imagick();
                    if (in_array($format, ['png','webp','gif'], true)) {
                        $canvas->newImage($width, $height, new \ImagickPixel('transparent'), $format);
                    } else {
                        $canvas->newImage($width, $height, new \ImagickPixel('white'), $format);
                    }

                    $x = (int) floor(($width  - $chosen->getImageWidth())  / 2);
                    $y = (int) floor(($height - $chosen->getImageHeight()) / 2);
                    $canvas->compositeImage($chosen, \Imagick::COMPOSITE_DEFAULT, $x, $y);

                    $canvas->setImageFormat($format);
                    if ($format === 'jpeg') {
                        $canvas->setImageCompressionQuality(85);
                    } elseif ($format === 'webp' && method_exists($canvas, 'setImageCompressionQuality')) {
                        $canvas->setImageCompressionQuality(80);
                    }

                    $out = $canvas->getImageBlob();
                    $canvas->clear(); $canvas->destroy();
                    $chosen->clear(); $chosen->destroy();
                    $img->clear(); $img->destroy();
                    if ($tmpPath && file_exists($tmpPath)) @unlink($tmpPath);

                    return ['type' => $targetMime, 'content' => $out];
                } finally {
                    if ($tmpPath && file_exists($tmpPath)) @unlink($tmpPath);
                }
            }
        }

        // ---------- GD fallback (with PNG-in-ICO extractor) ----------
        if ($this->isIcoMime($srcMime)) {
            $png = $this->icoToPng($bytes);
            if ($png === null) {
                throw new Exception('ICO could not be decoded (no PNG or unsupported DIB frame).');
            }
            $bytes   = $png;
            $srcMime = 'image/png';
        }

        $src = @imagecreatefromstring($bytes);
        if (!$src) {
            throw new Exception('Failed to decode image with GD.');
        }

        $srcW = imagesx($src);
        $srcH = imagesy($src);
        if ($srcW <= 0 || $srcH <= 0) {
            imagedestroy($src);
            throw new Exception('Invalid source dimensions.');
        }

        $scale = min($width / $srcW, $height / $srcH);
        $dstW  = max(1, (int) floor($srcW * $scale));
        $dstH  = max(1, (int) floor($srcH * $scale));
        $dstX  = (int) floor(($width  - $dstW) / 2);
        $dstY  = (int) floor(($height - $dstH) / 2);

        $canvas = imagecreatetruecolor($width, $height);

        if (in_array($format, ['png','webp','gif'], true)) {
            imagealphablending($canvas, false);
            imagesavealpha($canvas, true);
            $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
            imagefilledrectangle($canvas, 0, 0, $width, $height, $transparent);
        } else {
            $white = imagecolorallocate($canvas, 255, 255, 255);
            imagefilledrectangle($canvas, 0, 0, $width, $height, $white);
        }

        imagecopyresampled($canvas, $src, $dstX, $dstY, 0, 0, $dstW, $dstH, $srcW, $srcH);
        imagedestroy($src);

        ob_start();
        switch ($format) {
            case 'png':  imagepng($canvas); break;
            case 'jpeg': imagejpeg($canvas, null, 85); break;
            case 'gif':  imagegif($canvas); break;
            case 'webp':
                if (!function_exists('imagewebp')) {
                    imagedestroy($canvas); ob_end_clean();
                    throw new Exception('GD WebP support not available.');
                }
                imagewebp($canvas, null, 80);
                break;
            case 'bmp':
                if (!function_exists('imagebmp')) {
                    imagedestroy($canvas); ob_end_clean();
                    throw new Exception('GD BMP support not available.');
                }
                imagebmp($canvas);
                break;
        }
        $out = ob_get_clean();
        imagedestroy($canvas);

        return ['type' => $targetMime, 'content' => $out];
    }

    /* ===========================
    * Helpers
    * ===========================
    */

    private function writeTemp(string $bytes, string $suffix = '.tmp'): string
    {
        $base = tempnam(sys_get_temp_dir(), 'fav_');
        $path = $base . $suffix;
        @unlink($base);
        file_put_contents($path, $bytes);
        return $path;
    }

    private function extFromMime(?string $mime): ?string
    {
        static $map = [
            'image/png'  => 'png',
            'image/jpeg' => 'jpg',
            'image/gif'  => 'gif',
            'image/webp' => 'webp',
            'image/bmp'  => 'bmp',
            'image/x-icon' => 'ico',
            'image/vnd.microsoft.icon' => 'ico',
            'image/ico' => 'ico',
            // you can add more if needed
        ];
        $mime = $mime ? strtolower($mime) : null;
        return $mime && isset($map[$mime]) ? $map[$mime] : null;
    }

    /**
     * Extract the LARGEST embedded PNG from an ICO. Returns null if none.
     */
    private function extractLargestPngFromIco(string $icoBytes): ?string
    {
        $sig = "\x89PNG\x0D\x0A\x1A\x0A";
        $iend = "\x00\x00\x00\x00IEND\xAE\x42\x60\x82";
        $best = null; $bestLen = 0;
        $pos = 0;
        while (true) {
            $start = strpos($icoBytes, $sig, $pos);
            if ($start === false) break;
            $end = strpos($icoBytes, $iend, $start);
            if ($end === false) break;
            $end += strlen($iend);
            $chunk = substr($icoBytes, $start, $end - $start);
            $len = strlen($chunk);
            if ($len > $bestLen) { $bestLen = $len; $best = $chunk; }
            $pos = $end;
        }
        return $best;
    }

    /**
     * Extract the largest frame from an ICO and return it as PNG bytes.
     * Handles:
     *  - ICO entries stored as PNG (direct passthrough)
     *  - ICO entries stored as DIB/BMP (24/32 bpp) with AND mask (decoded to RGBA)
     *
     * @return string|null PNG bytes, or null if unrecognized ICO
     */
    private function icoToPng(string $icoBytes): ?string
    {
        // Minimal ICO signature check
        if (strlen($icoBytes) < 6 || substr($icoBytes, 0, 4) !== "\x00\x00\x01\x00") {
            return null;
        }

        $count = unpack('v', substr($icoBytes, 4, 2))[1] ?? 0;
        if ($count < 1) { return null; }

        // Read directory entries (16 bytes each) and pick the largest by area
        $best = null; $pos = 6;
        for ($i = 0; $i < $count; $i++, $pos += 16) {
            $w = ord($icoBytes[$pos + 0]); $w = ($w === 0) ? 256 : $w;
            $h = ord($icoBytes[$pos + 1]); $h = ($h === 0) ? 256 : $h;
            $bpp    = unpack('v', substr($icoBytes, $pos + 6, 2))[1];
            $size   = unpack('V', substr($icoBytes, $pos + 8, 4))[1];
            $offset = unpack('V', substr($icoBytes, $pos + 12, 4))[1];

            $cand = ['w'=>$w, 'h'=>$h, 'bpp'=>$bpp, 'size'=>$size, 'off'=>$offset];
            if ($best === null || ($w*$h) > ($best['w']*$best['h'])) { $best = $cand; }
        }
        if ($best === null || ($best['off'] + $best['size']) > strlen($icoBytes)) {
            return null;
        }

        $payload = substr($icoBytes, $best['off'], $best['size']);

        // Case 1: the frame itself is a PNG – we can return it directly.
        if (strncmp($payload, "\x89PNG\x0D\x0A\x1A\x0A", 8) === 0) {
            return $payload;
        }

        // Case 2: DIB/BMP inside ICO (common). Expect BITMAPINFOHEADER (40 bytes)
        if (strlen($payload) < 40) { return null; }

        $hdr = substr($payload, 0, 40);
        $u = unpack(
            'VbiSize/lbiWidth/lbiHeight/vbiPlanes/vbiBitCount/VbiCompression/VbiSizeImage/' .
            'lbiXPelsPerMeter/lbiYPelsPerMeter/VbiClrUsed/VbiClrImportant',
            $hdr
        );

        $w   = (int)$u['biWidth'];
        $h   = (int)(abs($u['biHeight']) / 2);       // ICO stores height*2 (XOR + AND)
        $bpp = (int)$u['biBitCount'];

        if ($w <= 0 || $h <= 0 || !in_array($bpp, [24, 32], true)) {
            return null; // keep it simple; 1/4/8-bpp not handled here
        }

        $rowBytes     = (int)(((($bpp * $w) + 31) >> 5) << 2);
        $pixelBytes   = $rowBytes * $h;
        if (40 + $pixelBytes > strlen($payload)) { return null; }

        $pix          = substr($payload, 40, $pixelBytes);
        $maskRowBytes = (int)(((($w) + 31) >> 5) << 2);
        $maskBytes    = $maskRowBytes * $h;
        $mask         = substr($payload, 40 + $pixelBytes, $maskBytes);

        // Build RGBA with GD
        $im = imagecreatetruecolor($w, $h);
        imagealphablending($im, false);
        imagesavealpha($im, true);

        $bytesPerPx = $bpp / 8;
        for ($y = 0; $y < $h; $y++) {
            $srcY = $h - 1 - $y; // DIB is bottom-up
            $row  = substr($pix, $srcY * $rowBytes, $w * $bytesPerPx);

            for ($x = 0; $x < $w; $x++) {
                if ($bpp === 32) {
                    $i = $x * 4;
                    $b = ord($row[$i    ]);
                    $g = ord($row[$i + 1]);
                    $r = ord($row[$i + 2]);
                    $a = ord($row[$i + 3]); // 0..255
                } else { // 24-bpp (no alpha)
                    $i = $x * 3;
                    $b = ord($row[$i    ]);
                    $g = ord($row[$i + 1]);
                    $r = ord($row[$i + 2]);
                    $a = 255;
                }

                // Apply AND mask (1 = transparent)
                $maskByte = ord($mask[$srcY * $maskRowBytes + intdiv($x, 8)] ?? "\x00");
                $maskBit  = ($maskByte >> (7 - ($x % 8))) & 1;
                if ($maskBit) { $a = 0; }

                $gdAlpha = 127 - (int) round($a * 127 / 255);
                $col     = imagecolorallocatealpha($im, $r, $g, $b, $gdAlpha);
                imagesetpixel($im, $x, $y, $col);
            }
        }

        ob_start(); imagepng($im); $png = ob_get_clean(); imagedestroy($im);
        return $png === '' ? null : $png;
    }

    private function normalizeBytes(?string $content): string
    {
        if (!is_string($content) || $content === '') {
            throw new Exception('Logo content must be a non-empty string (raw bytes or data URI).');
        }

        // data:[mime];base64,....
        if (strpos($content, 'data:') === 0) {
            if (!preg_match('#^data:(?<mime>[^;]+);base64,(?<data>.+)$#', $content, $m)) {
                throw new Exception('Invalid data URI.');
            }
            $decoded = base64_decode($m['data'], true);
            if ($decoded === false) {
                throw new Exception('Failed to decode data URI.');
            }
            return $decoded;
        }

        // Assume raw bytes.
        return $content;
    }

    private function isIcoMime(string $mime): bool
    {
        $mime = strtolower($mime);
        return $mime === 'image/x-icon' || $mime === 'image/vnd.microsoft.icon' || $mime === 'image/ico';
    }

    /**
     * For Imagick: pick the largest frame (useful for ICO or multi-frame inputs).
     */
    private function pickLargestFrame(\Imagick $img): \Imagick
    {
        $best = null; $bestArea = -1;

        // If not multi-image, clone and return.
        if ($img->getNumberImages() <= 1) {
            return clone $img;
        }

        foreach ($img as $frame) {
            $w = $frame->getImageWidth();
            $h = $frame->getImageHeight();
            $area = $w * $h;
            if ($area > $bestArea) {
                $bestArea = $area;
                $best = clone $frame;
            }
        }
        return $best ?? clone $img;
    }
}
