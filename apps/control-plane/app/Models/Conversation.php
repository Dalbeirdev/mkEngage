<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property Carbon|null $closed_at
 * @property int|null $csat_rating
 * @property string|null $csat_comment
 * @property Carbon|null $csat_rated_at
 * @property list<string>|null $tags
 * @property array<string, mixed>|null $flow_state
 */
final class Conversation extends Model
{
    use BelongsToOrganization;
    use HasUuids;

    protected $fillable = [
        'organization_id',
        'status',
        'visitor_id',
        'contact_id',
        'assigned_agent_id',
        'department_id',
        'chatbot_id',
        'source_url',
    ];

    protected function casts(): array
    {
        return [
            'last_sequence' => 'integer',
            'closed_at' => 'datetime',
            'csat_rating' => 'integer',
            'csat_rated_at' => 'datetime',
            'tags' => 'array',
            'flow_state' => 'array',
        ];
    }

    /** @return BelongsTo<Visitor, $this> */
    public function visitor(): BelongsTo
    {
        return $this->belongsTo(Visitor::class);
    }

    /** @return BelongsTo<Contact, $this> */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /** @return BelongsTo<Department, $this> */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /** @return BelongsTo<User, $this> */
    public function assignedAgent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_agent_id');
    }

    /** @return HasMany<Message, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }
}
