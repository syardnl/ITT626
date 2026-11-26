<?php

namespace App\Http\Requests\Concerns;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;

/**
 * `?sort=field` (ascending) or `?sort=-field` (descending), where field is a
 * key of the request's sortable() map of public field name => column, or an
 * expression when the stored value does not sort in meaningful order.
 */
trait AllowlistedSort
{
    /** @return array<string, string|Expression> public field name => column or expression */
    abstract protected function sortable(): array;

    protected function sortRule(): array
    {
        $fields = array_keys($this->sortable());

        return ['sometimes', 'string', Rule::in([...$fields, ...array_map(fn (string $field) => "-{$field}", $fields)])];
    }

    /** Applies the requested sort, if any, ahead of whatever default order the caller adds after. */
    public function applySort(Builder $query): Builder
    {
        if (! $this->filled('sort')) {
            return $query;
        }

        $sort = $this->string('sort')->toString();
        $descending = str_starts_with($sort, '-');

        return $query->orderBy($this->sortable()[ltrim($sort, '-')], $descending ? 'desc' : 'asc');
    }
}
