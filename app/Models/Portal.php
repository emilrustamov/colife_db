<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

class Portal extends Model
{
    protected $table = 'portals';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'code',
        'name',
        'domain',
        'timezone',
        'apartment_entity_type_id',
        'unit_entity_type_id',
        'apartment_units_field',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'apartment_entity_type_id' => 'integer',
            'unit_entity_type_id' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<Apartment, $this>
     */
    public function apartments(): HasMany
    {
        return $this->hasMany(Apartment::class);
    }

    /**
     * @return HasMany<Unit, $this>
     */
    public function units(): HasMany
    {
        return $this->hasMany(Unit::class);
    }

    /**
     * Resolve portal by code or fail.
     */
    public static function byCode(string $code): self
    {
        $portal = static::query()->where('code', $code)->first();
        if (! $portal instanceof self) {
            throw new RuntimeException('Portal not found: '.$code);
        }

        return $portal;
    }

    /**
     * Cached portal id by code.
     */
    public static function idByCode(string $code): int
    {
        static $cache = [];
        if (! isset($cache[$code])) {
            $cache[$code] = (int) static::byCode($code)->getKey();
        }

        return $cache[$code];
    }
}
