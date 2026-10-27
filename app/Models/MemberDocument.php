<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A file on the private disk; read through MemberController@document. */
class MemberDocument extends Model
{
    public const DISK = 'local';

    public const TYPES = ['photo', 'nid', 'signature', 'other'];

    protected $fillable = ['type', 'title'];

    public static function typeLabels(): array
    {
        return [
            'photo' => __('Photo'),
            'nid' => __('NID copy'),
            'signature' => __('Signature'),
            'other' => __('Other'),
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function isImage(): bool
    {
        return str_starts_with((string) $this->mime, 'image/');
    }
}
