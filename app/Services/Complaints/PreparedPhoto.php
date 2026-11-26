<?php

namespace App\Services\Complaints;

/** A report photo after re-encoding, ready to store: JPEG bytes and the final size. */
final readonly class PreparedPhoto
{
    public function __construct(
        public string $bytes,
        public int $width,
        public int $height,
    ) {}
}
