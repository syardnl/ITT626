<?php

namespace App\Services\Complaints;

use App\Enums\EvidenceType;
use Illuminate\Http\UploadedFile;

/** What the Technician sends with Complete work: the result, optional parts, notes and expense lines, and tagged photos. */
final readonly class CompletionDetails
{
    /**
     * @param  list<array{description: string, quantity: string, amount: string}>  $expenses  amount is the line's total in MYR
     * @param  list<array{file: UploadedFile, type: EvidenceType}>  $photos  in the order sent; at least one After photo
     */
    public function __construct(
        public string $repairResult,
        public ?string $partsUsed,
        public ?string $notes,
        public array $expenses,
        public array $photos,
        /** The Idempotency-Key: a retry returns the record this key first created. */
        public ?string $key = null,
    ) {}
}
