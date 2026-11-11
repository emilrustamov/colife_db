<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OlBotScopeTool extends Model
{
    protected $table = 'ol_bot_scope_tools';

    protected $fillable = [
        'scope_key',
        'tool_key',
        'sort_order',
    ];
}
