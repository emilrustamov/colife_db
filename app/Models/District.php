<?php

namespace App\Models;

use App\Support\DistrictNormalizer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class District extends Model
{
    protected $table = 'districts';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'sort',
        'is_deleted',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sort' => 'integer',
            'is_deleted' => 'boolean',
        ];
    }

    /**
     * Normalize Bitrix district value and upsert directory row.
     */
    public static function resolveFromBitrix(mixed $raw): ?self
    {
        $name = DistrictNormalizer::normalize($raw);
        if ($name === null) {
            return null;
        }

        return static::query()->firstOrCreate(
            ['name' => $name],
            ['sort' => 500, 'is_deleted' => false]
        );
    }

    /**
     * @return HasMany<Apartment, $this>
     */
    public function apartments(): HasMany
    {
        return $this->hasMany(Apartment::class);
    }
}
