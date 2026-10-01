<?php

declare(strict_types=1);

namespace Naluz\Storage;

use Psr\Http\Message\UploadedFileInterface;

/**
 * Safe handling of uploaded files. Nothing the client says is trusted:
 *  - the type is detected from the file's CONTENT (finfo), not from the client-supplied name or Content-Type;
 *  - only types you allow-list are accepted, and the stored extension comes from your list, not from the upload;
 *  - the stored name is random, so uploads can't overwrite files or smuggle path segments.
 *
 *   $path = Uploads::store($file, $disk, 'avatars', Uploads::IMAGES, maxBytes: 2_000_000);
 */
final class Uploads
{
    /** mime => extension */
    public const IMAGES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
    public const DOCUMENTS = ['application/pdf' => 'pdf', 'text/plain' => 'txt'];

    /**
     * @param array<string,string> $allowed mime => stored extension (required: there is no "allow everything")
     * @return string stored path relative to the disk
     * @throws UploadRejectedException
     */
    public static function store(UploadedFileInterface $file, Filesystem $disk, string $directory, array $allowed, int $maxBytes = 5_000_000): string
    {
        if ($allowed === []) {
            throw new \InvalidArgumentException('List the allowed MIME types explicitly.');
        }
        if ($file->getError() !== UPLOAD_ERR_OK) {
            throw new UploadRejectedException('The upload failed.');
        }
        $size = $file->getSize();
        if ($size === null || $size <= 0 || $size > $maxBytes) {
            throw new UploadRejectedException('The file is empty or larger than ' . $maxBytes . ' bytes.');
        }

        $stream = $file->getStream();
        $stream->rewind();
        $head = $stream->read(min($size, 8192));
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($head) ?: '';
        if (!isset($allowed[$mime])) {
            throw new UploadRejectedException('This file type is not allowed.');
        }

        $stream->rewind();
        $name = trim($directory, '/') . '/' . bin2hex(random_bytes(16)) . '.' . $allowed[$mime];
        $disk->put(ltrim($name, '/'), (string) $stream);
        return ltrim($name, '/');
    }
}
