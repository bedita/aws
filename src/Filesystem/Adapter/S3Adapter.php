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

namespace BEdita\AWS\Filesystem\Adapter;

use Aws\CloudFront\CloudFrontClient;
use Aws\S3\S3Client;
use BEdita\AWS\AwsConfigTrait;
use BEdita\Core\Filesystem\FilesystemAdapter;
use League\Flysystem\AdapterInterface;

/**
 * AWS S3 adapter.
 *
 * @since 4.0.0
 */
class S3Adapter extends FilesystemAdapter
{
    use AwsConfigTrait;

    /**
     * {@inheritDoc}
     */
    protected $_defaultConfig = [
        'region' => null,
        'version' => 'latest',
        'visibility' => 'public',
        'distributionId' => null,
    ];

    /**
     * AWS S3 client.
     *
     * @var \Aws\S3\S3Client
     */
    protected $client;

    /**
     * AWS CloudFront client.
     *
     * @var \Aws\CloudFront\CloudFrontClient|null
     */
    protected $cloudFrontClient;

    /**
     * {@inheritDoc}
     */
    public function initialize(array $config): bool
    {
        $config = $this->reformatConfig($config);

        return parent::initialize($config);
    }

    /**
     * Get AWS S3 client.
     *
     * @return \Aws\S3\S3Client
     */
    protected function getClient(): S3Client
    {
        if (!empty($this->client)) {
            return $this->client;
        }

        return $this->client = new S3Client($this->getConfig());
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

        return $this->cloudFrontClient = new CloudFrontClient($this->getConfig());
    }

    /**
     * {@inheritDoc}
     */
    protected function buildAdapter(array $config): AdapterInterface
    {
        $cloudFrontClient = null;
        $path = $this->getConfig('path');
        $options = (array)$this->getConfig('options');
        $distributionId = $this->getConfig('distributionId');
        if ($distributionId !== null) {
            $cloudFrontClient = $this->getCloudFrontClient();
            $cloudFrontPathPrefix = $this->getConfig('cloudfrontPathPrefix', $path);
            $options += compact('distributionId', 'cloudFrontPathPrefix');
        }

        return new AwsS3CloudFrontAdapter(
            $this->getClient(),
            $this->getConfig('host'),
            $path,
            $options,
            true,
            $cloudFrontClient
        );
    }

    /**
     * {@inheritDoc}
     */
    public function getPublicUrl($path): string
    {
        if (!empty($this->_config['baseUrl'])) {
            return parent::getPublicUrl($path);
        }

        return $this->getClient()->getObjectUrl(
            $this->getConfig('host'),
            $this->getConfig('path') . $path
        );
    }
}
