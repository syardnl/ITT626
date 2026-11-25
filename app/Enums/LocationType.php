<?php

namespace App\Enums;

/** The kinds of node in the Property's Location tree, and where each may be placed. */
enum LocationType: string
{
    case Block = 'block';
    case Floor = 'floor';
    case Unit = 'unit';
    case CommonArea = 'common_area';

    /**
     * Types this one may sit under; null stands for the Property itself.
     * Block under Property; Floor under Block; Unit under Floor;
     * Common Area under Property, Block or Floor. Units and Common Areas hold nothing.
     *
     * @return list<?self>
     */
    public function allowedParents(): array
    {
        return match ($this) {
            self::Block => [null],
            self::Floor => [self::Block],
            self::Unit => [self::Floor],
            self::CommonArea => [null, self::Block, self::Floor],
        };
    }

    public function canBePlacedUnder(?self $parent): bool
    {
        return in_array($parent, $this->allowedParents(), true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Block => 'Block',
            self::Floor => 'Floor',
            self::Unit => 'Unit',
            self::CommonArea => 'Common Area',
        };
    }
}
