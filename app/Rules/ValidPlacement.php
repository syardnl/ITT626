<?php

namespace App\Rules;

use App\Enums\LocationType;
use App\Models\Location;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates a parent_id for a Location of the given type: the parent must exist,
 * be active, accept that type, and (when moving) not be the Location or one of its descendants.
 */
class ValidPlacement implements ValidationRule
{
    /** Run even when parent_id is null: null means "directly under the Property". */
    public bool $implicit = true;

    public function __construct(
        private readonly ?LocationType $type,
        private readonly ?Location $moving = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $this->type) {
            return; // the type itself failed validation
        }

        $parent = $value === null ? null : Location::find($value);

        if ($value !== null && ! $parent) {
            $fail('The chosen parent Location does not exist.');

            return;
        }

        if ($parent && $this->moving?->isSelfOrAncestorOf($parent)) {
            $fail('A Location cannot be placed under itself or one of its own descendants.');

            return;
        }

        if (! $this->type->canBePlacedUnder($parent?->type)) {
            $where = $parent ? 'a '.$parent->type->label() : 'the Property';
            $fail("A {$this->type->label()} cannot be placed under {$where}.");

            return;
        }

        if ($parent && ! $parent->active) {
            $fail('The chosen parent Location is deactivated.');
        }
    }
}
