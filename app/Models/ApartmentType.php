<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ApartmentType extends Model
{
    use HasFactory;

    protected $table = 'apartment_types';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'bitrix_enum_id',
        'code',
        'name',
        'sort',
    ];
}

