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

namespace BEdita\AWS\Test\TestCase\Mailer\Transport;

use Aws\Command;
use Aws\Result;
use Aws\Sns\SnsClient;
use BEdita\AWS\Mailer\Transport\SnsTransport;
use Cake\Mailer\Message;
use PHPUnit\Framework\TestCase;

/**
 * Test {@see \BEdita\AWS\Mailer\Transport\SnsTransport}.
 *
 * @coversDefaultClass \BEdita\AWS\Mailer\Transport\SnsTransport
 */
class SnsTransportTest extends TestCase
{
    /**
     * Test {@see SnsTransport} constructor and {@see SnsTransport::getClient()} methods.
     *
     * @return void
     * @covers ::__construct()
     * @covers ::getClient()
     */
    public function testConstruct(): void
    {
        $config = [
            'username' => 'AKIAEXAMPLE',
            'password' => 'example',
            'region' => 'eu-south-1',
        ];
        $snsTransport = new class ($config) extends SnsTransport {
            public function getClient(): SnsClient
            {
                return parent::getClient();
            }
        };

        $expected = [
            'region' => 'eu-south-1',
            'version' => 'latest',
            'smsType' => null,
            'username' => 'AKIAEXAMPLE',
            'password' => 'example',
            'credentials' => [
                'key' => 'AKIAEXAMPLE',
                'secret' => 'example',
            ],
        ];
        static::assertAttributeSame($expected, '_config', $snsTransport);

        $client = $snsTransport->getClient();
        static::assertSame('eu-south-1', $client->getRegion());
        /** @var \Aws\Credentials\Credentials $credentials */
        $credentials = $client->getCredentials()->wait();
        static::assertSame('AKIAEXAMPLE', $credentials->getAccessKeyId());
        static::assertSame('example', $credentials->getSecretKey());
        static::assertNull($credentials->getSecurityToken());

        $anotherClient = $snsTransport->getClient();
        static::assertSame($client, $anotherClient, 'SNS client is not preserved');
    }

    /**
     * Data provider for {@see SnsTransportTest::testSend()} test case.
     *
     * @return array
     */
    public function sendProvider(): array
    {
        return [
            'simple' => [
                ['message' => 'Hello, world!', 'headers' => ''],
                [
                    'Message' => 'Hello, world!',
                    'PhoneNumber' => '+1-202-555-0118',
                    'MessageAttributes' => [],
                ],
                [],
                (new Message())
                    ->setEmailPattern('/.*/')
                    ->setFrom(['' => '_'])
                    ->setTo(['+1-202-555-0118'])
                    ->setBodyText('Hello, world!'),
            ],
            'with sender' => [
                ['message' => 'Hello, world!', 'headers' => ''],
                [
                    'Message' => 'Hello, world!',
                    'PhoneNumber' => '+1-202-555-0118',
                    'MessageAttributes' => [
                        'AWS.SNS.SMS.SenderID' => ['DataType' => 'String', 'StringValue' => 'FooBar'],
                    ],
                ],
                [],
                (new Message())
                    ->setEmailPattern('/.*/')
                    ->setFrom(['' => 'FooBar'])
                    ->setTo(['+1-202-555-0118'])
                    ->setBodyText('Hello, world!'),
            ],
            'with SMS type' => [
                ['message' => 'Hello, world!', 'headers' => ''],
                [
                    'Message' => 'Hello, world!',
                    'PhoneNumber' => '+1-202-555-0118',
                    'MessageAttributes' => [
                        'AWS.SNS.SMS.SMSType' => ['DataType' => 'String', 'StringValue' => 'Promotional'],
                    ],
                ],
                ['smsType' => 'Promotional'],
                (new Message())
                    ->setEmailPattern('/.*/')
                    ->setFrom(['' => '_'])
                    ->setTo(['+1-202-555-0118'])
                    ->setBodyText('Hello, world!'),
            ],
            'with sender and SMS type' => [
                ['message' => 'Hello, world!', 'headers' => ''],
                [
                    'Message' => 'Hello, world!',
                    'PhoneNumber' => '+1-202-555-0118',
                    'MessageAttributes' => [
                        'AWS.SNS.SMS.SenderID' => ['DataType' => 'String', 'StringValue' => 'FooBar'],
                        'AWS.SNS.SMS.SMSType' => ['DataType' => 'String', 'StringValue' => 'Transactional'],
                    ],
                ],
                ['smsType' => 'Transactional'],
                (new Message())
                    ->setEmailPattern('/.*/')
                    ->setFrom(['' => 'FooBar'])
                    ->setTo(['+1-202-555-0118'])
                    ->setBodyText('Hello, world!'),
            ],
        ];
    }

    /**
     * Test {@see SnsTransport::send()} method.
     *
     * @param array $expected Expected result.
     * @param array $expectedPayload Expected payload for `sns:Publish` action.
     * @param array $config Client configuration.
     * @param \Cake\Mailer\Message $email Email to send.
     * @return void
     * @dataProvider sendProvider()
     * @covers ::send()
     */
    public function testSend(array $expected, array $expectedPayload, array $config, Message $email): void
    {
        $invocations = 0;
        $handler = function (Command $command) use (&$invocations, $expectedPayload): Result {
            $invocations++;
            static::assertSame('Publish', $command->getName());
            $payload = iterator_to_array($command);
            unset($payload['@context'], $payload['@http']);
            static::assertEquals($expectedPayload, $payload);

            return new Result([]);
        };

        $config += [
            'region' => 'eu-south-1',
            'credentials' => [
                'key' => 'AKIAEXAMPLE',
                'secret' => 'example',
            ],
            'handler' => $handler,
        ];
        $transport = new SnsTransport($config);

        $actual = $transport->send($email);

        static::assertSame($expected, $actual);
        static::assertSame(1, $invocations);
    }
}
