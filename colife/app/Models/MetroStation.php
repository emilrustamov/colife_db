<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MetroStation extends Model
{
    use HasFactory;

    protected $table = 'metro_stations';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'bitrix_id',
        'name',
    ];
}

