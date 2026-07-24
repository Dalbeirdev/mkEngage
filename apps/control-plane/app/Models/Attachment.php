<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class Attachment extends Model
{
    use BelongsToOrganization;
    use HasUuids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_CLEAN = 'clean';

    public const STATUS_QUARANTINED = 'quarantined';

    protected $fillable = [
        'organization_id',
        'conversation_id',
        'message_id',
        'uploader_type',
        'uploader_id',
        'file_name',
        'content_type',
        'size_bytes',
        'checksum_sha256',
        'storage_path',
        'scan_status',
    ];

    /** @return BelongsTo<Conversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /** @return BelongsTo<Message, $this> */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    /**
     * Contract shape (AsyncAPI messageNew.attachments / OpenAPI Attachment).
     *
     * @return array<string, mixed>
     */
    public function toContract(): array
    {
        return [
            'attachment_id' => $this->id,
            'file_name' => $this->file_name,
            'content_type_header' => $this->content_type,
            'size_bytes' => (int) $this->size_bytes,
            'scan_status' => $this->scan_status,
        ];
    }
}
