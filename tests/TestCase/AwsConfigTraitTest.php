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

namespace BEdita\AWS\Test\TestCase;

use BEdita\AWS\AwsConfigTrait;
use PHPUnit\Framework\TestCase;

/**
 * Test {@see \BEdita\AWS\AwsConfigTrait}.
 *
 * @coversDefaultClass \BEdita\AWS\AwsConfigTrait
 */
class AwsConfigTraitTest extends TestCase
{
    /**
     * Data provider for {@see AwsConfigTraitTest::testReformatConfig()} test case.
     *
     * @return array[]
     */
    public function reformatConfigProvider(): array
    {
        return [
            'no username, no password' => [
                [
                    'region' => 'eu-south-1',
                    'credentials' => [
                        'key' => null,
                        'secret' => null,
                    ],
                ],
                [
                    'region' => 'eu-south-1',
                    'credentials' => [
                        'key' => null,
                        'secret' => null,
                    ],
                ],
            ],
            'username, no password' => [
                [
                    'region' => 'eu-south-1',
                    'username' => 'AKIAEXAMPLE',
                    'credentials' => [
                        'key' => null,
                        'secret' => null,
                    ],
                ],
                [
                    'region' => 'eu-south-1',
                    'username' => 'AKIAEXAMPLE',
                    'credentials' => [
                        'key' => null,
                        'secret' => null,
                    ],
                ],
            ],
            'username, password' => [
                [
                    'region' => 'eu-south-1',
                    'username' => 'AKIAEXAMPLE',
                    'password' => 'example==',
                    'credentials' => [
                        'key' => 'AKIAEXAMPLE',
                        'secret' => 'example==',
                    ],
                ],
                [
                    'region' => 'eu-south-1',
                    'username' => 'AKIAEXAMPLE',
                    'password' => 'example==',
                    'credentials' => [
                        'key' => null,
                        'secret' => null,
                    ],
                ],
            ],
        ];
    }

    /**
     * Test {@see AwsConfigTrait::reformatConfig()} method.
     *
     * @param array $expected Expected result.
     * @param array $config Input configuration.
     * @return void
     *
     * @dataProvider reformatConfigProvider()
     * @covers ::reformatConfig()
     */
    public function testReformatConfig(array $expected, array $config): void
    {
        $subject = new class {
            use AwsConfigTrait { reformatConfig as public; }
        };

        $actual = $subject->reformatConfig($config);

        static::assertSame($expected, $actual);
    }
}
