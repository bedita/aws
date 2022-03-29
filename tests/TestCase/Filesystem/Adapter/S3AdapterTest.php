<?php
/**
 * BEdita, API-first content management framework
 * Copyright 2022 Atlas Srl, Chialab Srl
 *
 * This file is part of BEdita: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published
 * by the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * See LICENSE.LGPL or <http://gnu.org/licenses/lgpl-3.0.html> for more details.
 */

namespace BEdita\AWS\Test\TestCase\Filesystem\Adapter;

use Aws\CloudFront\CloudFrontClient;
use Aws\S3\S3Client;
use BEdita\AWS\Filesystem\Adapter\AwsS3CloudFrontAdapter;
use BEdita\AWS\Filesystem\Adapter\S3Adapter;
use Exception;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Test {@see \BEdita\AWS\Filesystem\Adapter\S3Adapter}
 *
 * @coversDefaultClass \BEdita\AWS\Filesystem\Adapter\S3Adapter
 */
class S3AdapterTest extends TestCase
{
    /**
     * Data provider for {@see S3AdapterTest::testInitialize()} test case.
     *
     * @return array
     */
    public function initializeProvider(): array
    {
        return [
            'empty bucket' => [
                new InvalidArgumentException('Bucket name must be a non-empty string'),
                [
                    'region' => 'eu-south-1',
                    'credentials' => [
                        'key' => 'AKIAEXAMPLE',
                        'secret' => 'example',
                    ],
                ],
            ],
            'bucket not a string' => [
                new InvalidArgumentException('Bucket name must be a non-empty string'),
                [
                    'region' => 'eu-south-1',
                    'bucket' => ['not', 'a', 'string'],
                    'credentials' => [
                        'key' => 'AKIAEXAMPLE',
                        'secret' => 'example',
                    ],
                ],
            ],
            'host' => [
                [
                    'region' => 'eu-south-1',
                    'bucket' => 'example-bucket',
                    'version' => 'latest',
                    'visibility' => 'public',
                    'distributionId' => null,
                    'host' => 'example-bucket',
                    'credentials' => [
                        'key' => 'AKIAEXAMPLE',
                        'secret' => 'example',
                    ],
                ],
                [
                    'region' => 'eu-south-1',
                    'host' => 'example-bucket',
                    'credentials' => [
                        'key' => 'AKIAEXAMPLE',
                        'secret' => 'example',
                    ],
                ],
            ],
            'host, bucket preserved' => [
                [
                    'region' => 'eu-south-1',
                    'bucket' => 'example-bucket',
                    'version' => 'latest',
                    'visibility' => 'public',
                    'distributionId' => null,
                    'host' => 'another-bucket',
                    'credentials' => [
                        'key' => 'AKIAEXAMPLE',
                        'secret' => 'example',
                    ],
                ],
                [
                    'region' => 'eu-south-1',
                    'bucket' => 'example-bucket',
                    'host' => 'another-bucket',
                    'credentials' => [
                        'key' => 'AKIAEXAMPLE',
                        'secret' => 'example',
                    ],
                ],
            ],
            'prefix not a string' => [
                new InvalidArgumentException('Prefix must be omitted, or be a string'),
                [
                    'region' => 'eu-south-1',
                    'bucket' => 'example-bucket',
                    'prefix' => ['not', 'a', 'string'],
                    'credentials' => [
                        'key' => 'AKIAEXAMPLE',
                        'secret' => 'example',
                    ],
                ],
            ],
            'path' => [
                [
                    'region' => 'eu-south-1',
                    'bucket' => 'example-bucket',
                    'version' => 'latest',
                    'visibility' => 'public',
                    'distributionId' => null,
                    'path' => '/foo/',
                    'credentials' => [
                        'key' => 'AKIAEXAMPLE',
                        'secret' => 'example',
                    ],
                    'prefix' => 'foo/',
                ],
                [
                    'region' => 'eu-south-1',
                    'bucket' => 'example-bucket',
                    'path' => '/foo/',
                    'credentials' => [
                        'key' => 'AKIAEXAMPLE',
                        'secret' => 'example',
                    ],
                ],
            ],
            'path, prefix preserved' => [
                [
                    'region' => 'eu-south-1',
                    'bucket' => 'example-bucket',
                    'version' => 'latest',
                    'visibility' => 'public',
                    'distributionId' => null,
                    'prefix' => 'foo/',
                    'path' => '/bar/',
                    'credentials' => [
                        'key' => 'AKIAEXAMPLE',
                        'secret' => 'example',
                    ],
                ],
                [
                    'region' => 'eu-south-1',
                    'bucket' => 'example-bucket',
                    'prefix' => 'foo/',
                    'path' => '/bar/',
                    'credentials' => [
                        'key' => 'AKIAEXAMPLE',
                        'secret' => 'example',
                    ],
                ],
            ],
        ];
    }

    /**
     * Test {@see S3Adapter::initialize()} and {@see S3Adapter::reformatConfig()} methods.
     *
     * @param array|\Exception $expected Expected outcome.
     * @param array $config Adapter configuration.
     * @return void
     *
     * @dataProvider initializeProvider()
     * @covers ::initialize()
     * @covers ::reformatConfig()
     */
    public function testInitialize($expected, array $config): void
    {
        if ($expected instanceof Exception) {
            $this->expectExceptionObject($expected);
        }

        $s3Adapter = new S3Adapter();
        $initialized = $s3Adapter->initialize($config);
        static::assertTrue($initialized);

        $actual = $s3Adapter->getConfig();
        static::assertSame($expected, $actual);
    }

    /**
     * Test {@see S3Adapter::getClient()} and {@see S3Adapter::getCloudFrontClient()} methods.
     *
     * @return void
     *
     * @covers ::getClient()
     * @covers ::getCloudFrontClient()
     */
    public function testGetClient(): void
    {
        $s3Adapter = new class extends S3Adapter {
            public function getClient(): S3Client
            {
                return parent::getClient();
            }

            public function getCloudFrontClient(): CloudFrontClient
            {
                return parent::getCloudFrontClient();
            }
        };

        $config = [
            'username' => 'AKIAEXAMPLE',
            'password' => 'example',
            'region' => 'eu-south-1',
            'bucket' => 'example-bucket',
        ];
        $s3Adapter->initialize($config);

        $client = $s3Adapter->getClient();
        static::assertSame('eu-south-1', $client->getRegion());
        /** @var \Aws\Credentials\Credentials $credentials */
        $credentials = $client->getCredentials()->wait();
        static::assertSame('AKIAEXAMPLE', $credentials->getAccessKeyId());
        static::assertSame('example', $credentials->getSecretKey());
        static::assertNull($credentials->getSecurityToken());

        $anotherClient = $s3Adapter->getClient();
        static::assertSame($client, $anotherClient, 'S3 client is not preserved');

        $client = $s3Adapter->getCloudFrontClient();
        static::assertSame('eu-south-1', $client->getRegion());
        /** @var \Aws\Credentials\Credentials $credentials */
        $credentials = $client->getCredentials()->wait();
        static::assertSame('AKIAEXAMPLE', $credentials->getAccessKeyId());
        static::assertSame('example', $credentials->getSecretKey());
        static::assertNull($credentials->getSecurityToken());

        $anotherClient = $s3Adapter->getCloudFrontClient();
        static::assertSame($client, $anotherClient, 'CloudFront client is not preserved');
    }

    /**
     * Test {@see S3Adapter::buildAdapter()} method.
     *
     * @return void
     *
     * @covers ::buildAdapter()
     */
    public function testBuildAdapter(): void
    {
        $config = [
            'bucket' => 'example-bucket',
            'prefix' => '/foo/',
            'region' => 'eu-south-1',
            'credentials' => [
                'key' => 'AKIAEXAMPLE',
                'secret' => 'example',
            ],
            'distributionId' => null,
        ];
        $adapter = new S3Adapter();
        $adapter->initialize($config);

        /** @var \BEdita\AWS\Filesystem\Adapter\AwsS3CloudFrontAdapter $inner */
        $inner = $adapter->getInnerAdapter();
        static::assertInstanceOf(AwsS3CloudFrontAdapter::class, $inner);
        static::assertSame('example-bucket', $inner->getBucket());
        static::assertSame('foo/', $inner->getPathPrefix());
        static::assertNull($inner->getDistributionId());
    }

    /**
     * Test {@see S3Adapter::buildAdapter()} method with a CloudFront distribution.
     *
     * @return void
     *
     * @covers ::buildAdapter()
     */
    public function testBuildAdapterCloudFront(): void
    {
        $config = [
            'bucket' => 'example-bucket',
            'prefix' => '/foo/',
            'region' => 'eu-south-1',
            'credentials' => [
                'key' => 'AKIAEXAMPLE',
                'secret' => 'example',
            ],
            'distributionId' => 'E2EXAMPLE',
        ];
        $adapter = new S3Adapter();
        $adapter->initialize($config);

        /** @var \BEdita\AWS\Filesystem\Adapter\AwsS3CloudFrontAdapter $inner */
        $inner = $adapter->getInnerAdapter();
        static::assertInstanceOf(AwsS3CloudFrontAdapter::class, $inner);
        static::assertSame('example-bucket', $inner->getBucket());
        static::assertSame('foo/', $inner->getPathPrefix());
        static::assertSame('E2EXAMPLE', $inner->getDistributionId());
    }

    /**
     * Test {@see S3Adapter::getPublicUrl()} method.
     *
     * @return void
     *
     * @covers ::getPublicUrl()
     */
    public function testGetPublicUrl(): void
    {
        $config = [
            'bucket' => 'example-bucket',
            'prefix' => '/foo/',
            'region' => 'eu-south-1',
            'credentials' => [
                'key' => 'AKIAEXAMPLE',
                'secret' => 'example',
            ],
            'baseUrl' => 'https://cdn.example.com/my-foo/',
        ];
        $adapter = new S3Adapter();
        $adapter->initialize($config);

        $expected = 'https://cdn.example.com/my-foo/my/image.png';
        $actual = $adapter->getPublicUrl('my/image.png');

        static::assertSame($expected, $actual);
    }

    /**
     * Test {@see S3Adapter::getPublicUrl()} method falling back to default AWS S3 URL..
     *
     * @return void
     *
     * @covers ::getPublicUrl()
     */
    public function testGetPublicUrlDefaultS3Url(): void
    {
        $config = [
            'bucket' => 'example-bucket',
            'prefix' => 'foo/',
            'region' => 'eu-south-1',
            'credentials' => [
                'key' => 'AKIAEXAMPLE',
                'secret' => 'example',
            ],
            'baseUrl' => null,
        ];
        $adapter = new S3Adapter();
        $adapter->initialize($config);

        $expected = 'https://example-bucket.s3.eu-south-1.amazonaws.com/foo/my/image.png';
        $actual = $adapter->getPublicUrl('my/image.png');

        static::assertSame($expected, $actual);
    }
}
