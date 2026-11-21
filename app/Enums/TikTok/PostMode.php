<?php

declare(strict_types=1);

namespace App\Enums\TikTok;

/**
 * How a post reaches TikTok (`meta.post_mode`). Direct posts it to the profile
 * (an unaudited app may only post SELF_ONLY); inbox uploads it as a draft the
 * creator finishes and posts in the TikTok app, which needs no privacy level
 * and no Direct Post audit.
 *
 * @see https://developers.tiktok.com/doc/content-posting-api-reference-upload-video
 */
enum PostMode: string
{
    case Direct = 'direct';
    case Inbox = 'inbox';

    public static function fromMeta(mixed $meta): self
    {
        return self::tryFrom((string) data_get($meta, 'post_mode')) ?? self::Direct;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
