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

namespace BEdita\AWS;

use BEdita\AWS\Filesystem\Adapter\S3Adapter;
use BEdita\AWS\Mailer\Transport\SesTransport;
use BEdita\AWS\Mailer\Transport\SnsTransport;
use BEdita\Core\Filesystem\FilesystemRegistry;
use Cake\Core\BasePlugin;
use Cake\Core\PluginApplicationInterface;
use Cake\Mailer\Mailer;

/**
 * Plugin class.
 */
class Plugin extends BasePlugin
{
    /**
     * @inheritDoc
     */
    public function bootstrap(PluginApplicationInterface $app): void
    {
        parent::bootstrap($app);

        // Register SES (email) and SNS (SMS) transports.
        Mailer::setDsnClassMap([
            'ses' => SesTransport::class,
            'sns' => SnsTransport::class,
        ]);

        // Register S3 filesystem adapter.
        FilesystemRegistry::setDsnClassMap([
            's3' => S3Adapter::class,
        ]);
    }
}
