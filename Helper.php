<?php

// Import additionnal class into the global namespace
use \LaswitchTech\Core\Abstracts\Helper;

class FaviconHelper extends Helper {

    // Properties
    private $Path;

    /**
     * Constructor
     */
    public function __construct()
    {
        // Import Global Variables
        global $CONFIG;

        // Set Properties
        $this->Path = $CONFIG->root() . DIRECTORY_SEPARATOR . 'data';
    }

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
        // Retrieve the file content
        $content = file_get_contents($this->url($url));

        // Return the file content
        return $content;
    }

    /**
     * Detect MIME type from a binary string (blob)
     *
     * @param string $blob  Raw file contents
     * @return string       Detected MIME type, e.g. "image/png"
     * @throws RuntimeException if Fileinfo isn’t available
     */
    public function mimeType(string $blob): string
    {
        if (!extension_loaded('fileinfo')) {
            throw new RuntimeException('The Fileinfo extension is not enabled.');
        }

        // Open a Fileinfo resource that returns only the MIME type
        $finfo = finfo_open(FILEINFO_MIME_TYPE);

        if ($finfo === false) {
            throw new RuntimeException('Unable to open finfo.');
        }

        $mime = finfo_buffer($finfo, $blob);

        finfo_close($finfo);

        return $mime ?: 'application/octet-stream';
    }

    /**
     * Convert an image to a different format and size
     *
     * @param array $logo    ['type' => mime‑type, 'content' => raw‑bytes|data‑uri]
     * @param string $format The desired format (e.g., 'png', 'jpeg', 'gif')
     * @param int $width     The desired width of the image
     * @param int $height    The desired height of the image
     * @return array        An array containing the converted image type and content
     */
    public function convert(array $logo, string $format, int $width, int $height): array
    {
        // 1) normalise the source bytes  ────────────────────────────────────────
        $bytes = preg_match('#^data:.*?;base64,#', $logo['content'])
            ? base64_decode(substr($logo['content'], strpos($logo['content'], ',') + 1))
            : $logo['content'];

        // 2) if it’s ICO (or anything GD can’t read) fall back to Imagick ──────
        $isIco = in_array($logo['type'], ['image/vnd.microsoft.icon', 'image/x-icon'], true);

        if ($isIco) {
            try {
                if (!extension_loaded('imagick')) {
                    throw new RuntimeException('Imagick is required to convert .ico files.');
                }
                if (empty(\Imagick::queryFormats("ICO"))) {
                    throw new \Exception('Unsupported format');
                }
                $im = new Imagick();
                $im->readImageBlob($bytes, 'favicon.ico');
                $im->setIteratorIndex($im->getNumberImages() - 1);
                $im->setImageFormat($format);
                if ($width > 0 && $height > 0) {
                    $im->resizeImage($width, $height, Imagick::FILTER_LANCZOS, 1, true);
                }
                $converted = $im->getImageBlob();
                $im->clear();
                $im->destroy();
            } catch (ImagickException $e) {
                $png = $this->extractPNG($bytes);
                if ($png === null) {
                    throw new RuntimeException('ICO contains no PNG frame and Imagick could not read it');
                }
                $bytes = $png;
                $isIco = false;
            }
        }
        if (!$isIco) {
            // 3) use GD for the common formats ──────────────────────────────────
            $src = @imagecreatefromstring($bytes);
            if (!$src) {
                throw new RuntimeException('Unsupported or corrupt source image.');
            }

            $dst = ($width > 0 && $height > 0)
                ? imagescale($src, $width, $height)
                : $src;                    // keep original size if no resize requested

            ob_start();
            switch ($format) {
                case 'png':  imagepng($dst);          break;
                case 'jpeg': imagejpeg($dst, null, 90); break;
                case 'gif':  imagegif($dst);          break;
                default:     throw new InvalidArgumentException('Bad target format');
            }
            $converted = ob_get_clean();

            imagedestroy($dst);
            if ($dst !== $src) {
                imagedestroy($src);
            }
        }

        return [
            'type'    => 'image/' . $format,
            'content' => $converted,
        ];
    }

    /**
     * Return the first embedded PNG frame from an ICO blob, or null if none.
     */
    private function extractPNG(string $ico): ?string
    {
        $sig  = "\x89PNG\r\n\x1A\n";
        $pos  = strpos($ico, $sig);
        if ($pos === false) {
            return null;
        }
        $iend = strpos($ico, "\x00\x00\x00\x00IEND\xAE\x42\x60\x82", $pos);
        if ($iend === false) {
            return null;
        }
        return substr($ico, $pos, ($iend + 12) - $pos);
    }
}
