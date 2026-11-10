<?php

namespace App\Support;

use RuntimeException;
use Symfony\Component\HttpFoundation\Request;

class PhotoCommentRequestBody
{
    public const MAX_BYTES = 8192;

    public static function appliesTo(Request $request): bool
    {
        $path = rtrim($request->getPathInfo(), '/') ?: '/';

        return $request->getRealMethod() === 'POST'
            && preg_match('#\A/photo-posts/[0-9]+/comments\z#', rawurldecode($path)) === 1;
    }

    /** @param resource $stream */
    public static function read($stream): ?string
    {
        $content = '';

        while (strlen($content) <= self::MAX_BYTES) {
            $chunk = fread($stream, min(4096, self::MAX_BYTES + 1 - strlen($content)));
            if ($chunk === false) {
                throw new RuntimeException('Unable to read comment request.');
            }

            if ($chunk === '') {
                break;
            }

            $content .= $chunk;
        }

        return strlen($content) > self::MAX_BYTES ? null : $content;
    }
}
