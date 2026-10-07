<?php

/**
 * This file is part of FPDI
 *
 * @package   setasign\Fpdi
 * @copyright Copyright (c) 2023 Setasign GmbH & Co. KG (https://www.setasign.com)
 * @license   http://opensource.org/licenses/mit-license The MIT License
 */

namespace setasign\Fpdi\PdfParser\Filter;

/**
 * Class for handling zlib/deflate encoded data
 */
class Flate implements FilterInterface
{
    private const MAX_DECODED_BYTES = 33554432;

    public static function resetDecodedBudget()
    {
        DecodedStreamBudget::reset();
    }

    private static function consumeDecodedBytes($data)
    {
        return DecodedStreamBudget::consume($data);
    }

    /**
     * Checks whether the zlib extension is loaded.
     *
     * Used for testing purpose.
     *
     * @return boolean
     * @internal
     */
    protected function extensionLoaded()
    {
        return \extension_loaded('zlib');
    }

    /**
     * Decodes a flate compressed string.
     *
     * @param string|false $data The input string
     * @return string
     * @throws FlateException
     */
    public function decode($data)
    {
        if ($this->extensionLoaded()) {
            $oData = $data;
            $data = (($data !== '') ? @\gzuncompress($data, self::MAX_DECODED_BYTES + 1) : '');
            if ($data === false) {
                // let's try if the checksum is CRC32
                $fh = fopen('php://temp', 'w+b');
                fwrite($fh, "\x1f\x8b\x08\x00\x00\x00\x00\x00" . $oData);
                // "window" == 31 -> 16 + (8 to 15): Uses the low 4 bits of the value as the window size logarithm.
                //                   The input must include a gzip header and trailer (via 16).
                stream_filter_append($fh, 'zlib.inflate', STREAM_FILTER_READ, ['window' => 31]);
                fseek($fh, 0);
                $data = @stream_get_contents($fh, self::MAX_DECODED_BYTES + 1);
                fclose($fh);

                if ($data && \strlen($data) <= self::MAX_DECODED_BYTES) {
                    return self::consumeDecodedBytes($data);
                }

                // Try this fallback (remove the zlib stream header)
                $data = @(gzinflate(substr($oData, 2), self::MAX_DECODED_BYTES + 1));

                if ($data === false || \strlen($data) > self::MAX_DECODED_BYTES) {
                    throw new FlateException(
                        'Error while decompressing stream.',
                        FlateException::DECOMPRESS_ERROR
                    );
                }
            }
        } else {
            throw new FlateException(
                'To handle FlateDecode filter, enable zlib support in PHP.',
                FlateException::NO_ZLIB
            );
        }

        if (\strlen($data) > self::MAX_DECODED_BYTES) {
            throw new FlateException(
                'Decoded stream exceeds the configured safety limit.',
                FlateException::DECOMPRESS_ERROR
            );
        }

        return self::consumeDecodedBytes($data);
    }
}
