<?php

declare(strict_types=1);

namespace DockerBackup\Tests\Unit\ValueObject;

use DockerBackup\Tests\TestCase;
use DockerBackup\ValueObject\DockerVolume;

class DockerVolumeTest extends TestCase
{
    private string $volumeName = 'test-volume';
    private string $volumeDriver = 'local';
    private string $volumeMount = '/var/lib/docker/volumes/test-volume/_data';

    /**
     * @todo Replace docker volume creation with helper method from TestCase
     */
    public function testCreateVolumeWithValidData(): void
    {
        $volume = new DockerVolume($this->volumeName, $this->volumeDriver, $this->volumeMount);

        $this->assertEquals($this->volumeName, $volume->name);
        $this->assertEquals($this->volumeDriver, $volume->driver);
        $this->assertEquals($this->volumeMount, $volume->mountpoint);
        $this->assertEmpty($volume->options);
        $this->assertEmpty($volume->labels);
    }

    public function testCreateVolumeWithDefaults(): void
    {
        $volume = new DockerVolume($this->volumeName);

        $this->assertEquals($this->volumeName, $volume->name);
        $this->assertEquals($this->volumeDriver, $volume->driver);
        $this->assertEquals('', $volume->mountpoint);
    }

    public function testFromArrayWithCompleteData(): void
    {
        $options = ['type' => 'tmpfs'];
        $labels = ['env' => 'test', 'app' => 'myapp'];

        $data = [
            'Name' => $this->volumeName,
            'Driver' => $this->volumeDriver,
            'Mountpoint' => $this->volumeMount,
            'Options' => $options,
            'Labels' => $labels
        ];

        $volume = DockerVolume::fromArray($data);

        $this->assertEquals($this->volumeName, $volume->name);
        $this->assertEquals($this->volumeDriver, $volume->driver);
        $this->assertEquals($this->volumeMount, $volume->mountpoint);
        $this->assertEquals($options, $volume->options);
        $this->assertEquals($labels, $volume->labels);
    }

    public function testFromArrayWithMinimalData(): void
    {
        $data = ['Name' => 'minimal-volume'];

        $volume = DockerVolume::fromArray($data);

        $this->assertEquals('minimal-volume', $volume->name);
        $this->assertEquals($this->volumeDriver, $volume->driver);
        $this->assertEquals('', $volume->mountpoint);
        $this->assertEmpty($volume->options);
        $this->assertEmpty($volume->labels);
    }

    public function testFromArrayWithStringLabels(): void
    {
        $data = [
            'Name' => $this->volumeName,
            'Labels' => 'env=test,app=myapp'
        ];

        $volume = DockerVolume::fromArray($data);

        $this->assertEquals(['env' => 'test', 'app' => 'myapp'], $volume->labels);
    }

    public function testToArray(): void
    {
        $volume = new DockerVolume(
            'test-volume',
            'local',
            '/path',
            ['option1' => 'value1'],
            ['label1' => 'value1']
        );

        $array = $volume->toArray();

        $expected = [
            'Name' => 'test-volume',
            'Driver' => 'local',
            'Mountpoint' => '/path',
            'Options' => ['option1' => 'value1'],
            'Labels' => ['label1' => 'value1']
        ];

        $this->assertEquals($expected, $array);
    }

    public function testInvalidVolumeNameThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Volume name cannot be empty');

        new DockerVolume('');
    }

    public function testInvalidVolumeNameFormatThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid volume name');

        new DockerVolume('invalid volume name with spaces');
    }

    /**
     * @dataProvider validVolumeNameProvider
     */
    public function testValidVolumeNames(string $volumeName): void
    {
        $volume = new DockerVolume($volumeName);

        $this->assertEquals($volumeName, $volume->name);
    }

    public static function validVolumeNameProvider(): array
    {
        return [
            ['simple-volume'],
            ['volume_with_underscores'],
            ['volume123'],
            ['my.volume.name'],
            ['very-long-volume-name-with-many-parts'],
        ];
    }

    /**
     * @dataProvider invalidVolumeNameProvider
     */
    public function testInvalidVolumeNames(string $volumeName): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new DockerVolume($volumeName);
    }

    public static function invalidVolumeNameProvider(): array
    {
        return [
            ['volume with spaces'],
            ['volume@with@special'],
            ['volume#with#hash'],
            ['volume/with/slashes'],
        ];
    }
}