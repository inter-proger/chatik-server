<?php

require_once __DIR__ . '/../../core/File.php';

class FileTest extends TestCase
{
    public function testExtensionOfIsLowercased(): void
    {
        $this->assertSame('jpg', File::extensionOf('Photo.JPG'));
    }

    public function testExtensionOfTakesLastPart(): void
    {
        $this->assertSame('gz', File::extensionOf('archive.tar.gz'));
    }

    public function testExtensionOfWithoutExtensionIsFile(): void
    {
        $this->assertSame('file', File::extensionOf('README'));
        $this->assertSame('file', File::extensionOf(''));
        $this->assertSame('file', File::extensionOf('name.'));
    }

    public function testExtensionOfHandlesMultibyte(): void
    {
        $this->assertSame('пнг', File::extensionOf('картинка.ПНГ'));
    }

    public function testExtensionOfIsTruncatedToColumnLength(): void
    {
        $ext = File::extensionOf('a.' . str_repeat('я', 40));
        $this->assertSame(File::EXTENSION_MAX_LENGTH, mb_strlen($ext));
    }

    public function testFromRowImage(): void
    {
        $file = File::fromRow([
            'id' => '7', 'type' => 'image', 'path' => 'static/uploads/a.png', 'size' => '100',
            'filename' => 'a.png', 'extension' => 'png', 'data' => '{"small_path":"static/uploads/a_small.png"}',
        ]);

        $this->assertSame(['smallUrl' => 'http://files.test/static/uploads/a_small.png'], (array) $file['data']);

        unset($file['data']);
        $this->assertSame([
            'fileId'    => 7,
            'type'      => 'image',
            'url'       => 'http://files.test/static/uploads/a.png',
            'size'      => 100,
            'filename'  => 'a.png',
            'extension' => 'png',
        ], $file);
    }

    public function testFromRowImageWithoutSmallPathFallsBackToOriginal(): void
    {
        $file = File::fromRow([
            'id' => 1, 'type' => 'image', 'path' => 'static/uploads/a.png', 'size' => 1,
            'filename' => 'a.png', 'extension' => 'png', 'data' => '{}',
        ]);

        $this->assertSame('http://files.test/static/uploads/a.png', $file['data']->smallUrl);
    }

    public function testFromRowOtherTypeHidesData(): void
    {
        $file = File::fromRow([
            'id' => 1, 'type' => 'other', 'path' => 'static/uploads/a.bin', 'size' => 1,
            'filename' => 'a.bin', 'extension' => 'bin', 'data' => '{"small_path":"x","secret":"y"}',
        ]);

        // Пустой объект, а не массив — в JSON должен сериализоваться в {}
        $this->assertSame('{}', json_encode($file['data']));
    }

    public function testFromRowSurvivesInvalidJson(): void
    {
        $file = File::fromRow([
            'id' => 1, 'type' => 'image', 'path' => '/static/uploads/a.png', 'size' => 1,
            'filename' => 'a.png', 'extension' => 'png', 'data' => 'not json',
        ]);

        // Ведущий слеш в пути не даёт двойного слеша в URL
        $this->assertSame('http://files.test/static/uploads/a.png', $file['url']);
        $this->assertSame('http://files.test/static/uploads/a.png', $file['data']->smallUrl);
    }

    public function testFindByIdMissing(): void
    {
        $this->assertNull(File::findById(12345));
    }

    public function testFindById(): void
    {
        $userId = $this->insertUser('owner');
        $fileId = $this->insertFile($userId);

        $file = File::findById($fileId);

        $this->assertSame($fileId, $file['fileId']);
        $this->assertSame('http://files.test/static/uploads/x_small.png', $file['data']->smallUrl);
    }
}
