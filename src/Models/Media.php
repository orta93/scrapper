<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Media extends Model
{
    use HasFactory;

    protected $fillable = [
        'post_id',
        'reel_id',
        'is_video',
        'video_duration',
        'url'
    ];

    protected $casts = [
        'is_video' => 'boolean'
    ];
}
