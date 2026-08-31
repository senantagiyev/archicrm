<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * Server-side upload guard. Client-side accept filters are trivially bypassed,
 * so every uploaded file is re-checked here by extension, real MIME (finfo),
 * and content sniffing. SVG is rejected explicitly — it is an XSS vector
 * (embedded <script>) even though it "looks like an image".
 */
class SafeUpload implements ValidationRule
{
    /** Extensions we never accept, whatever the declared type. */
    private const BLOCKED_EXTENSIONS = [
        'svg', 'svgz', 'html', 'htm', 'xhtml', 'xml', 'js', 'mjs', 'php', 'phtml',
        'php3', 'php4', 'php5', 'phar', 'exe', 'bat', 'cmd', 'sh', 'com', 'jar',
        'htaccess', 'swf', 'xht',
    ];

    /** Byte signatures that reveal active content regardless of extension. */
    private const BLOCKED_SIGNATURES = ['<svg', '<?php', '<script', '<!doctype html', '<html'];

    /**
     * @param  list<string>  $allowedMimes  MIME allowlist for this field
     * @param  list<string>  $allowedExtensions  extension allowlist
     */
    public function __construct(
        private readonly array $allowedMimes,
        private readonly array $allowedExtensions,
    ) {}

    /** Images: jpeg/png/webp/gif only. */
    public static function image(): self
    {
        return new self(
            ['image/jpeg', 'image/png', 'image/webp', 'image/gif'],
            ['jpg', 'jpeg', 'png', 'webp', 'gif'],
        );
    }

    /** Documents: office/pdf + plain images. */
    public static function document(): self
    {
        return new self(
            [
                'application/pdf',
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/vnd.ms-excel',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'image/jpeg', 'image/png', 'image/webp',
                'text/plain', 'text/csv',
            ],
            ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png', 'webp', 'txt', 'csv'],
        );
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        foreach (is_array($value) ? $value : [$value] as $file) {
            if (! $file instanceof UploadedFile && ! $file instanceof TemporaryUploadedFile) {
                continue; // already-stored path (edit without re-upload) — nothing to check
            }

            $this->check($file, $fail);
        }
    }

    private function check(UploadedFile|TemporaryUploadedFile $file, Closure $fail): void
    {
        $ext = strtolower($file->getClientOriginalExtension());
        $mime = strtolower((string) $file->getMimeType());

        if (in_array($ext, self::BLOCKED_EXTENSIONS, true)) {
            $fail(t('portal.upload_blocked', ['ext' => strtoupper($ext ?: '?')]));

            return;
        }

        if ($this->allowedExtensions !== [] && ! in_array($ext, $this->allowedExtensions, true)) {
            $fail(t('portal.upload_type_not_allowed'));

            return;
        }

        if ($this->allowedMimes !== [] && ! in_array($mime, $this->allowedMimes, true)) {
            $fail(t('portal.upload_type_not_allowed'));

            return;
        }

        // Content sniff: reject files whose bytes reveal markup/scripts even when
        // the extension/MIME look benign (e.g. an .png that is really an SVG).
        $head = strtolower((string) file_get_contents($file->getRealPath(), false, null, 0, 512));
        $head = ltrim($head, "\xEF\xBB\xBF \t\r\n"); // strip BOM + leading whitespace

        foreach (self::BLOCKED_SIGNATURES as $sig) {
            if (str_contains($head, $sig)) {
                $fail(t('portal.upload_blocked', ['ext' => 'SVG/HTML']));

                return;
            }
        }
    }
}
