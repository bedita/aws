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

namespace BEdita\AWS\Test\TestCase;

use BEdita\AWS\Plugin;
use BEdita\Core\Filesystem\FilesystemRegistry;
use Cake\Http\BaseApplication;
use Cake\Http\MiddlewareQueue;
use Cake\Mailer\Mailer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;

/**
 * Test {@see \BEdita\AWS\Plugin}.
 */
#[CoversClass(Plugin::class)]
#[CoversMethod(Plugin::class, 'bootstrap')]
class PluginTest extends TestCase
{
    /**
     * Test subject.
     *
     * @var \BEdita\AWS\Plugin
     */
    protected Plugin $plugin;

    /**
     * @inheritDoc
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->plugin = new Plugin();
    }

    /**
     * Test {@see Plugin::bootstrap()} method.
     *
     * @return void
     */
    public function testBootstrap(): void
    {
        $app = new class (CONFIG) extends BaseApplication {
            public function middleware(MiddlewareQueue $middlewareQueue): MiddlewareQueue
            {
                return $middlewareQueue;
            }
        };

        $mailers = Mailer::getDsnClassMap();
        static::assertArrayNotHasKey('ses', $mailers);
        static::assertArrayNotHasKey('sns', $mailers);

        $storage = FilesystemRegistry::getDsnClassMap();
        static::assertArrayNotHasKey('s3', $storage);

        $this->plugin->bootstrap($app);

        $mailers = Mailer::getDsnClassMap();
        static::assertArrayHasKey('ses', $mailers);
        static::assertArrayHasKey('sns', $mailers);

        $storage = FilesystemRegistry::getDsnClassMap();
        static::assertArrayHasKey('s3', $storage);
    }
}
