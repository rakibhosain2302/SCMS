<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MemberNominee extends Model
{
    protected $fillable = ['name', 'relation', 'nid', 'phone', 'date_of_birth', 'share', 'address'];

    protected function casts(): array
    {
        return ['date_of_birth' => 'date', 'share' => 'decimal:2'];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }
}
