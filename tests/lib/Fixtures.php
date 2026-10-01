<?php

/** Временные файлы для загрузки. Удаляются в конце процесса. */
class Fixtures
{
    private static array $created = [];

    public static function image(string $type, int $width, int $height): string
    {
        $img = imagecreatetruecolor($width, $height);
        imagefill($img, 0, 0, imagecolorallocate($img, 200, 50, 50));

        $path = self::tempPath($type);
        match ($type) {
            'jpg'  => imagejpeg($img, $path),
            'png'  => imagepng($img, $path),
            'webp' => imagewebp($img, $path),
            'gif'  => imagegif($img, $path),
        };

        return $path;
    }

    /**
     * PNG, в заголовке которого указаны огромные размеры, а данных почти нет:
     * getimagesize видит размеры, но декодировать такой файл нельзя.
     */
    public static function pngHeaderOnly(int $width, int $height): string
    {
        $chunk = fn(string $type, string $data) =>
            pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));

        $png = "\x89PNG\r\n\x1a\n"
            . $chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0))
            . $chunk('IDAT', gzcompress(''))
            . $chunk('IEND', '');

        return self::write('png', $png);
    }

    public static function bytes(string $ext, int $size): string
    {
        return self::write($ext, str_repeat('a', $size));
    }

    public static function write(string $ext, string $content): string
    {
        $path = self::tempPath($ext);
        file_put_contents($path, $content);

        return $path;
    }

    private static function tempPath(string $ext): string
    {
        $path = tempnam(sys_get_temp_dir(), 'serverai-fixture-');
        rename($path, "$path.$ext");
        self::$created[] = "$path.$ext";

        if (count(self::$created) === 1) {
            register_shutdown_function(fn() => array_map('unlink', array_filter(self::$created, 'is_file')));
        }

        return "$path.$ext";
    }
}
