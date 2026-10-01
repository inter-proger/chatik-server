<?php

class FilesTest extends TestCase
{
    private ApiClient $client;

    public function setUp(): void
    {
        parent::setUp();
        [$this->client] = $this->signup('alice');
    }

    private function upload(string $path, array $fields = [], string $mime = 'application/octet-stream', ?string $name = null): array
    {
        return $this->client->post('/files/uploadImage', $fields + [
            'file' => new CURLFile($path, $mime, $name ?? basename($path)),
        ]);
    }

    /** Абсолютный путь на диске по URL из ответа */
    private function diskPath(string $url): string
    {
        return dirname(__DIR__, 2) . '/' . substr($url, strlen(BASE_URL) + 1);
    }

    // ---- /files/uploadImage ----

    public function testUploadPng(): void
    {
        $res = $this->upload(Fixtures::image('png', 50, 40), [], 'image/png', 'Pic.PNG');

        $this->assertSuccess($res);
        $file = $res['response'];
        $this->assertSame(1, $file['fileId']);
        $this->assertSame('image', $file['type']);
        $this->assertSame('Pic.PNG', $file['filename']);
        $this->assertSame('png', $file['extension']);
        $this->assertStringStartsWith(BASE_URL . '/static/uploads/' . date('Y/m') . '/', $file['url']);
        $this->assertStringStartsWith(BASE_URL . '/static/uploads/', $file['data']['smallUrl']);

        $original = $this->diskPath($file['url']);
        $this->assertTrue(is_file($original), 'Original must be stored on disk');
        $this->assertSame(filesize($original), $file['size']);
        // Маленькая картинка копируется как есть
        $this->assertSame(file_get_contents($original), file_get_contents($this->diskPath($file['data']['smallUrl'])));
    }

    public function testUploadResizesLargeJpeg(): void
    {
        $res = $this->upload(Fixtures::image('jpg', 600, 400));

        $small = getimagesize($this->diskPath($res['response']['data']['smallUrl']));
        $this->assertSame([300, 200], [$small[0], $small[1]]);
        $this->assertSame('image/jpeg', $small['mime']);
    }

    public function testUploadResizesPortraitPngKeepingAlpha(): void
    {
        $res = $this->upload(Fixtures::image('png', 300, 900));

        $path  = $this->diskPath($res['response']['data']['smallUrl']);
        $small = getimagesize($path);
        $this->assertSame([200, 600], [$small[0], $small[1]]);
        $this->assertSame('image/png', $small['mime']);
    }

    public function testUploadWebp(): void
    {
        $res = $this->upload(Fixtures::image('webp', 400, 400));

        $this->assertSuccess($res);
        $this->assertSame('image/webp', getimagesize($this->diskPath($res['response']['data']['smallUrl']))['mime']);
    }

    public function testUploadDiskExtensionFollowsContentNotName(): void
    {
        $res = $this->upload(Fixtures::image('png', 10, 10), [], 'image/jpeg', 'fake.jpg');

        $this->assertSame('jpg', $res['response']['extension']);
        $this->assertTrue(str_ends_with($res['response']['url'], '.png'), 'Stored file must use real type extension');
    }

    public function testUploadFilenameParam(): void
    {
        $res = $this->upload(Fixtures::image('png', 10, 10), ['filename' => '  Отпуск.webp  ']);

        $this->assertSame('Отпуск.webp', $res['response']['filename']);
        $this->assertSame('webp', $res['response']['extension']);
    }

    public function testUploadFilenameWithoutExtension(): void
    {
        $res = $this->upload(Fixtures::image('png', 10, 10), ['filename' => 'noext']);

        $this->assertSame('file', $res['response']['extension']);
    }

    public function testUploadFilenameIsTruncatedAndScrubbed(): void
    {
        $res = $this->upload(Fixtures::image('png', 10, 10), ['filename' => "bad\xFF" . str_repeat('я', 300)]);

        $this->assertSuccess($res);
        $this->assertSame(255, mb_strlen($res['response']['filename']));
        $this->assertTrue(mb_check_encoding($res['response']['filename'], 'UTF-8'));
    }

    public function testUploadOriginalNameIsBasename(): void
    {
        $res = $this->upload(Fixtures::image('png', 10, 10), [], 'image/png', '../../etc/pic.png');

        $this->assertSame('pic.png', $res['response']['filename']);
    }

    public function testUploadNotAuthenticated(): void
    {
        $res = $this->client()->post('/files/uploadImage', ['file' => new CURLFile(Fixtures::image('png', 10, 10))]);

        $this->assertError(ErrorCode::NOT_AUTHENTICATED, $res);
    }

    public function testUploadMissingFile(): void
    {
        $this->assertError(ErrorCode::FILE_MISSING, $this->client->post('/files/uploadImage', ['filename' => 'x.png']));
    }

    public function testUploadNotImage(): void
    {
        $this->assertError(ErrorCode::FILE_NOT_IMAGE, $this->upload(Fixtures::write('png', 'just text'), [], 'image/png'));
    }

    public function testUploadGifIsNotImage(): void
    {
        $this->assertError(ErrorCode::FILE_NOT_IMAGE, $this->upload(Fixtures::image('gif', 10, 10), [], 'image/gif'));
    }

    public function testUploadTooManyPixels(): void
    {
        $this->assertError(ErrorCode::FILE_TOO_LARGE, $this->upload(Fixtures::pngHeaderOnly(6000, 5000)));
    }

    public function testUploadBrokenImageDataIsRemovedFromDisk(): void
    {
        $before = $this->uploadedFiles();

        // Заголовок валиден, но декодировать нечего — уменьшенная копия не создастся
        $res = $this->upload(Fixtures::pngHeaderOnly(400, 400));

        $this->assertError(ErrorCode::FILE_UPLOAD_FAILED, $res);
        $this->assertSame($before, $this->uploadedFiles());
        $this->assertSame('0', (string) DB::get()->query('SELECT COUNT(*) FROM files')->fetchColumn());
    }

    public function testUploadLargerThanAppLimit(): void
    {
        // Больше UPLOAD_MAX_SIZE, но меньше upload_max_filesize сервера (см. tests/run.php)
        $this->assertError(ErrorCode::FILE_TOO_LARGE, $this->upload(Fixtures::bytes('png', UPLOAD_MAX_SIZE + 1)));
    }

    public function testUploadLargerThanIniLimit(): void
    {
        $this->assertError(ErrorCode::FILE_TOO_LARGE, $this->upload(Fixtures::bytes('png', 13 * 1024 * 1024)));
    }

    // ---- /files/get ----

    public function testGet(): void
    {
        $uploaded = $this->upload(Fixtures::image('png', 10, 10))['response'];

        // Авторизация не требуется, fileId принимается и в GET, и в POST
        $this->assertSame($uploaded, $this->client()->get('/files/get', ['fileId' => $uploaded['fileId']])['response']);
        $this->assertSame($uploaded, $this->client()->post('/files/get', ['fileId' => $uploaded['fileId']])['response']);
    }

    public function testGetNotFound(): void
    {
        foreach (['', '0', '-5', 'abc', '999'] as $fileId) {
            $this->assertError(ErrorCode::FILE_NOT_FOUND, $this->client()->get('/files/get', ['fileId' => $fileId]));
        }
    }

    public function testGetOtherTypeHasEmptyData(): void
    {
        $fileId = $this->insertFile($this->insertUser('bob'), 'other', ['small_path' => 'x']);

        $res = $this->client()->get('/files/get', ['fileId' => $fileId]);

        $this->assertSame([], $res['response']['data']);
    }

    private function uploadedFiles(): array
    {
        $dir = dirname(__DIR__, 2) . '/' . UPLOAD_DIR . '/' . date('Y/m');
        return is_dir($dir) ? array_values(array_diff(scandir($dir), ['.', '..'])) : [];
    }
}
