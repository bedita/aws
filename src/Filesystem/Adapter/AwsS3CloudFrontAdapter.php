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
use Aws\CloudFront\Exception\CloudFrontException;
use Aws\S3\S3ClientInterface;
use League\Flysystem\AwsS3v3\AwsS3Adapter;
use League\Flysystem\Config;

/**
 * AWS S3 adapter that creates a CloudFront invalidation every time an object is updated or deleted.
 */
class AwsS3CloudFrontAdapter extends AwsS3Adapter
{
    /**
     * CloudFront Client instance.
     *
     * @var \Aws\CloudFront\CloudFrontClient|null
     */
    protected $cloudfrontClient = null;

    /**
     * @inheritdoc
     *
     * @param \Aws\CloudFront\CloudFrontClient|null $cloudfrontClient CloudFront client instance, or `null`.
     */
    public function __construct(S3ClientInterface $client, $bucket, $prefix = '', array $options = [], $streamReads = true, CloudFrontClient $cloudfrontClient = null)
    {
        parent::__construct($client, $bucket, $prefix, $options, $streamReads);

        $this->cloudfrontClient = $cloudfrontClient;
    }

    /**
     * @inheritdoc
     */
    public function copy($path, $newpath)
    {
        $existed = $this->has($newpath);
        $result = parent::copy($path, $newpath);
        if ($result !== false && $existed) {
            $this->createCloudFrontInvalidation($newpath);
        }

        return $result;
    }

    /**
     * @inheritdoc
     */
    public function delete($path)
    {
        $existed = $this->has($path);
        $result = parent::delete($path);
        if ($result !== false && $existed) {
            $this->createCloudFrontInvalidation($path);
        }

        return $result;
    }

    /**
     * @inheritdoc
     */
    public function deleteDir($dirname)
    {
        $result = parent::deleteDir($dirname);
        if ($result !== false) {
            $this->createCloudFrontInvalidation(rtrim($dirname, '/') . '/*');
        }

        return $result;
    }

    /**
     * @inheritdoc
     */
    public function upload($path, $body, Config $config)
    {
        $existed = $this->has($path);
        $result = parent::upload($path, $body, $config);
        if ($result !== false && $existed) {
            $this->createCloudFrontInvalidation($path);
        }

        return $result;
    }

    /**
     * Apply CloudFront path prefix.
     *
     * @param string $path Path to prefix.
     * @return string
     */
    protected function applyCloudFrontPathPrefix($path): string
    {
        $path = '/' . ltrim($path, '/');
        if (empty($this->options['cloudFrontPathPrefix'])) {
            return $path;
        }

        return '/' . trim($this->options['cloudFrontPathPrefix'], '/') . $path;
    }

    /**
     * Create CloudFront invalidation.
     *
     * @param string $path Path.
     * @return void
     */
    protected function createCloudFrontInvalidation($path): void
    {
        if ($this->cloudfrontClient === null || empty($this->options['distributionId'])) {
            return;
        }

        try {
            $this->cloudfrontClient->createInvalidation([
                'DistributionId' => $this->options['distributionId'],
                'InvalidationBatch' => [
                    'CallerReference' => uniqid($path),
                    'Paths' => [
                        'Items' => [$this->applyCloudFrontPathPrefix($path)],
                        'Quantity' => 1,
                    ],
                ],
            ]);
        } catch (CloudFrontException $e) {
            triggerWarning(sprintf('Unable to create CloudFront invalidation: %s', $e->getMessage()));
        }
    }
}
