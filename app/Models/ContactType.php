<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContactType extends Model
{
    protected $table = 'contact_types';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'code',
        'name',
        'sort',
    ];
}
