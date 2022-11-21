<?php

namespace Markerly\Scrapper\Models;

use Illuminate\Database\Eloquent\Model;

class Account extends Model
{
    protected $with = [
        'posts.account'
    ];

    protected $appends = [
        'uri'
    ];

    protected $fillable = [
        'platform',
        'social_id',
        'alter_id',
        'username',
        'full_name',
        'total_followers',
        'total_following',
        'total_likes',
        'total_views',
        'total_videos',
        'profile_picture_url',
        'bio'
    ];

    public function posts()
    {
        return $this->hasMany(Post::class)->orderBy('id', 'desc');
    }

    public function getUriAttribute()
    {
        switch ($this->platform) {
            case 'instagram':
                return "https://www.instagram.com/{$this->username}";
            case 'tiktok':
                return "https://www.tiktok.com/@{$this->username}";
            case 'youtube':
                return "https://www.youtube.com/user/{$this->username}";
            case 'facebook':
                return "https://www.facebook.com/{$this->username}";
            case 'pinterest':
                return "https://www.pinterest.com/{$this->username}";
            case 'twitter':
                return "https://www.twitter.com/{$this->username}";
            case 'linkedin':
                return "https://www.linkedin.com/in/{$this->username}";
        }
        return null;
    }

    public function token()
    {
        $now = now();
        return $this->hasOne(OAuthToken::class)->where('expires_at', '>', $now);
    }
}
