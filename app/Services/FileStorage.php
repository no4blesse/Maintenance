<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Exceptions\BusinessRuleException;
use RuntimeException;

/**
 * Private file storage outside the public web root.
 * - Type is detected from file CONTENT (finfo), never from the client name/MIME.
 * - Stored under a random name with a whitelisted extension -> cannot execute.
 * - Downloads go through controllers that enforce authorization.
 */
final class FileStorage
{
    /** Allows tests to store files that did not arrive via HTTP upload */
    public static bool $allowNonUploaded = false;

    /**
     * @param array{name:string,tmp_name:string,error:int,size:int} $file
     * @param 'images'|'documents' $kind
     * @return array{disk_path:string, original_name:string, mime:string, size_bytes:int}
     */
    public static function store(array $file, string $kind, string $subdir): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new BusinessRuleException(match ($file['error'] ?? 0) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'ไฟล์มีขนาดใหญ่เกินกำหนด',
                UPLOAD_ERR_PARTIAL => 'อัปโหลดไฟล์ไม่สมบูรณ์ กรุณาลองใหม่',
                default => 'อัปโหลดไฟล์ไม่สำเร็จ',
            });
        }
        $tmp = (string) $file['tmp_name'];
        if (!self::$allowNonUploaded && !is_uploaded_file($tmp)) {
            throw new BusinessRuleException('ไฟล์อัปโหลดไม่ถูกต้อง');
        }
        $maxMb = (int) (setting('upload_max_mb') ?: Config::get('upload.max_mb', 10));
        $size = (int) filesize($tmp);
        if ($size <= 0 || $size > $maxMb * 1024 * 1024) {
            throw new BusinessRuleException("ไฟล์ต้องมีขนาดไม่เกิน {$maxMb} MB");
        }
        $allowed = (array) Config::get('upload.' . $kind, []);
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
        if (!isset($allowed[$mime])) {
            throw new BusinessRuleException('ประเภทไฟล์ไม่ได้รับอนุญาต (' . $mime . ')');
        }
        if (str_starts_with($mime, 'image/') && @getimagesize($tmp) === false) {
            throw new BusinessRuleException('ไฟล์รูปภาพไม่ถูกต้อง');
        }
        if (!preg_match('#^[a-z0-9_/\-]+$#', $subdir)) {
            throw new RuntimeException('Invalid storage subdir');
        }
        $rel = trim($subdir, '/') . '/' . gmdate('Y/m') . '/' . bin2hex(random_bytes(16)) . '.' . $allowed[$mime];
        $abs = self::root() . '/' . $rel;
        if (!is_dir(dirname($abs)) && !mkdir(dirname($abs), 0750, true) && !is_dir(dirname($abs))) {
            throw new RuntimeException('Cannot create upload directory');
        }
        $ok = self::$allowNonUploaded && !is_uploaded_file($tmp) ? copy($tmp, $abs) : move_uploaded_file($tmp, $abs);
        if (!$ok) {
            throw new RuntimeException('Cannot store uploaded file');
        }
        @chmod($abs, 0640);
        $original = mb_substr(preg_replace('/[\x00-\x1F\x7F\/\\\\]+/u', '_', (string) $file['name']) ?: 'file', 0, 200);
        return ['disk_path' => $rel, 'original_name' => $original, 'mime' => $mime, 'size_bytes' => $size];
    }

    public static function root(): string
    {
        return rtrim((string) Config::get('upload.root'), '/\\');
    }

    /** Absolute path, guaranteed to be inside the storage root */
    public static function absolute(string $diskPath): ?string
    {
        $root = realpath(self::root());
        $abs = realpath(self::root() . '/' . $diskPath);
        if ($root === false || $abs === false || !str_starts_with($abs, $root . DIRECTORY_SEPARATOR) || !is_file($abs)) {
            return null;
        }
        return $abs;
    }
}
