<?php

namespace Justinjkline\WebCrawlPublic\Models;

use Illuminate\Database\Eloquent\Model;

class Media extends Model
{
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
