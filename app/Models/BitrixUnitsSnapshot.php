<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BitrixUnitsSnapshot extends Model
{
    use HasFactory;

    protected $table = 'bitrix_units_snapshot';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'unit_id',
        'apart_id',
        'is_booked',
        'is_moved_from_termination',
        'is_stage_status',
        'stage',
        'is_sharing',
        'check_in_date',
        'is_idle',
        'synced_at',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'is_idle' => 'boolean',
        'is_booked' => 'boolean',
        'is_moved_from_termination' => 'boolean',
        'is_stage_status' => 'boolean',
        'is_sharing' => 'boolean',
        'check_in_date' => 'datetime',
        'synced_at' => 'datetime',
    ];
}
