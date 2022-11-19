<?php

namespace Markerly\Scrapper;

use App\Models\Account;
use App\Models\Media;
use App\Models\Post;
use Carbon\Carbon;
use DOMDocument;
use DOMXPath;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class Scrapper {
    protected array $urls = [
        'instagram' => 'https://www.instagram.com',
        'tiktok' => 'https://www.tiktok.com',
        'youtube' => 'https://www.youtube.com',
        'facebook' => 'https://www.facebook.com',
        'pinterest' => 'https://www.pinterest.com',
        'twitter' => 'https://www.twitter.com',
    ];

    public function postByLink($link)
    {
        $string = $link;
        $link = Str::of($string)->after('https://');
        $link = Str::of($link)->after('www.');

        if (Str::of($string)->contains('vm.tiktok.com')) {
            return $this->getPostFromTiktokLink($string);
        }

        if (Str::of($string)->contains('youtu.be')) {
            $link = Str::of($string)->after('youtu.be/');
            return $this->getAccountByPlatform('youtube', 'https://www.youtube.com', "https://www.youtube.com/watch?v={$link}");
        }

        foreach ($this->urls as $key => $platform) {
            if (Str::startsWith($link, Str::of($platform)->after('https://www.'))) {
                return $this->getAccountByPlatform($key, $platform, 'https://www.'.$link);
            }
        }
    }

    public function getPostFromTiktokLink($link, $onlyLink = false)
    {
        $res = Http::withHeaders($this->getTikTokHeaders())->get($link);
        if ($res->getStatusCode() === 200) {
            $html = $res->getBody();
            $dom = new DOMDocument();
            @$dom->loadHTML($html);
            $metas = $dom->getElementsByTagName('meta');
            for ($i = 0; $i < $metas->length; $i++) {
                $meta = $metas->item($i);
                if ($meta->getAttribute('property') == 'og:url') {
                    $url = $meta->getAttribute('content');
                    if ($onlyLink) {
                        return $url;
                    }
                    return $this->getAccountByPlatform('tiktok', $this->urls['tiktok'], $url);
                }
            }
        }

        throw new Exception('Service unavailable', 503);
    }

    private function prepareForTikTok($link, $platform): ?\Illuminate\Http\JsonResponse
    {
        $link = Str::of($link)->after("$platform/@");
        $items = explode('/', $link);
        $account = $items[0];

        $video_params = explode('?', $items[2]);
        if ($account !== '' && $video_params[0] !== '') {
            return $this->getTikTokPost($account, $video_params[0]);
        }

        return null;
    }

    private function getAccountByPlatform($key, $platform, $link)
    {
        switch ($key) {
            case 'instagram':
                if (Str::contains($link, 'stories')) {
                    $link = Str::of($link)->after("$platform/stories/");
                    $items = explode('/', $link);
                    if ($items[0] !== '') {
                        $post_id = isset($items[1]) ? ($items[1] != '' ? $items[1] : null) : null;
                        $post_id = Str::of($post_id)->before('?');
                        return $this->storeInstagramStories($items[0], $post_id);
                    }
                }
                $isTv = Str::contains($link, '/tv/');
                $isReel = Str::contains($link, '/reel/');

                $urlVerb = $isTv ? 'tv' : ($isReel ? 'reel' : 'p');
                $link = Str::of($link)->after("$platform/$urlVerb/");

                $items = explode('/', $link);
                if ($items[0] !== '') {
                    return $this->getInstagramPost($items[0], $isTv, $isReel, $urlVerb);
                }
                break;
            case 'tiktok':
                if (Str::of($link)->contains('/video/')) {
                    return $this->prepareForTikTok($link, $platform);
                } else {
                    try {
                        $ch = curl_init($link);
                        curl_setopt($ch, CURLOPT_HEADER, false);
                        curl_setopt($ch, CURLOPT_USERAGENT,'Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.8.1.13) Gecko/20080311 Firefox/2.0.0.13');
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ch, CURLOPT_BINARYTRANSFER, true);
                        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
                        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
                        $html = curl_exec($ch);
                        $url = curl_getinfo($ch,CURLINFO_EFFECTIVE_URL );
                        curl_close($ch);

                        if ($url) {
                            return $this->prepareForTikTok($url, $platform);
                        }
                    } catch (Exception $exception) {
                        throw new Exception('Service unavailable', 503);
                    }
                }
                break;
            case 'youtube':
                $link = Str::of($link)->after("$platform/watch?v=");
                $items = explode('&', $link);
                if ($items[0] !== '') {
                    return $this->getYouTubePost($items[0]);
                }
                break;
            case 'pinterest':
                $link = Str::of($link)->after("$platform/pin/");

                $items = explode('/', $link);
                if ($items[0] !== '') {
                    return $this->getPinterestPost($items[0]);
                }
                break;
            case 'twitter':
                $link = Str::of($link)->after("$platform/");
                $items = explode('/status/', $link);
                if (count($items) >= 2) {
                    return $this->getTwitterPost($items[0], $items[1]);
                }
                break;
        }

        throw new Exception('Service unavailable', 503);
    }

    private function getBusinessInstagram($account, $internal = false)
    {
        $ig_profile = config('services.instagram_profile');
        $helper = '{';
        $access_token = config('services.instagram_access_token');

        $profile_params = ['id', 'ig_id', 'profile_picture_url', 'name', 'username', 'follows_count', 'biography','followers_count'];
        $profile_params_inline = implode(',', $profile_params);

        $media_params = ['id', 'caption', 'like_count', 'comments_count', 'views_count', 'timestamp', 'username', 'media_product_type', 'media_type', 'permalink', 'media_url', 'video_duration'];
        $media_params_inline = implode(',', $media_params);

        $fields = urlencode("{$helper}{$profile_params_inline},media{$helper}{$media_params_inline},children{media_url}}}");

        $link = "https://graph.facebook.com/v15.0/{$ig_profile}?fields=business_discovery.username($account){$fields}&access_token={$access_token}";
        $response = Http::get($link);
        $profile = null;
        if ($json = json_decode($response->getBody()->getContents())) {
            if ($profile_data = $json->business_discovery ?? null) {
                if ($profile = $this->storeBusinessInstagramProfile($profile_data)) {
                    $this->storeBusinessInstagramPost($profile_data, $profile, '', '', false, false, true);
                    $profile = $profile->fresh();
                }
            }
        }
        return $profile;
    }

    private function getInstagram($account, $internal = false)
    {
        $route = "{$this->urls['instagram']}/{$account}/?__a=1";

        $profile = Account::where('platform', 'instagram')->where('username', $account)->first();
        $headers = $this->getInstagramHeaders();

        $res = Http::withHeaders($headers)->get($route);
        if ($res->getStatusCode() == 200) {
            if ($json = json_decode($res->getBody()->getContents())) {
                $user_account = $json->graphql->user;

                $profile = $this->storeInstagramProfile($user_account);

                if (!$internal && $user_account->edge_owner_to_timeline_media->count) {
                    $posts = collect($user_account->edge_owner_to_timeline_media->edges)->reverse();

                    foreach ($posts as $post) {
                        $this->storeInstagramPost($post->node, $profile);
                    }
                }

                $profile = $profile->fresh();
            } else {
                $route = "https://i.instagram.com/api/v1/users/web_profile_info/?username={$account}";
                $headers = $this->getInstagramHeaders(true, true);
                $res = Http::withHeaders($headers)->get($route);

                if ($res->getStatusCode() == 200) {
                    if ($json = json_decode($res->getBody()->getContents())) {
                        $user_account = $json->data->user;
                        $profile = $this->storeInstagramProfile($user_account);

                        $profile = $profile->fresh();
                    }
                }
            }
        }

        if ($profile) {
            return $internal ? $profile : response()->json($profile);
        }

        throw new Exception('Instagram service unavailable', 503);
    }

    private function getInstagramPost($postId, $isTv = false, $isReel = false, $urlVerb = 'p')
    {
        $alter_route = "{$this->urls['instagram']}/p/{$postId}";

        $post = Post::with('account')
            ->where(function ($query) use ($postId) {
                return $query->where('post_identifier', $postId)->orWhere('url', 'like', "%{$postId}%");
            })
            ->where('is_tv', $isTv)
            ->where('is_reel', $isReel)
            ->first();
        $headers = $this->getInstagramHeaders();
        $res = Http::withHeaders($headers)->get($alter_route);
        $html = $res->getBody()->getContents();

        $dom = new DOMDocument();
        @$dom->loadHTML($html);

        $metas = $dom->getElementsByTagName('meta');
        $display_url = '';
        for ($i = 0; $i < $metas->length; $i++) {
            $meta = $metas->item($i);
            $meta_name = $meta->getAttribute('name');
            $meta_property = $meta->getAttribute('property');
            $content = $meta->getAttribute('content');

            if ($display_url == '' && Str::of($meta_name)->contains('twitter:image')) {
                $display_url = (string)$content;
            }

            if ($display_url == '' && Str::of($meta_property)->contains('og:image')) {
                $display_url = (string)$content;
            }

            if (Str::of($meta_name)->contains('twitter:title')) {
                $username = (string)Str::of($content)->after('@')->before(')');
            }

            if (Str::of($content)->contains('instagram://media?id=')) {
                $obtained_id = (string)Str::of($content)->after('instagram://media?id=');
            }
        }

        if (is_null($obtained_id)) {
            $xpath = new DOMXPath($dom);
            $script_tags = $xpath->query('//body//script[not(@src)]');

            foreach ($script_tags as $tag) {
                $str = Str::of($tag->nodeValue);
                if ($str->contains('requireLazy') && $str->contains('media_id')) {
                    $ids_external = $str->after('"media_id":')->explode(',');
                    if (count($ids_external)) {
                        if ($value = json_decode($ids_external[0])) {
                            $obtained_id = $value;
                        }
                    }
                }
            }
        }

        if (!is_null($obtained_id)) {
            $post = $this->scrapInstagramPost($post, $obtained_id, $isTv, $isReel, $urlVerb);
        }

        if ($post) {
            return response()->json($post);
        }

        throw new Exception('Instagram service unavailable', 503);
    }

    private function scrapInstagramPost($post, $id, $isTv, $isReel, $urlVerb)
    {
        $obtained_link = "https://i.instagram.com/api/v1/media/{$id}/info/";
        $headers = $this->getInstagramHeaders(true, true);
        $res = Http::withHeaders($headers)->get($obtained_link);
        if ($json = json_decode($res->getBody()->getContents())) {
            if ($json->items && count($json->items)) {
                $media = $json->items[0];
                $media->id = $media->id ?? $media->pk;

                $owner = isset($media->user) ? $media->user->username : $media->owner->username;
                $profile = $this->getInstagram($owner, true);

                $post = $this->storeInstagramPost($media, $profile, $isTv, $isReel, $urlVerb);

                $post->account;
                $post->media;
            }
        }

        return $post;
    }

    private function getFacebookHeaders()
    {
        $headers = [
            'authority'     => 'www.facebook.com',
            'accept'        => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.9',
            'accept-language'=> 'es-419,es;q=0.9',
            'cache-control' => 'max-age=0',
            'cookie' => base64_decode(config('services.facebook_cookie')),
            'sec-ch-prefers-color-scheme' => 'dark',
            'sec-ch-ua' => '" Not A;Brand";v="99", "Chromium";v="101", "Microsoft Edge";v="101"',
            'sec-ch-ua-mobile' => '?0',
            'sec-ch-ua-platform' => '"Windows"',
            'sec-fetch-dest' => 'document',
            'sec-fetch-mode' => 'navigate',
            'sec-fetch-site' => 'same-origin',
            'sec-fetch-user' => '?1',
            'upgrade-insecure-requests' => '1',
            'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/101.0.4951.54 Safari/537.36 Edg/101.0.1210.39',
        ];

        return $headers;
    }

    private function getInstagramHeaders($history = false, $versioned = false)
    {
        $headers = [
            'authority'     => $history ? 'i.instagram.com' : 'www.instagram.com',
            'accept'        => $history ? '*/*' : 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.9',
            'x-ig-www-claim'=> 'hmac.AR2nZ00xPISKkNa8K1OxknDF41rdMaQzqGoU-FttMjyRLiKB',
            'user-agent'    => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/101.0.4951.64 Safari/537.36 Edg/101.0.1210.53',
            'x-ig-app-id'   => '936619743392459',
            'origin'        => 'https://www.instagram.com',
            'sec-fetch-site'=> $history ? 'same-site' : 'none',
            'sec-fetch-mode'=> $history ? 'cors' : 'navigate',
            'sec-fetch-dest'=> $history ? 'empty' : 'document',
            'referer'       => 'https://www.instagram.com/',
            'accept-language'=> 'es-419,es;q=0.9,es-ES;q=0.8,en;q=0.7,en-GB;q=0.6,en-US;q=0.5',
            'cache-control' => 'max-age=0',
            'upgrade-insecure-requests' => '1',
            'sec-fetch-user' => '?1',
            'cookie'        => base64_decode(config($history ? 'services.instagram_cookie_stories' : 'services.instagram_cookie'))
        ];

        if ($versioned) {
            $headers['sec-ch-ua'] = '" Not A;Brand";v="99", "Chromium";v="101", "Microsoft Edge";v="101"';
            $headers['sec-ch-ua-mobile'] = '?0';
            $headers['sec-ch-ua-platform'] = 'Windows';
            $headers['x-asbd-id'] = '198387';
            $headers['x-csrftoken'] = '5ViccIUbhy80SRgiB6X5ecnzVdsYofIT';
            $headers['x-ig-app-id'] = '936619743392459';

            $headers['cookie'] = base64_decode(config('services.instagram_versioned_cookie'));
        }

        if (!$history) {
            $unset_items = ['x-ig-www-claim', 'x-ig-app-id', 'origin'];
        } else {
            $unset_items = ['cache-control', 'upgrade-insecure-requests', 'sec-fetch-user'];
        }

        foreach ($unset_items as $item) {
            if (isset($headers[$item])) {
                unset($headers[$item]);
            }
        }

        return $headers;
    }

    private function storeInstagramProfile($user_account)
    {
        Account::where('platform', 'instagram')->where('social_id', $user_account->id)->update(['social_id' => $user_account->fbid]);
        return Account::updateOrCreate([
            'platform' => 'instagram',
            'social_id' => $user_account->fbid
        ], [
            'alter_id' => $user_account->id,
            'username' => $user_account->username,
            'full_name' => $user_account->full_name ?? '',
            'total_followers' => $user_account->edge_followed_by->count ?? 0,
            'total_following' => $user_account->edge_follow->count ?? 0,
            'total_likes' => 0,
            'profile_picture_url' => $user_account->profile_pic_url_hd ?? $user_account->profile_pic_url,
            'bio' => $user_account->biography ?? ''
        ]);
    }

    private function storeBusinessInstagramProfile($user_account)
    {
        if ($account = Account::where('platform', 'instagram')->where('social_id', $user_account->ig_id)->first()) {
            $account->social_id = $user_account->id;
            $account->save();
        }

        return Account::updateOrCreate([
            'platform' => 'instagram',
            'social_id' => $user_account->id
        ], [
            'alter_id' => $user_account->ig_id,
            'username' => $user_account->username,
            'full_name' => $user_account->name ?? '',
            'total_followers' => $user_account->followers_count ?? 0,
            'total_following' => $user_account->follows_count ?? 0,
            'total_likes' => 0,
            'profile_picture_url' => $user_account->profile_picture_url ?? '',
            'bio' => $user_account->biography ?? ''
        ]);
    }

    private function storeInstagramPost($node, $profile, $isTv = false, $isReel = false, $urlVerb = 'p')
    {
        $caption = $node->caption ? $node->caption->text : '';

        if ($caption === '' && isset($node->edge_media_to_caption)) {
            if (count($node->edge_media_to_caption->edges)) {
                if ($node->edge_media_to_caption->edges[0]->node) {
                    $caption = $node->edge_media_to_caption->edges[0]->node->text;
                }
            }
        }

        $is_video = isset($node->video_duration) ? true : (isset($node->is_video) ? $node->is_video : false);

        $likes = 0;
        if (isset($node->like_count)) {
            $likes = $node->like_count;
        } elseif (isset($node->edge_liked_by)) {
            $likes = $node->edge_liked_by->count;
        } elseif (isset($node->edge_media_preview_like)) {
            $likes = $node->edge_media_preview_like->count;
        }

        $comments = 0;
        if (isset($node->comment_count)) {
            $comments = $node->comment_count;
        } elseif (isset($node->edge_media_to_comment)) {
            $comments = $node->edge_media_to_comment->count;
        } elseif (isset($node->edge_media_to_parent_comment)) {
            $comments = $node->edge_media_to_parent_comment->count;
        }

        $views = 0;
        if ($is_video) {
            if (isset($node->play_count)) {
                $views = $node->play_count;
            } elseif (isset($node->view_count)) {
                $views = $node->view_count;
            } elseif (isset($node->video_play_count)) {
                $views = $node->video_play_count;
            } elseif (isset($node->video_view_count)) {
                $views = $node->video_view_count;
            }
        }

        $displayUrl = $this->getDisplayUrl($node);

        $post = Post::where('post_identifier', $node->id)->first();
        $taken_at = isset($node->taken_at) ? $node->taken_at : (isset($node->taken_at_timestamp) ? $node->taken_at_timestamp : null);
        $code = $node->code ?? $node->shortcode;
        $post_data = [
            'post_identifier' => $node->id,
            'account_id' => $profile->id,
            'date' => $taken_at ? Carbon::createFromTimestamp($taken_at) : now(),
            'is_video' => $is_video,
            'is_tv' => $isTv,
            'is_reel' => $isReel,
            'url' => "{$this->urls['instagram']}/{$urlVerb}/{$code}",
            'caption' => $caption,
            'display_url' => $displayUrl,
            'likes' => $likes,
            'comments' => $post ? (max($post->comments, $comments)) : $comments,
            'views' => $views,
            'shares' => 0
        ];

        $post = $this->savePostAndUpdateStats($post, $post_data, $likes, $comments, $views);

        if ($post) {
            Media::where('post_id', $post->id)->delete();

            if (isset($node->video_versions)) {
                $media_data = [
                    'post_id' => $post->id,
                    'is_video' => $is_video,
                    'video_duration' => $this->getTime($node),
                    'url' => $is_video ? $node->video_versions[0]->url : $displayUrl
                ];

                Media::create($media_data);

            } elseif (isset($node->carousel_media)) {
                foreach ($node->carousel_media as $media_edge) {
                    $mediaUrl = $media_edge->image_versions2->candidates[0]->url;
                    $media_data = [
                        'post_id' => $post->id,
                        'is_video' => $is_video,
                        'url' => $mediaUrl
                    ];
                    Media::create($media_data);
                }
            } elseif (isset($node->image_versions2)) {
                $mediaUrl = $node->image_versions2->candidates[0]->url;
                $media_data = [
                    'post_id' => $post->id,
                    'is_video' => $is_video,
                    'url' => $mediaUrl
                ];
                Media::create($media_data);
            } elseif (isset($node->edge_sidecar_to_children)) {
                foreach ($node->edge_sidecar_to_children->edges as $media_edge) {
                    $media_data = [
                        'post_id' => $post->id,
                        'is_video' => $media_edge->node->is_video ?? false,
                        'url' => ($media_edge->node->is_video ?? false) ? $media_edge->node->video_url : $media_edge->node->display_url
                    ];
                    Media::create($media_data);
                }
            } elseif (isset($node->video_url) || isset($node->display_url)) {
                $media_data = [
                    'post_id' => $post->id,
                    'is_video' => $is_video,
                    'video_duration' => $this->getTime($node),
                    'url' => $is_video ? $node->video_url : $node->display_url
                ];

                Media::create($media_data);
            }
        }
        return $post;
    }

    private function storeBusinessInstagramPost($data, $profile, $route, $display_url, $isTv, $isReel, $all = false)
    {
        $posts = $data->media ? collect($data->media->data) : collect();

        $item = null;

        $posts->each(function ($node) use ($profile, $route, $display_url, $isTv, $isReel, $all, &$item) {
            $route = rtrim($route, '/');
            if ($all || $node->permalink == "{$route}/") {
                $caption = $node->caption ?? '';

                $is_video = $node->media_type != 'IMAGE';
                $likes = $node->like_count ?? 0;
                $comments = $node->comments_count ?? 0;
                $views = $node->views_count ?? 0;

                $post = Post::where('post_identifier', $node->id)->first();
                $taken_at = $node->timestamp ?? null;
                $post_data = [
                    'post_identifier' => $node->id,
                    'account_id' => $profile->id,
                    'date' => $taken_at ? Carbon::createFromTimestamp($taken_at) : now(),
                    'is_video' => $is_video,
                    'is_tv' => $isTv,
                    'is_reel' => $isReel,
                    'url' => $node->permalink,
                    'caption' => $caption,
                    'display_url' => $display_url,
                    'likes' => $likes,
                    'comments' => $post ? (max($post->comments, $comments)) : $comments,
                    'views' => $views,
                    'shares' => 0
                ];

                $post = $this->savePostAndUpdateStats($post, $post_data, $likes, $comments, $views);

                if ($post) {
                    Media::where('post_id', $post->id)->delete();
                    $change_display = false;

                    if (isset($node->children)) {
                        foreach ($node->children->data as $media_edge) {
                            if ($display_url == '') {
                                $change_display = true;
                                $display_url = $media_edge->media_url;
                            }
                            $media_data = [
                                'post_id' => $post->id,
                                'is_video' => $is_video,
                                'url' => $media_edge->media_url
                            ];
                            Media::create($media_data);
                        }
                    } elseif (isset($node->media_url)) {
                        if ($display_url == '') {
                            $change_display = true;
                            $display_url = $node->media_url;
                        }
                        $media_data = [
                            'post_id' => $post->id,
                            'is_video' => $is_video,
                            'url' => $node->media_url
                        ];

                        Media::create($media_data);
                    }

                    if ($change_display) {
                        $post->display_url = $display_url;
                        $post->save();
                    }
                }

                $post->account;
                $post->media;

                $item = $post;
            }
        });

        return $item;
    }

    private function getDisplayUrl($node)
    {
        if (isset($node->carousel_media)) {
            $candidates = $node->carousel_media[0]->image_versions2->candidates;
        } else {
            $candidates = $node->image_versions2->candidates;
        }

        if (count($candidates)) {
            return $candidates[0]->url;
        }

        if (isset($node->display_url)) {
            return $node->display_url;
        }

        if (isset($node->edge_sidecar_to_children)) {
            if (count($node->edge_sidecar_to_children->edges)) {
                return $node->edge_sidecar_to_children->edges[0]->node->display_url;
            }
        }

        return null;
    }

    private function getTime($node)
    {
        if (isset($node->video_duration) || isset($node->duration)) {
            return gmdate('H:i:s', floor($node->video_duration ?? $node->duration ?? 0));
        }
        return 0;
    }

    private function savePostAndUpdateStats($post, $post_data, $likes, $comments, $views = 0, $dislikes = 0, $shares = 0)
    {
        if ($post) {
            $elements = ['likes', 'comments', 'views', 'shares'];
            foreach ($elements as $element) {
                $old = $post->{$element};
                $new = ${$element};
                $post_data[$element] = $old < $new ? $new : $old;
            }
            $post->update($post_data);
        } else {
            $post = Post::create($post_data);
        }
        return $post;
    }

    private function storeInstagramStories($user, $post_id)
    {
        if ($profile = $this->getInstagram($user, true)) {
            $route = "https://i.instagram.com/api/v1/feed/reels_media/?reel_ids={$profile->alter_id}";
            $post = Post::where('post_identifier', $post_id)->where('is_story', true)->first();
            if ($post) {
                return $this->returnPost($post, $post_id);
            }
            $res = Http::withHeaders($this->getInstagramHeaders(true))->get($route);
            if ($res->getStatusCode() == 200) {
                if ($json = json_decode($res->getBody()->getContents())) {
                    if (count($json->reels_media)) {
                        $json = $json->reels_media[0];
                        $items = collect($json->items);
                        $total = count($items);

                        if ($total) {
                            $first = $items[0];
                            $latest = $items[$total - 1];

                            if (!is_null($post_id) && (string)$post_id !== '') {
                                $nested = $items->where('pk', $post_id)->first();
                                if (!is_null($nested)) {
                                    $first = $latest = $nested;
                                }
                            }

                            if (!is_null($first) && !is_null($latest)) {
                                $post = Post::updateOrCreate([
                                    'post_identifier' => $latest->pk,
                                    'account_id' => $profile->id,
                                    'is_story' => true
                                ], [
                                    'date' => $latest->taken_at ? Carbon::createFromTimestamp($latest->taken_at) : now(),
                                    'is_video' => false,
                                    'url' => "{$this->urls['instagram']}/stories/{$profile->username}/{$latest->pk}/",
                                    'caption' => '',
                                    'display_url' => isset($first->image_versions2) ? $first->image_versions2->candidates[0]->url : null,
                                ]);
                            }

                            if ($post) {
                                foreach ($items as $key => $item) {
                                    $is_video = ($item->video_duration ?? 0) > 0;
                                    Media::updateOrCreate([
                                        'post_id' => $post->id,
                                        'is_video' => $is_video,
                                        'reel_id' => $item->pk,
                                    ], [
                                        'video_duration' => $this->getTime($item),
                                        'url' => $is_video ? ($item->video_versions[0]->url) : (isset($item->image_versions2) ? $item->image_versions2->candidates[0]->url : null)
                                    ]);
                                }
                            }
                        }
                    }
                }
            }

            if ($post) {
                return $this->returnPost($post, $post_id);
            }
        }

        throw new Exception('Instagram service unavailable', 503);
    }

    private function returnPost($post, $post_id)
    {
        $post->account;
        if (!is_null($post_id)) {
            $post = json_decode($post->toJson());
            $post->media = Media::where('post_id', $post->id)->where('reel_id', $post_id)->get();
            if (!count($post->media)) {
                $post->media = Media::where('post_id', $post->id)->get();
            }
        } else {
            $post->media;
        }
        return response()->json($post);
    }

    private function fixAccount($string)
    {
        if ($string[0] == '@') {
            return substr($string, 1);
        }
        return $string;
    }

    private function getTikTok($account, $savePosts = true)
    {
        $route = "{$this->urls['tiktok']}/share/user/{$account}";

        $profile = Account::where('platform', 'tiktok')->where('username', $account)->first();
        try {
            $res = Http::withHeaders($this->getTikTokHeaders())->get($route);
            if ($res->getStatusCode() == 200) {
                return $res->getBody()->getContents();
                $html = (string)$res->getBody();
                $dom = new DOMDocument();
                @$dom->loadHTML($html);

                if ($json = json_decode($dom->getElementById('__NEXT_DATA__')->nodeValue)) {
                    $pageProps = $json->props->pageProps;
                    $items = collect($pageProps->items)->reverse();

                    if ($profile = $this->storeTikTokProfile($account)) {
                        if ($savePosts) {
                            foreach ($items as $key => $item) {
                                $this->storeTikTokPost($item, $profile);
                            }

                            $profile = $profile->fresh();
                        } else {
                            return $profile;
                        }
                    }
                }
            }
        } catch (Exception $exception) {
            \Log::info($exception->getMessage());
            if (app()->bound('sentry') && $this->mustThrow()) {
                app('sentry')->captureException($exception);
            }
        }
        if ($profile) {
            return response()->json($profile);
        }

        if ($this->mustThrow()) {
            throw new Exception('TikTok service unavailable', 503);
        }
    }

    private function getSignedTiktokPost($profile, $post)
    {
        $fields = [
            'id',
            'title',
            'create_time',
            'cover_image_url',
            'share_url',
            'video_description',
            'duration',
            'embed_html',
            'embed_link',
            'like_count',
            'comment_count',
            'share_count',
            'view_count'
        ];
        $fieldsImplode = implode(',', $fields);
        $route = "https://open.tiktokapis.com/v2/video/query/?fields={$fieldsImplode}";
        $body = ['filters' => ['video_ids' => [$post]]];

        $res = Http::withToken($profile->token->access_token)->post($route, $body);

        if ($json = json_decode($res->getBody()->getContents())) {
            if ($json->data && $json->data->videos && count($json->data->videos)) {
                $node = $json->data->videos[0];
                $likes = $node->like_count;
                $comments = $node->comment_count;
                $views = $node->view_count;
                $shares = $node->share_count;

                $post = Post::where('post_identifier', $node->id)->first();
                $post_data = [
                    'post_identifier' => $node->id,
                    'account_id' => $profile->id,
                    'date' => $node->create_time ? Carbon::createFromTimestamp($node->create_time) : now(),
                    'is_video' => true,
                    'url' => "{$this->urls['tiktok']}/@{$profile->username}/video/{$node->id}",
                    'caption' => $node->video_description ?? '',
                    'display_url' => $node->cover_image_url,
                    'likes' => $likes,
                    'comments' => $comments,
                    'views' => $views,
                    'shares' => $shares
                ];

                return $this->savePostAndUpdateStats($post, $post_data, $likes, $comments, $views, 0, $shares);
            }
        }

        return null;
    }

    private function getTikTokPost($account, $post)
    {
        $signed_account = Account::with('token')->whereHas('token')->where('username', $account)->first();
        if ($signed_account) {
            $signed_post = $this->getSignedTiktokPost($signed_account, $post);
        }
        $signature = env('TIKTOK_SIGNATURE');
        $route = "{$this->urls['tiktok']}/node/share/video/@{$account}/{$post}?_signature={$signature}";
        $alter_route = "{$this->urls['tiktok']}/@{$account}/video/{$post}";
        $postId = $post;

        $post = Post::with('account')->where('post_identifier', $post)->first();
        $res = Http::withHeaders($this->getTikTokHeaders())->get($route);
        if ($res->getStatusCode() == 200) {
            if ($json = json_decode($res->getBody()->getContents())) {
                $item = $json->itemInfo ? $json->itemInfo->itemStruct : null;
                if ($item && $item->author) {
                    if ($profile = $this->storeTikTokProfileFormatted($item->author, $item->authorStats)) {
                        $post = $this->storeTikTokPost($item, $profile);
                        $post->account;
                        $post->media;
                    }
                }
            }
        } else {
            $res = Http::withHeaders($this->getTikTokHeaders())->get($alter_route);
            if ($res->getStatusCode() == 200) {
                $html = (string)$res->getBody();
                $dom = new DOMDocument();
                @$dom->loadHTML($html);
                $node = $dom->getElementById('SIGI_STATE');
                if ($node && $json = json_decode($node->nodeValue)) {
                    if ($json->ItemModule && $json->ItemModule->{$postId}) {
                        $data = $json->ItemModule->{$postId};
                        $signature = '';
                        if ($json->UserModule && $json->UserModule->users && $json->UserModule->users->{$account}) {
                            $account = $json->UserModule->users->{$account};
                            if ($account->signature) {
                                $signature = $account->signature;
                            }
                        }
                        $profileItem = (Object)[
                            'id' => $data->authorId,
                            'uniqueId' => $data->author,
                            'nickname' => $data->nickname,
                            'avatarLarger' => $data->avatarThumb,
                            'signature' => $signature
                        ];

                        if ($profile = $this->storeTikTokProfileFormatted($profileItem, $data->authorStats)) {
                            $post = $this->storeTikTokPost($data, $profile);
                            $post->account;
                            $post->media;
                        }
                    }
                }
            }
        }

        if ($post) {
            return response()->json($post);
        }

        throw new Exception('TikTok service unavailable', 503);
    }

    private function storeTikTokProfile($account)
    {
        $profileRoute = "{$this->urls['tiktok']}/node/share/user/@{$account}";
        $userRes = Http::get($profileRoute);
        if ($userRes->getStatusCode() == 200) {
            if ($userJson = json_decode($userRes->getBody()->getContents())) {
                $user_account = $userJson->userInfo;

                return Account::updateOrCreate([
                    'platform' => 'tiktok',
                    'social_id' => $user_account->user->id
                ], [
                    'username' => $user_account->user->uniqueId,
                    'full_name' => $user_account->user->nickname,
                    'total_followers' => $user_account->stats->followerCount ?? 0,
                    'total_following' => $user_account->stats->followingCount ?? 0,
                    'total_likes' => $user_account->stats->heartCount ?? 0,
                    'profile_picture_url' => $user_account->user->avatarLarger ?? '',
                    'bio' => $user_account->user->signature ?? ''
                ]);
            }
        }
        return null;
    }

    private function storeTikTokProfileFormatted($user_account, $stats)
    {
        Account::where('platform', 'tiktok')->where('social_id', $user_account->uniqueId)->update(['social_id' => $user_account->id]);
        return Account::updateOrCreate([
            'platform' => 'tiktok',
            'social_id' => $user_account->id
        ], [
            'username' => $user_account->uniqueId,
            'full_name' => $user_account->nickname,
            'total_followers' => $stats->followerCount ?? 0,
            'total_following' => $stats->followingCount ?? 0,
            'total_likes' => $stats->heartCount ?? 0,
            'profile_picture_url' => $user_account->avatarLarger ?? '',
            'bio' => $user_account->signature ?? ''
        ]);
    }

    private function storeTikTokPost($node, $profile)
    {
        $likes = $node->stats->diggCount ?? 0;
        $comments = $node->stats->commentCount ?? 0;
        $views = $node->stats->playCount ?? 0;
        $shares = $node->stats->shareCount ?? 0;

        $post = Post::where('post_identifier', $node->id)->first();
        $post_data = [
            'post_identifier' => $node->id,
            'account_id' => $profile->id,
            'date' => $node->createTime ? Carbon::createFromTimestamp($node->createTime) : now(),
            'is_video' => true,
            'url' => "{$this->urls['tiktok']}/@{$profile->username}/video/{$node->id}",
            'caption' => $node->desc ?? '',
            'display_url' => $node->video->cover,
            'likes' => $likes,
            'comments' => $comments,
            'views' => $views,
            'shares' => $shares
        ];

        $post = $this->savePostAndUpdateStats($post, $post_data, $likes, $comments, $views, 0, $shares);

        if ($post) {
            Media::where('post_id', $post->id)->delete();
            Media::create([
                'post_id' => $post->id,
                'is_video' => true,
                'video_duration' => $this->getTime($node->video),
                'url' => $node->video->downloadAddr ?? $node->video->playAddr ?? $node->video->cover ?? ''
            ]);
        }

        return $post;
    }

    private function getTikTokHeaders()
    {
        return [
            'cookie' => config('services.tiktok_cookie'),
            'authority' => 'www.tiktok.com',
            'accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.9',
            'accept-language' => 'es-419,es;q=0.9,es-ES;q=0.8,en;q=0.7,en-GB;q=0.6,en-US;q=0.5',
            'cache-control' => 'max-age=0',
            'sec-ch-ua' => '"Microsoft Edge";v="105", " Not;A Brand";v="99", "Chromium";v="105"',
            'sec-ch-ua-mobile' => '?0',
            'sec-ch-ua-platform' => '"Windows"',
            'sec-fetch-dest' => 'document',
            'sec-fetch-mode' => 'navigate',
            'sec-fetch-site' => 'none',
            'sec-fetch-user' => '?1',
            'upgrade-insecure-requests' => '1',
            'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/105.0.0.0 Safari/537.36 Edg/105.0.1343.42'
        ];
    }

    private function getYouTube($account)
    {
        $key = config('services.google_api_key');
        $results = request()->get('results', 5);
        $uri = "https://www.googleapis.com/youtube/v3/search?key={$key}&channelId={$account}&part=snippet,id&order=date&maxResults={$results}";

        $profile = Account::where('platform', 'youtube')->where('username', $account)->first();
        $res = Http::get($uri);
        if ($res->getStatusCode() == 200) {
            if ($json = json_decode($res->getBody()->getContents())) {
                $profile = $this->storeYouTubeProfile($key, $account);
                if ($profile && count($json->items)) {
                    $items = collect($json->items)->reverse();
                    foreach ($items as $item) {
                        $this->storeYouTubePost($key, $profile, $item->id->videoId);
                    }

                    $profile = $profile->fresh();
                }
            }
        }

        if ($profile) {
            return response()->json($profile);
        }

        throw new Exception('YouTube service unavailable', 503);
    }

    private function getYouTubePost($post_id)
    {
        $key = config('services.google_api_key');

        $post = Post::with('account')->where('post_identifier', $post_id)->first();
        $post = $this->storeYouTubePost($key, null, $post_id, true);
        $post->account;
        $post->media;

        if ($post) {
            return response()->json($post);
        }

        throw new Exception('YouTube service unavailable', 503);
    }

    private function storeYouTubeProfile($key, $account)
    {
        $uri = "https://www.googleapis.com/youtube/v3/channels?key={$key}&id={$account}&part=snippet,id,statistics";
        $res = Http::get($uri);
        if ($res->getStatusCode() == 200) {
            if ($json = json_decode($res->getBody()->getContents())) {
                if (count($json->items)) {
                    $item = $json->items[0];
                    return Account::updateOrCreate([
                        'platform' => 'youtube',
                        'social_id' => $item->id
                    ], [
                        'username' => $item->snippet->title,
                        'full_name' => '',
                        'total_followers' => $item->statistics->subscriberCount ?? 0,
                        'total_views' => $item->statistics->viewCount ?? 0,
                        'total_videos' => $item->statistics->videoCount ?? 0,
                        'profile_picture_url' => $item->snippet->thumbnails->high->url ?? '',
                        'bio' => $item->snippet->description ?? ''
                    ]);
                }
            }
        }
        return null;
    }

    private function storeYouTubePost($key, $profile, $videoId, $storeProfile = false)
    {
        $uri = "https://www.googleapis.com/youtube/v3/videos?key={$key}&id={$videoId}&part=snippet,id,statistics";

        $res = Http::get($uri);
        if ($res->getStatusCode() == 200) {
            if ($json = json_decode($res->getBody()->getContents())) {
                if (count($json->items)) {
                    $node = $json->items[0];
                    if ($storeProfile) {
                        $profile = $this->storeYouTubeProfile($key, $node->snippet->channelId);
                    }

                    $likes = $node->statistics->likeCount ?? 0;
                    $dislikes = $node->statistics->dislikeCount ?? 0;
                    $comments = $node->statistics->commentCount ?? 0;
                    $views = $node->statistics->viewCount ?? 0;

                    $post = Post::where('post_identifier', $node->id)->first();
                    $post_data = [
                        'post_identifier' => $node->id,
                        'account_id' => $profile->id,
                        'date' => $node->snippet->publishedAt ? $node->snippet->publishedAt : now(),
                        'is_video' => true,
                        'url' => "{$this->urls['youtube']}/watch?v={$node->id}",
                        'caption' => $node->snippet->title ?? '',
                        'description' => $node->snippet->description ?? '',
                        'likes' => $likes,
                        'display_url' => isset($node->snippet->thumbnails->medium) ? $node->snippet->thumbnails->medium->url : $node->snippet->thumbnails->maxres->url,
                        'dislikes' => $dislikes,
                        'comments' => $comments,
                        'views' => $views,
                        'shares' => 0
                    ];

                    $post = $this->savePostAndUpdateStats($post, $post_data, $likes, $comments, $views, $dislikes);

                    if ($post && $post->wasRecentlyCreated && $node->snippet->thumbnails) {
                        Media::create([
                            'post_id' => $post->id,
                            'is_video' => true,
                            'url' => isset($node->snippet->thumbnails->medium) ? $node->snippet->thumbnails->medium->url : $node->snippet->thumbnails->maxres->url
                        ]);
                    }

                    return $post;
                }
            }
        }
        return null;
    }

    private function storePinterestProfile($user_account)
    {
        return Account::updateOrCreate([
            'platform' => 'pinterest',
            'social_id' => $user_account->id
        ], [
            'username' => $user_account->username,
            'full_name' => $user_account->full_name ?? $user_account->first_name,
            'total_followers' => $user_account->follower_count ?? 0,
            'total_following' => 0,
            'total_likes' => 0,
            'profile_picture_url' => $user_account->image_medium_url ?? $user_account->image_small_url ?? '',
            'bio' => $user_account->domain_url ?? ''
        ]);
    }

    public function getPinterestPost($link)
    {
        $uri = "https://www.pinterest.com/pin/{$link}";
        $post = Post::with('account')->where('post_identifier', $link)->first();
        $res = Http::get($uri);
        if ($res->getStatusCode() == 200) {
            $html = (string)$res->getBody();
            $dom = new DOMDocument();
            @$dom->loadHTML($html);

            if ($json = json_decode($dom->getElementById('__PWS_DATA__')->nodeValue)) {
                if ($json->props && $json->props->initialReduxState && $pins = $json->props->initialReduxState->pins) {
                    if (isset($pins->{$link})) {
                        $pin = $pins->{$link};
                        if (isset($pin->closeup_attribution) && $profile = $this->storePinterestProfile($pin->closeup_attribution)) {
                            $image = $pin->images ? ($pin->images->orig ? $pin->images->orig->url : '') : '';

                            $post = Post::updateOrCreate([
                                'post_identifier' => $pin->id,
                                'account_id' => $profile->id,
                            ], [
                                'date' => now(),
                                'is_video' => 0,
                                'is_tv' => 0,
                                'is_reel' => 0,
                                'url' => "{$this->urls['pinterest']}/pin/{$pin->id}",
                                'caption' => isset($pin->closeup_unified_description) ? $pin->closeup_unified_description : '',
                                'display_url' => $image,
                                'likes' => 0,
                                'comments' => 0,
                                'views' => 0,
                                'shares' => isset($pin->repin_count) ? $pin->repin_count : 0
                            ]);

                            if ($post && $post->wasRecentlyCreated) {
                                Media::create([
                                    'post_id' => $post->id,
                                    'is_video' => false,
                                    'url' => $image
                                ]);
                            }

                            $post->account;
                            $post->media;
                        }
                    }
                }
            }
        }

        if ($post) {
            return response()->json($post);
        }

        throw new Exception('Pinterest service unavailable', 503);
    }

    private function getTwitterHeaders()
    {
        $auth = config('services.twitter.auth');

        return [
            'authorization' => "Bearer {$auth}"
        ];
    }

    private function getTwitterPost($account_id, $post_id)
    {
        $uri = "{$this->urls['twitter']}/{$account_id}/status/{$post_id}";
        $post = Post::with('account')->where('post_identifier', $post_id)->first();

        $params = [
            'ids' => [$post_id],
            'tweet.fields' => [
                'attachments',
                'author_id',
                'created_at',
                'id',
                'public_metrics',
                'source',
                'text',
                'withheld'
            ],
            'media.fields' => [
                'alt_text',
                'duration_ms',
                'height',
                'media_key',
                'preview_image_url',
                'public_metrics',
                'type',
                'url',
                'variants',
                'width'
            ],
            'user.fields' => [
                'created_at',
                'description',
                'id',
                'name',
                'profile_image_url',
                'public_metrics',
                'url',
                'username'
            ],
            'expansions' => ['attachments.media_keys','author_id']
        ];
        $params_arr = collect();
        foreach ($params as $key => $param) {
            $vars = implode(',', $param);
            $str = "{$key}={$vars}";
            $params_arr->push($str);
        }
        $url_params = $params_arr->implode('&');
        $link = "https://api.twitter.com/2/tweets/?{$url_params}";

        $res = Http::withHeaders($this->getTwitterHeaders())->get($link);
        if ($res->getStatusCode() == 200) {
            if ($json = json_decode($res->getBody()->getContents())) {
                if ($json->data && count($json->data) && isset($json->includes->users) && count($json->includes->users)) {
                    $post_data = $json->data[0];
                    $post_id = $post_data->id;
                    $account = $json->includes->users[0];

                    $profile = Account::updateOrCreate([
                        'platform' => 'twitter',
                        'social_id' => $account->id
                    ], [
                        'username' => $account->username,
                        'full_name' => $account->name ?? $account->username,
                        'total_followers' => $account->public_metrics->followers_count ?? 0,
                        'total_following' => $account->public_metrics->following_count ?? 0,
                        'total_likes' => 0,
                        'profile_picture_url' => $account->profile_image_url ?? $account->profile_image_url ?? '',
                        'bio' => $account->description ?? ''
                    ]);

                    if ($account && $json->includes->media && count($json->includes->media)) {
                        $medias = $json->includes->media;
                        $is_video = false;
                        if (isset($json->includes->media[0])) {
                            $is_video = $json->includes->media[0]->type === 'video';
                        }

                        $image = isset($medias[0]) ? (isset($medias[0]->url) ? $medias[0]->url : (isset($medias[0]->preview_image_url) ? $medias[0]->preview_image_url : '')) : '';
                        $post = Post::updateOrCreate([
                            'post_identifier' => $post_id,
                            'account_id' => $profile->id,
                        ], [
                            'date' => Carbon::parse($post_data->created_at),
                            'is_video' => $is_video,
                            'is_tv' => 0,
                            'is_reel' => 0,
                            'url' => $uri,
                            'caption' => isset($post_data->text) ? $post_data->text : '',
                            'display_url' => $image,
                            'likes' => isset($post_data->public_metrics->like_count) ? $post_data->public_metrics->like_count : 0,
                            'comments' => isset($post_data->public_metrics->reply_count) ? $post_data->public_metrics->reply_count : 0,
                            'views' => isset($medias[0]->public_metrics) && isset($medias[0]->public_metrics->view_count) ? $medias[0]->public_metrics->view_count : 0,
                            'shares' => isset($post_data->public_metrics->retweet_count) ? $post_data->public_metrics->retweet_count : 0,
                        ]);
                        if ($post && $post->wasRecentlyCreated) {
                            if ($is_video) {
                                if (count($medias[0]->variants)) {
                                    $duration = new \stdClass();
                                    $duration->video_duration = ceil(($medias[0]->duration_ms ?? 0) / 1000);
                                    Media::create([
                                        'post_id' => $post->id,
                                        'video_duration' => $this->getTime($duration),
                                        'is_video' => true,
                                        'url' => $medias[0]->variants[0]->url
                                    ]);
                                }
                            } else {
                                foreach ($medias as $media) {
                                    Media::create([
                                        'post_id' => $post->id,
                                        'is_video' => $is_video,
                                        'url' => $media->url
                                    ]);
                                }
                            }
                        }

                        $post->account;
                        $post->media;
                        return response()->json($post);
                    }
                }
            }
        }

        if ($post) {
            return response()->json($post);
        }

        throw new Exception('Service unavailable', 503);
    }

    private function searchAccountByPlatform($key, $platform, $link)
    {
        switch ($key) {
            case 'instagram':
                if (Str::contains($link, 'stories')) {
                    $link = Str::of($link)->after("$platform/stories/");
                    $items = explode('/', $link);
                    if ($items[0] !== '') {
                        $post_id = isset($items[1]) ? ($items[1] != '' ? $items[1] : null) : null;
                        $post_id = Str::of($post_id)->before('?');
                        return $this->storeInstagramStories($items[0], $post_id);
                    }
                }
                $isTv = Str::contains($link, '/tv/');
                $isReel = Str::contains($link, '/reel/');

                $urlVerb = $isTv ? 'tv' : ($isReel ? 'reel' : 'p');
                $link = Str::of($link)->after("$platform/$urlVerb/");

                $items = explode('/', $link);
                if ($items[0] !== '') {
                    return $this->getInstagramPost($items[0], $isTv, $isReel, $urlVerb);
                }
                break;
        }

        throw new Exception('Service unavailable', 503);
    }

    private function searchInPlatform($link, $platform = 'facebook')
    {
        $final_url = $link;

//        $link = Str::of($link)->replace('https://www.', 'https://m.');

        $description = null;
        $medium = 'image';
        $url = null;
        $display_url = null;
        $title = null;
        $profile_url = null;
        $username = '';
        $post_id = '';

        if (Str::of($link)->contains('https://www.')) {
            $res = Http::get($link);
            if ($res->getStatusCode() === 200) {
                $html = $res->getBody()->getContents();

                $dom = new DOMDocument();
                @$dom->loadHTML($html);

                $metas = $dom->getElementsByTagName('meta');
                for ($i = 0; $i < $metas->length; $i++) {
                    $meta = $metas->item($i);

                    $name = $meta->getAttribute('name');
                    $property = $meta->getAttribute('property');
                    $content = $meta->getAttribute('content');

                    if ($property == 'og:video') {
                        $url = $content;
                        $medium = 'video';
                    }

                    if ($name == 'description') {
                        $description = $content;
                    }

                    if ($property == 'og:image') {
                        $display_url = $content;
                    }

                    if ($property == 'og:title') {
                        $title = $content;
                    }

                    if ($property == 'og:url') {
                        $final_url = $content;
                        $profile_url = $content;
                    }

                    if ($platform == 'instagram' && Str::of($name)->contains('twitter:title')) {
                        $username = (string)Str::of($content)->after('@')->before(')');
                    }

                    if ($platform == 'instagram' && Str::of($content)->contains('instagram://media?id=')) {
                        $post_id = (string)Str::of($content)->after('instagram://media?id=');
                    }
                }

                if ($description || $url || $display_url || $title) {
                    $profile_picture_url = $display_url;
                    $full_name = $title;
                    if ($profile_url && $platform == 'facebook') {
                        $start = 'https://www.facebook.com';
                        $profile_url = Str::of($profile_url)->replace($start, '');
                        $profile_url = explode('?', $profile_url);
                        if (count($profile_url)) {
                            $profile_url = $profile_url[0];
                        }
                        $profile_items = trim($profile_url, '/');
                        $profile_items_arr = explode('/', $profile_items);
                        $username = $profile_items_arr[0];
                        if ($username === 'story.php') {
                            $profile_url = Str::of($link)->replace('https://www.facebook.com', '');
                            $profile_items = trim($profile_url, '/');
                            $profile_items_arr = explode('/', $profile_items);
                            $username = $profile_items_arr[0];
                        }
                        $profile_url = "https://www.facebook.com/{$username}";
                        $full_profile = $this->searchProfile('facebook', $username, $profile_url);
                        $post_id = $profile_items_arr[count($profile_items_arr) - 1];

                        if ($full_profile['description'] !== '' || $full_profile['full_name'] !== '' || $full_profile['profile_picture_url'] !== '') {
                            $profile_picture_url = $full_profile['profile_picture_url'];
                            $full_name = $full_profile['full_name'] !== '' ? $full_profile['full_name'] : $title;
                        }
                    }

                    if ($profile_url && $username != '' && $platform == 'instagram') {
                        $profile_url = "https://www.instagram.com/{$username}";
                        $full_profile = $this->searchProfile('instagram', $username, $profile_url);

                        if ($full_profile['description'] !== '' || $full_profile['full_name'] !== '' || $full_profile['profile_picture_url'] !== '') {
                            $profile_picture_url = $full_profile['profile_picture_url'];
                            $full_name = $full_profile['full_name'] !== '' ? $full_profile['full_name'] : $title;
                        }

                        if ($title != '') {
                            $full_name = Str::of($title)->before("(@{$username})");
                        }
                    }

                    $final_url = explode('?', $final_url);
                    $final_url = $final_url[0];

                    return response()->json([
                        'caption' => $description,
                        'medium' => $medium,
                        'url' => $url ?? $display_url,
                        'platform' => $platform,
                        'post_url' => $final_url,
                        'date' => Carbon::now()->format('Y-m-d'),
                        'display_url' => $display_url,
                        'profile_picture_url' => $profile_picture_url,
                        'profile_url' => $profile_url,
                        'bio' => '',
                        'full_name' => $full_name,
                        'username' => $username,
                        'social_id' => $username,
                        'post_identifier' => $post_id,
                        'video_duration' => '00:00:00',
                    ]);
                }
            }
        }

        throw new Exception('Service unavailable', 503);
    }

    private function searchProfile($platform, $username, $link)
    {
        $description = '';
        $full_name = '';
        $profile_picture_url = '';

        $res = Http::get($link);
        if ($res->getStatusCode() === 200) {
            $html = $res->getBody()->getContents();

            $dom = new DOMDocument();
            @$dom->loadHTML($html);

            $metas = $dom->getElementsByTagName('meta');
            for ($i = 0; $i < $metas->length; $i++) {
                $meta = $metas->item($i);

                $name = $meta->getAttribute('name');
                $property = $meta->getAttribute('property');
                $content = $meta->getAttribute('content');

                if ($property == 'og:description') {
                    $description = $content;
                }

                if ($property == 'og:image') {
                    $profile_picture_url = $content;
                }

                if ($property == 'og:title') {
                    $full_name = $content;
                }
            }
        }

        return [
            'description' => $description,
            'full_name' => $full_name,
            'profile_picture_url' => $profile_picture_url
        ];
    }

    private function mustThrow($request = null)
    {
        if (is_null($request)) {
            $request = request();
        }

        return !$request->filled('must_fail') || !$request->boolean('must_fail');
    }
}