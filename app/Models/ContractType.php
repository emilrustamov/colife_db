<?php

namespace App\Models;

use App\Support\Prices\ContractTypeMapper;
use Illuminate\Database\Eloquent\Model;

class ContractType extends Model
{
    protected $table = 'contract_types';

    protected static function booted(): void
    {
        static::saved(static fn () => ContractTypeMapper::forgetCache());
        static::deleted(static fn () => ContractTypeMapper::forgetCache());
    }

    /**
     * @var list<string>
     */
    protected $fillable = [
        'bitrix_enum_id',
        'name',
        'bucket',
        'sort',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'bitrix_enum_id' => 'integer',
            'sort' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
