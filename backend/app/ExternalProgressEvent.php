<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class ExternalProgressEvent extends Model
{
    protected $guarded = [];

    protected $casts = [
        'completed' => 'boolean',
    ];
}
