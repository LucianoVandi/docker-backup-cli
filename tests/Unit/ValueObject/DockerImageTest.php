<?php

declare(strict_types=1);

namespace DockerBackup\Tests\Unit\ValueObject;

use DockerBackup\Tests\TestCase;
use DockerBackup\ValueObject\DockerImage;

class DockerImageTest extends TestCase
{
    private string $imageId = 'sha256:1234567890abcdef1234567890abcdef1234567890abcdef1234567890abcdef';
    private array $repoTags = ['nginx:latest', 'nginx:1.21'];
    private int $imageSize = 142123456;
    private int $imageCreated = 1640995200;

    /**
     * @todo Replace docker image creation with helper method from TestCase
     */
    public function testCreateImageWithValidData(): void
    {
        $labels = ['maintainer' => 'nginx', 'version' => '1.21'];

        $image = new DockerImage(
            $this->imageId,
            $this->repoTags,
            $this->imageSize,
            $this->imageCreated,
            $labels
        );

        $this->assertEquals($this->imageId, $image->id);
        $this->assertEquals($this->repoTags, $image->repoTags);
        $this->assertEquals($this->imageSize, $image->size);
        $this->assertEquals($this->imageCreated, $image->created);
        $this->assertEquals($labels, $image->labels);
    }

    public function testCreateImageWithDefaults(): void
    {
        $image = new DockerImage($this->imageId);

        $this->assertEquals($this->imageId, $image->id);
        $this->assertEmpty($image->repoTags);
        $this->assertEquals(0, $image->size);
        $this->assertEquals(0, $image->created);
        $this->assertEmpty($image->labels);
    }

    public function testFromArrayWithCompleteData(): void
    {
        $labels = ['env' => 'production', 'app' => 'web'];

        $data = [
            'Id' => $this->imageId,
            'RepoTags' => $this->repoTags,
            'Size' => $this->imageSize,
            'Created' => $this->imageCreated,
            'Labels' => $labels
        ];

        $image = DockerImage::fromArray($data);

        $this->assertEquals($this->imageId, $image->id);
        $this->assertEquals($this->repoTags, $image->repoTags);
        $this->assertEquals($this->imageSize, $image->size);
        $this->assertEquals($this->imageCreated, $image->created);
        $this->assertEquals($labels, $image->labels);
    }

    public function testFromArrayWithMinimalData(): void
    {
        $data = ['Id' => 'abcdef123456'];

        $image = DockerImage::fromArray($data);

        $this->assertEquals('abcdef123456', $image->id);
        $this->assertEmpty($image->repoTags);
        $this->assertEquals(0, $image->size);
        $this->assertEquals(0, $image->created);
        $this->assertEmpty($image->labels);
    }

    public function testFromArrayWithAlternativeIdField(): void
    {
        $data = ['ID' => $this->imageId]; // Docker può usare 'ID' invece di 'Id'

        $image = DockerImage::fromArray($data);

        $this->assertEquals($this->imageId, $image->id);
    }

    public function testFromArrayWithStringLabels(): void
    {
        $data = [
            'Id' => $this->imageId,
            'Labels' => 'env=test,version=1.0'
        ];

        $image = DockerImage::fromArray($data);

        $this->assertEquals(['env' => 'test', 'version' => '1.0'], $image->labels);
    }

    public function testFromArrayWithNullRepoTags(): void
    {
        $data = [
            'Id' => $this->imageId,
            'RepoTags' => null
        ];

        $image = DockerImage::fromArray($data);

        $this->assertEmpty($image->repoTags);
    }

    public function testToArray(): void
    {
        $image = new DockerImage(
            'abcdef123456',
            ['app:latest'],
            1024000,
            1640995200,
            ['env' => 'test']
        );

        $array = $image->toArray();

        $expected = [
            'Id' => 'abcdef123456',
            'RepoTags' => ['app:latest'],
            'Size' => 1024000,
            'Created' => 1640995200,
            'Labels' => ['env' => 'test']
        ];

        $this->assertEquals($expected, $array);
    }

    public function testGetFirstTag(): void
    {
        $image = new DockerImage($this->imageId, $this->repoTags);

        $this->assertEquals('nginx:latest', $image->getFirstTag());
    }

    public function testGetFirstTagWithEmptyRepoTags(): void
    {
        $image = new DockerImage($this->imageId);

        $this->assertNull($image->getFirstTag());
    }

    public function testGetShortId(): void
    {
        $image = new DockerImage($this->imageId);

        $this->assertEquals('sha256:12345', $image->getShortId());
    }

    public function testGetShortIdWithShortId(): void
    {
        $image = new DockerImage('abcdef123456');

        $this->assertEquals('abcdef123456', $image->getShortId());
    }

    /**
     * @dataProvider formattedSizeProvider
     */
    public function testGetFormattedSize(int $bytes, string $expected): void
    {
        $image = new DockerImage($this->imageId, [], $bytes);

        $this->assertEquals($expected, $image->getFormattedSize());
    }

    public static function formattedSizeProvider(): array
    {
        return [
            [0, '0.00 B'],
            [512, '512.00 B'],
            [1024, '1.00 KB'],
            [1536, '1.50 KB'],
            [1048576, '1.00 MB'],
            [1073741824, '1.00 GB'],
            [142090240, '135.51 MB'],
            [5368709120, '5.00 GB'],
        ];
    }

    public function testInvalidImageIdThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Image ID cannot be empty');

        new DockerImage('');
    }

    public function testInvalidImageIdFormatThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid image ID');

        new DockerImage('invalid-image-id-with-special-chars!');
    }

    /**
     * @dataProvider validImageIdProvider
     */
    public function testValidImageIds(string $imageId): void
    {
        $image = new DockerImage($imageId);

        $this->assertEquals($imageId, $image->id);
    }

    public static function validImageIdProvider(): array
    {
        return [
            ['sha256:1234567890abcdef1234567890abcdef1234567890abcdef1234567890abcdef'],
            ['1234567890abcdef1234567890abcdef1234567890abcdef1234567890abcdef'],
            ['abcdef123456'], // short form
            ['SHA256:ABCDEF1234567890ABCDEF1234567890ABCDEF1234567890ABCDEF1234567890'], // uppercase
            ['1a2b3c4d5e6f'], // 12 chars minimum
        ];
    }

    /**
     * @dataProvider invalidImageIdProvider
     */
    public function testInvalidImageIds(string $imageId): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new DockerImage($imageId);
    }

    public static function invalidImageIdProvider(): array
    {
        return [
            ['invalid-id'],
            ['sha256:invalid'],
            ['12345'], // too short
            ['sha256:'], // empty after prefix
            ['invalid@id'],
            ['sha256:1234567890abcdef1234567890abcdef1234567890abcdef1234567890abcdefg'], // too long
            ['sha256:1234567890abcdef1234567890abcdef1234567890abcdef1234567890abcd!'], // invalid char
        ];
    }
}