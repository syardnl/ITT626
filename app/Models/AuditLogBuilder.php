<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use LogicException;

/** Query builder for AuditLog that refuses bulk changes, keeping the log append-only. */
class AuditLogBuilder extends Builder
{
    public function update(array $values)
    {
        throw new LogicException('Audit records are append-only and cannot be changed.');
    }

    public function delete()
    {
        throw new LogicException('Audit records are append-only and cannot be deleted.');
    }

    public function forceDelete()
    {
        return $this->delete();
    }
}
