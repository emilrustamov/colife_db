<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Contact extends Model
{
    use HasFactory;

    protected $table = 'contacts';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'id',
        'bitrix_id',
        'first_name',
        'last_name',
        'contact_type_id',
        'nationality',
        'birth_date',
        'gender',
        'language',
        'is_deleted',
        'bitrix_created_at',
        'bitrix_updated_at',
        'last_synced_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_deleted' => 'boolean',
            'birth_date' => 'date',
            'bitrix_created_at' => 'datetime',
            'bitrix_updated_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<ContactType, $this>
     */
    public function contactType(): BelongsTo
    {
        return $this->belongsTo(ContactType::class);
    }

    /**
     * @return HasMany<ContactPhone, $this>
     */
    public function phones(): HasMany
    {
        return $this->hasMany(ContactPhone::class);
    }

    /**
     * @return HasMany<ContactEmail, $this>
     */
    public function emails(): HasMany
    {
        return $this->hasMany(ContactEmail::class);
    }
}

