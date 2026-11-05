<?php

namespace App\Services;

use App\Models\Proposal;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class ProposalScreenshots
{
    public const MAX_BYTES = 5_242_880;

    public const MAX_SIDE = 8192;

    public const MAX_PIXELS = 12_000_000;

    public const MAX_THUMBNAIL_BYTES = 524_288;

    public const ROOT = 'proposal-screenshots';

    public const FIELDS = ['screenshot_path', 'screenshot_mime', 'screenshot_bytes', 'screenshot_width', 'screenshot_height', 'screenshot_sha256'];

    public function __construct(private StorageBudget $budget, private PrivateStoragePermissions $permissions) {}

    /** Decode before taking the write lock; keep the upload intact for transaction retries. */
    public function prepare(?UploadedFile $file, string $action): ?array
    {
        if (! in_array($action, ['keep', 'replace', 'remove'], true) || ($action === 'replace') !== ($file !== null)) {
            $this->invalid('action_invalid');
        }
        if ($file === null) {
            return null;
        }
        if (! $file->isValid()) {
            $this->invalid('invalid');
        }
        $path = $file->getRealPath();
        $bytes = filesize($path);
        if ($bytes === false || $bytes < 1 || $bytes > self::MAX_BYTES) {
            $this->invalid('too_large');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        $size = @getimagesize($path);
        if (! in_array($mime, ['image/png', 'image/jpeg'], true) || ! $size
            || $size['mime'] !== $mime || $size[0] < 1 || $size[1] < 1) {
            $this->invalid('invalid');
        }
        if ($size[0] > self::MAX_SIDE || $size[1] > self::MAX_SIDE || $size[0] * $size[1] > self::MAX_PIXELS) {
            $this->invalid('dimensions');
        }
        if (! extension_loaded('gd') || ! extension_loaded('exif')) {
            $this->invalid('unavailable');
        }
        $image = null;
        $thumbnail = null;
        $ignoreJpegWarnings = ini_get('gd.jpeg_ignore_warning');
        ini_set('gd.jpeg_ignore_warning', '0');
        // Truncated images must not be silently repaired by the decoder or logged with their path.
        set_error_handler(static function (int $severity) {
            if (error_reporting() & $severity) {
                throw new RuntimeException('Image processing failed.');
            }

            return false;
        });
        try {
            $image = $mime === 'image/png' ? imagecreatefrompng($path) : imagecreatefromjpeg($path);
            if (! $image || imagesx($image) !== $size[0] || imagesy($image) !== $size[1]) {
                throw new RuntimeException('Image processing failed.');
            }
            if ($mime === 'image/jpeg') {
                // EXIF is optional in a valid JPEG.
                $exif = @exif_read_data($path);
                $orientation = (int) ($exif['Orientation'] ?? 1);
                if (in_array($orientation, [2, 4, 5, 7], true)) {
                    imageflip($image, $orientation === 4 ? IMG_FLIP_VERTICAL : IMG_FLIP_HORIZONTAL);
                }
                $angle = match ($orientation) {
                    3 => 180, 6, 7 => -90, 5, 8 => 90, default => 0
                };
                if ($angle !== 0) {
                    $image = imagerotate($image, $angle, 0);
                }
            }
            $scale = min(1, 320 / imagesx($image), 240 / imagesy($image));
            $thumbnail = imagecreatetruecolor(max(1, (int) floor(imagesx($image) * $scale)), max(1, (int) floor(imagesy($image) * $scale)));
            imagealphablending($thumbnail, false);
            imagesavealpha($thumbnail, true);
            imagecopyresampled($thumbnail, $image, 0, 0, 0, 0, imagesx($thumbnail), imagesy($thumbnail), imagesx($image), imagesy($image));
            ob_start();
            try {
                imagepng($thumbnail, null, 6);
                $png = ob_get_contents();
            } finally {
                ob_end_clean();
            }
            if (! is_string($png) || strlen($png) < 1 || strlen($png) > self::MAX_THUMBNAIL_BYTES || ! @getimagesizefromstring($png)) {
                throw new RuntimeException('Image processing failed.');
            }
        } catch (\Throwable) {
            $this->invalid('invalid');
        } finally {
            restore_error_handler();
            ini_set('gd.jpeg_ignore_warning', $ignoreJpegWarnings);
            unset($image, $thumbnail);
        }

        return ['source' => $path, 'thumbnail' => $png, 'screenshot_mime' => $mime, 'screenshot_bytes' => $bytes,
            'screenshot_width' => $size[0], 'screenshot_height' => $size[1], 'screenshot_sha256' => hash_file('sha256', $path)];
    }

    /** Caller holds the exclusive operation lock and a database transaction. */
    public function change(?Proposal $previous, string $action, ?array $prepared, bool $copy = false): array
    {
        if ($action === 'keep' && ! $copy) {
            return $previous ? $previous->only(self::FIELDS) : array_fill_keys(self::FIELDS, null);
        }
        if ($copy && $action === 'keep' && $previous?->screenshot_path) {
            $original = $this->absolute($previous->screenshot_path);
            $thumbnail = $this->absolute($previous->screenshot_path, true);
            if (! is_file($original) || ! is_file($thumbnail)
                || ! hash_equals($previous->screenshot_sha256, (string) @hash_file('sha256', $original))) {
                $this->invalid('unavailable');
            }
            $png = @file_get_contents($thumbnail);
            if ($png === false || strlen($png) < 1 || strlen($png) > self::MAX_THUMBNAIL_BYTES || ! @getimagesizefromstring($png)) {
                $this->invalid('unavailable');
            }
            $prepared = [...$previous->only(self::FIELDS), 'source' => $original, 'thumbnail' => $png];
        }
        $result = array_fill_keys(self::FIELDS, null);
        if ($prepared !== null && $action !== 'remove') {
            $this->budget->ensure($prepared['screenshot_bytes'] + strlen($prepared['thumbnail']) + 1_048_576);
            $relative = self::ROOT.'/'.bin2hex(random_bytes(16)).'/image.'.($prepared['screenshot_mime'] === 'image/png' ? 'png' : 'jpg');
            $directory = dirname($this->absolute($relative));
            // Register before writing so an outer campaign rollback also cleans a committed savepoint.
            DB::afterRollBack(fn () => $this->remove($relative));
            try {
                $this->permissions->ensureDirectory($directory);
                if (! @copy($prepared['source'], $directory.'/original.tmp')
                    || filesize($directory.'/original.tmp') !== $prepared['screenshot_bytes']
                    || ! hash_equals($prepared['screenshot_sha256'], (string) @hash_file('sha256', $directory.'/original.tmp'))
                    || @file_put_contents($directory.'/thumbnail.tmp', $prepared['thumbnail'], LOCK_EX) !== strlen($prepared['thumbnail'])) {
                    throw new RuntimeException('Private image write failed.');
                }
                $this->permissions->secureFile($directory.'/original.tmp');
                $this->permissions->secureFile($directory.'/thumbnail.tmp');
                if (! @rename($directory.'/original.tmp', $this->absolute($relative)) || ! @rename($directory.'/thumbnail.tmp', $directory.'/thumbnail.png')) {
                    throw new RuntimeException('Private image write failed.');
                }
                $result = array_intersect_key($prepared, $result);
                $result['screenshot_path'] = $relative;
            } catch (\Throwable) {
                $this->remove($relative);
                $this->invalid('save_failed');
            }
        }
        if (! $copy && $previous?->screenshot_path) {
            $oldPath = $previous->screenshot_path;
            DB::afterCommit(fn () => $this->remove($oldPath));
        }

        return $result;
    }

    public function absolute(string $relative, bool $thumbnail = false): string
    {
        if (! preg_match('~^proposal-screenshots/[0-9a-f]{32}/image\.(png|jpg)$~D', $relative)) {
            $this->invalid('unavailable');
        }
        $root = storage_path('app/private/'.self::ROOT);
        $directory = dirname(storage_path('app/private/'.$relative));
        $path = $thumbnail ? $directory.'/thumbnail.png' : storage_path('app/private/'.$relative);
        if (is_link($root) || is_link($directory) || is_link($path)) {
            $this->invalid('unavailable');
        }

        return $path;
    }

    /** Best-effort cleanup must never turn a committed proposal into a reported failure. */
    public function remove(string $relative): bool
    {
        try {
            if (Proposal::where('screenshot_path', $relative)->exists()) {
                return false;
            }
            $directory = dirname($this->absolute($relative));
            if (! is_dir($directory)) {
                return true;
            }
            foreach (new \DirectoryIterator($directory) as $file) {
                if ($file->isDot()) {
                    continue;
                }
                if ($file->isLink() || ! $file->isFile() || ! in_array($file->getFilename(), ['image.png', 'image.jpg', 'thumbnail.png', 'original.tmp', 'thumbnail.tmp'], true)) {
                    return false;
                }
            }
            foreach (new \DirectoryIterator($directory) as $file) {
                if (! $file->isDot() && ! @unlink($file->getPathname())) {
                    return false;
                }
            }

            return @rmdir($directory);
        } catch (\Throwable) {
            Log::warning('Proposal screenshot cleanup deferred.');

            return false;
        }
    }

    public function summary(array $attributes): ?array
    {
        if (empty($attributes['screenshot_path'])) {
            return null;
        }

        return array_intersect_key($attributes, array_flip(array_diff(self::FIELDS, ['screenshot_path'])));
    }

    private function invalid(string $key): never
    {
        throw ValidationException::withMessages(['screenshot' => __('screenshots.'.$key)]);
    }
}
