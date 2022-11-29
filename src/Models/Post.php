<?php

namespace Justinjkline\WebCrawlPublic\Models;

use Illuminate\Database\Eloquent\Model;

class Post extends Model
{
    protected $with = [
        'media'
    ];

    protected $casts = [
        'is_video' => 'boolean',
        'is_story' => 'boolean',
        'is_tv' => 'boolean',
        'is_reel' => 'boolean',
    ];

    protected $dates = [
        'date',
    ];

    protected $appends = [
        'is_recent',
    ];

    protected $fillable = [
        'post_identifier',
        'account_id',
        'url',
        'date',
        'is_story',
        'is_video',
        'is_tv',
        'is_reel',
        'display_url',
        'caption',
        'description',
        'likes',
        'dislikes',
        'comments',
        'views',
        'shares',
    ];

    public function media()
    {
        return $this->hasMany(Media::class);
    }

    public function account()
    {
        return $this->belongsTo(Account::class)->without('posts');
    }

    public function getIsRecentAttribute()
    {
        return $this->wasRecentlyCreated;
    }
}
