<?php

namespace App\Services\Complaints;

use App\Enums\EvidenceType;
use App\Models\Complaint;
use App\Models\Evidence;
use App\Models\MaintenanceRecord;
use App\Models\User;
use ErrorException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\Exceptions\ImageException;
use Intervention\Image\ImageManager;
use Throwable;

/**
 * Photos sent with a new Complaint or with Complete work: each file is decoded (so only real JPEG, PNG or WebP images get through),
 * shrunk to at most 1600 px on the longest edge and re-encoded as JPEG, which drops EXIF and
 * GPS metadata. Stored under a random name on the private `evidence` disk.
 */
class EvidencePhotos
{
    public const MAX_EDGE = 1600;

    /**
     * Refuse before decoding anything bigger than a real phone photo (a "decompression bomb"):
     * GD holds about 4 bytes a pixel, so 25 MP is about 100 MB of memory.
     */
    public const MAX_PIXELS = 25_000_000;

    public const ACCEPTED_MIME = ['image/jpeg', 'image/png', 'image/webp'];

    private readonly ImageManager $images;

    public function __construct()
    {
        $this->images = new ImageManager(new Driver);
    }

    /**
     * Decode and re-encode every photo before anything is saved, so a bad one rejects the whole submission.
     *
     * @param  array<int, UploadedFile>  $files  keyed as submitted (photos.0, photos.1, …)
     * @return list<PreparedPhoto>
     *
     * @throws ValidationException naming the photo that could not be used
     */
    public function prepare(array $files): array
    {
        $prepared = [];
        foreach ($files as $index => $file) {
            $prepared[] = $this->prepareOne($file) ?? throw ValidationException::withMessages([
                "photos.{$index}" => 'Photo '.($index + 1).' is not a JPEG, PNG or WebP image that can be opened.',
            ]);
        }

        return $prepared;
    }

    /**
     * Write the photos and their Evidence records. Paths are added to $written as each file lands,
     * so the caller can delete them if the surrounding transaction fails.
     *
     * @param  list<PreparedPhoto>  $photos
     * @param  list<string>  $written
     * @param  ?MaintenanceRecord  $record  the Maintenance Record Before and After photos belong to
     */
    public function store(Complaint $complaint, User $uploader, array $photos, array &$written, EvidenceType $type = EvidenceType::ReportPhoto, ?MaintenanceRecord $record = null): void
    {
        foreach ($photos as $photo) {
            $path = Str::random(40).'.jpg';
            Storage::disk(Evidence::DISK)->put($path, $photo->bytes);
            $written[] = $path;

            $complaint->evidence()->create([
                'uploaded_by' => $uploader->id,
                'maintenance_record_id' => $record?->id,
                'type' => $type,
                'path' => $path,
                'mime' => 'image/jpeg',
                'size_bytes' => strlen($photo->bytes),
                'width' => $photo->width,
                'height' => $photo->height,
            ]);
        }
    }

    /**
     * Remove files whose Evidence rows were rolled back. Only for that: stored Evidence is never deleted.
     *
     * @param  list<string>  $paths
     */
    public function discard(array $paths): void
    {
        if ($paths === []) {
            return;
        }
        try {
            Storage::disk(Evidence::DISK)->delete($paths);
        } catch (Throwable $failure) {
            report($failure);   // never let clean-up hide the failure that caused it
        }
    }

    private function prepareOne(UploadedFile $file): ?PreparedPhoto
    {
        $info = @getimagesize($file->getRealPath());
        if (! $info || ! in_array($info['mime'], self::ACCEPTED_MIME, true) || $info[0] * $info[1] > self::MAX_PIXELS) {
            return null;
        }

        try {
            $image = $this->images->decodePath($file->getRealPath())->scaleDown(self::MAX_EDGE, self::MAX_EDGE);
            $bytes = $image->encode(new JpegEncoder(quality: 85, strip: true))->toString();
        } catch (ImageException|ErrorException) {   // ErrorException: a GD warning on a corrupt file
            return null;
        }

        return new PreparedPhoto($bytes, $image->width(), $image->height());
    }
}
