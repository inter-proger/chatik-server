<?php

require_once __DIR__ . '/DB.php';
require_once __DIR__ . '/Response.php';
require_once __DIR__ . '/ErrorCode.php';
require_once __DIR__ . '/../config/files.php';

class File
{
    const TYPE_IMAGE = 'image';
    const TYPE_OTHER = 'other';

    /** MIME-тип => расширение для сохранения на диске. GIF не считается изображением — для него будет отдельный тип */
    const IMAGE_MIMES = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
    ];

    /**
     * Возвращает сущность файла по id или null, если не найден.
     *
     * Формат: fileId, type, url, size, filename, extension, data
     */
    public static function findById(int $id): ?array
    {
        $stmt = DB::get()->prepare(
            'SELECT id, type, path, size, filename, extension, data FROM files WHERE id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row ? self::fromRow($row) : null;
    }

    public static function fromRow(array $row): array
    {
        $data = json_decode($row['data'], true) ?: [];

        // В БД для картинки хранится small_path, наружу отдаём smallUrl
        if ($row['type'] === self::TYPE_IMAGE) {
            $data = ['smallUrl' => self::url($data['small_path'] ?? $row['path'])];
        } else {
            $data = [];
        }

        return [
            'fileId'    => (int) $row['id'],
            'type'      => $row['type'],
            'url'       => self::url($row['path']),
            'size'      => (int) $row['size'],
            'filename'  => $row['filename'],
            'extension' => $row['extension'],
            'data'      => (object) $data,
        ];
    }

    /**
     * Сохраняет загруженное изображение на диск, делает уменьшенную копию
     * и создаёт запись в БД. Возвращает сущность файла.
     *
     * @param array  $upload   элемент $_FILES
     * @param string $filename имя файла для сущности
     */
    public static function storeImage(array $upload, string $filename, int $userId): array
    {
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($upload['tmp_name']);
        $diskExt = self::IMAGE_MIMES[$mime] ?? null;

        if ($diskExt === null || @getimagesize($upload['tmp_name']) === false) {
            Response::error(ErrorCode::FILE_NOT_IMAGE);
        }

        [$absDir, $relDir] = self::makeUploadDir();
        $base = bin2hex(random_bytes(16));

        $relPath      = "$relDir/$base.$diskExt";
        $relSmallPath = "$relDir/{$base}_small.$diskExt";

        if (!move_uploaded_file($upload['tmp_name'], "$absDir/$base.$diskExt")) {
            Response::error(ErrorCode::FILE_UPLOAD_FAILED);
        }

        if (!self::makeSmallCopy("$absDir/$base.$diskExt", "$absDir/{$base}_small.$diskExt", $mime)) {
            @unlink("$absDir/$base.$diskExt");
            Response::error(ErrorCode::FILE_UPLOAD_FAILED);
        }

        $stmt = DB::get()->prepare(
            'INSERT INTO files (user_id, type, path, size, filename, extension, data)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $userId,
            self::TYPE_IMAGE,
            $relPath,
            (int) $upload['size'],
            $filename,
            self::extensionOf($filename),
            json_encode(['small_path' => $relSmallPath]),
        ]);

        return self::findById((int) DB::get()->lastInsertId());
    }

    /** Расширение из имени файла; если его нет — "file". */
    public static function extensionOf(string $filename): string
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        return $ext !== '' ? $ext : 'file';
    }

    /** Абсолютный URL для пути относительно корня проекта. */
    private static function url(string $relPath): string
    {
        $https  = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        $scheme = $https ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
        return "$scheme://$host/" . ltrim($relPath, '/');
    }

    /** Создаёт каталог вида static/uploads/YYYY/MM, возвращает [абсолютный, относительный]. */
    private static function makeUploadDir(): array
    {
        $relDir = UPLOAD_DIR . '/' . date('Y/m');
        $absDir = dirname(__DIR__) . '/' . $relDir;

        if (!is_dir($absDir) && !mkdir($absDir, 0775, true) && !is_dir($absDir)) {
            Response::error(ErrorCode::FILE_UPLOAD_FAILED);
        }

        return [$absDir, $relDir];
    }

    /**
     * Делает копию изображения, у которой меньшая сторона не превышает IMAGE_SMALL_SIDE.
     * Если исходник уже меньше — просто копирует файл.
     */
    private static function makeSmallCopy(string $src, string $dst, string $mime): bool
    {
        [$w, $h] = getimagesize($src);
        $minSide = min($w, $h);

        if ($minSide <= IMAGE_SMALL_SIDE) {
            return copy($src, $dst);
        }

        $ratio = IMAGE_SMALL_SIDE / $minSide;
        $nw = max(1, (int) round($w * $ratio));
        $nh = max(1, (int) round($h * $ratio));

        $image = match ($mime) {
            'image/jpeg' => imagecreatefromjpeg($src),
            'image/png'  => imagecreatefrompng($src),
            'image/webp' => imagecreatefromwebp($src),
            default      => false,
        };
        if ($image === false) {
            return false;
        }

        $small = imagecreatetruecolor($nw, $nh);

        // Сохраняем прозрачность для png/webp
        if ($mime !== 'image/jpeg') {
            imagealphablending($small, false);
            imagesavealpha($small, true);
            imagefill($small, 0, 0, imagecolorallocatealpha($small, 0, 0, 0, 127));
        }

        imagecopyresampled($small, $image, 0, 0, 0, 0, $nw, $nh, $w, $h);

        $ok = match ($mime) {
            'image/jpeg' => imagejpeg($small, $dst, 85),
            'image/png'  => imagepng($small, $dst, 6),
            'image/webp' => imagewebp($small, $dst, 85),
        };

        return $ok;
    }
}
