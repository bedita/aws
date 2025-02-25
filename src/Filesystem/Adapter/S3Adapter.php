<?php
declare(strict_types=1);

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

namespace BEdita\AWS\Filesystem\Adapter;

use Aws\CloudFront\CloudFrontClient;
use Aws\S3\S3Client;
use BEdita\AWS\AwsConfigTrait;
use BEdita\Core\Filesystem\FilesystemAdapter;
use InvalidArgumentException;
use League\Flysystem\FilesystemAdapter as FlysystemAdapter;

/**
 * AWS S3 adapter.
 *
 * @since 4.0.0
 */
class S3Adapter extends FilesystemAdapter
{
    use AwsConfigTrait;

    /**
     * @inheritDoc
     */
    protected $_defaultConfig = [
        'region' => null,
        'bucket' => null,
        'version' => 'latest',
        'visibility' => 'public',
        'distributionId' => null,
    ];

    /**
     * AWS S3 client.
     *
     * @var \Aws\S3\S3Client|null
     */
    protected ?S3Client $client;

    /**
     * AWS CloudFront client.
     *
     * @var \Aws\CloudFront\CloudFrontClient|null
     */
    protected ?CloudFrontClient $cloudFrontClient;

    /**
     * @inheritDoc
     */
    public function initialize(array $config): bool
    {
        $config = $this->reformatConfig($config);
        if (empty($config['bucket']) || !is_string($config['bucket'])) {
            throw new InvalidArgumentException('Bucket name must be a non-empty string');
        }
        if (!empty($config['prefix']) && !is_string($config['prefix'])) {
            throw new InvalidArgumentException('Prefix must be omitted, or be a string');
        }

        return parent::initialize($config);
    }

    /**
     * Reformat configuration.
     *
     * @param array $config Configuration.
     * @return array
     */
    protected function reformatConfig(array $config): array
    {
        $config = $this->reformatCredentials($config);
        if (!empty($config['host'])) {
            $config['bucket'] = $config['bucket'] ?? $config['host'];
        }
        if (!empty($config['path'])) {
            $config['prefix'] = $config['prefix'] ?? substr($config['path'], 1);
        }

        return $config;
    }

    /**
     * Get AWS S3 client.
     *
     * @return \Aws\S3\S3Client
     */
    public function getClient(): S3Client
    {
        if (!empty($this->client)) {
            return $this->client;
        }

        return $this->client = new S3Client((array)$this->getConfig());
    }

    /**
     * Get AWS CloudFront client.
     *
     * @return \Aws\CloudFront\CloudFrontClient
     */
    protected function getCloudFrontClient(): CloudFrontClient
    {
        if (!empty($this->cloudFrontClient)) {
            return $this->cloudFrontClient;
        }

        return $this->cloudFrontClient = new CloudFrontClient((array)$this->getConfig());
    }

    /**
     * @inheritDoc
     */
    protected function buildAdapter(array $config): FlysystemAdapter
    {
        $cloudFrontClient = null;
        $prefix = $this->getConfig('prefix');
        $options = (array)$this->getConfig('options');
        $distributionId = $this->getConfig('distributionId');
        if ($distributionId !== null) {
            $cloudFrontClient = $this->getCloudFrontClient();
            $cloudFrontPathPrefix = $this->getConfig('cloudfrontPathPrefix', $prefix);
            $options += compact('distributionId', 'cloudFrontPathPrefix');
        }

        return new AwsS3CloudFrontAdapter(
            $this->getClient(),
            $this->getConfig('bucket'),
            $prefix,
            $options,
            true,
            $cloudFrontClient
        );
    }

    /**
     * @inheritDoc
     */
    public function getPublicUrl($path): string
    {
        if (!empty($this->_config['baseUrl'])) {
            return parent::getPublicUrl($path);
        }

        return $this->getClient()->getObjectUrl(
            $this->getConfig('bucket'),
            $this->getConfig('prefix') . $path
        );
    }
}
