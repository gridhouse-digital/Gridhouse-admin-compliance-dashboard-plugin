<?php

namespace setasign\Fpdi\PdfParser\Filter;

final class DecodedStreamBudget
{
    private const MAX_BYTES = 33554432;
    private static $bytes = 0;

    public static function reset()
    {
        self::$bytes = 0;
    }

    public static function consume($data)
    {
        self::$bytes += \strlen($data);
        if (self::$bytes > self::MAX_BYTES) {
            throw new FlateException(
                'Decoded certificate content exceeds the configured safety limit.',
                FlateException::DECOMPRESS_ERROR
            );
        }
        return $data;
    }
}
