<?php
/**
 * BEdita, API-first content management framework
 * Copyright 2017 ChannelWeb Srl, Chialab Srl
 *
 * This file is part of BEdita: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published
 * by the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * See LICENSE.LGPL or <http://gnu.org/licenses/lgpl-3.0.html> for more details.
 */

namespace BEdita\AWS\Filesystem\Adapter;

use Aws\S3\S3Client;
use BEdita\AWS\AwsConfigTrait;
use BEdita\Core\Filesystem\FilesystemAdapter;
use League\Flysystem\AwsS3v3\AwsS3Adapter;

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
    ];

    /**
     * AWS S3 client.
     *
     * @var \Aws\S3\S3Client
     */
    protected $client;

    /**
     * {@inheritDoc}
     */
    public function initialize(array $config)
    {
        $config = $this->reformatConfig($config);

        return parent::initialize($config);
    }

    /**
     * Get AWS S3 client.
     *
     * @return \Aws\S3\S3Client
     */
    protected function getClient()
    {
        if (!empty($this->client)) {
            return $this->client;
        }

        return $this->client = new S3Client($this->getConfig());
    }

    /**
     * {@inheritDoc}
     */
    protected function buildAdapter(array $config)
    {
        return new AwsS3Adapter(
            $this->getClient(),
            $this->getConfig('host'),
            $this->getConfig('path'),
            (array)$this->getConfig('options')
        );
    }

    /**
     * {@inheritDoc}
     */
    public function getPublicUrl($path)
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
