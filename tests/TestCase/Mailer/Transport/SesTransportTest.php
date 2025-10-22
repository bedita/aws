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
use Aws\Ses\SesClient;
use BEdita\AWS\Mailer\Transport\SesTransport;
use Cake\I18n\DateTime;
use Cake\Mailer\Message;
use Cake\Utility\Text;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Test {@see \BEdita\AWS\Mailer\Transport\SesTransport}.
 */
#[CoversClass(SesTransport::class)]
#[CoversMethod(SesTransport::class, '__construct')]
#[CoversMethod(SesTransport::class, 'getClient')]
#[CoversMethod(SesTransport::class, 'send')]
class SesTransportTest extends TestCase
{
    /**
     * Test {@see SesTransport} constructor and {@see SesTransport::getClient()} methods.
     *
     * @return void
     */
    public function testConstruct(): void
    {
        $config = [
            'username' => 'AKIAEXAMPLE',
            'password' => 'example',
            'region' => 'eu-south-1',
        ];
        $sesTransport = new class ($config) extends SesTransport {
            public function getClient(): SesClient
            {
                return parent::getClient();
            }
        };

        $expected = [
            'region' => 'eu-south-1',
            'version' => 'latest',
            'username' => 'AKIAEXAMPLE',
            'password' => 'example',
            'credentials' => [
                'key' => 'AKIAEXAMPLE',
                'secret' => 'example',
            ],
        ];
        static::assertSame($expected, $sesTransport->getConfig());

        $client = $sesTransport->getClient();
        static::assertSame('eu-south-1', $client->getRegion());
        /** @var \Aws\Credentials\Credentials $credentials */
        $credentials = $client->getCredentials()->wait();
        static::assertSame('AKIAEXAMPLE', $credentials->getAccessKeyId());
        static::assertSame('example', $credentials->getSecretKey());
        static::assertNull($credentials->getSecurityToken());

        $anotherClient = $sesTransport->getClient();
        static::assertSame($client, $anotherClient, 'SES client is not preserved');
    }

    /**
     * Data provider for {@see SesTransportTest::testSend()} test case.
     *
     * @return array
     */
    public static function sendProvider(): array
    {
        $messageId = sprintf('<%s@example.com>', Text::uuid());
        $now = DateTime::now();

        return [
            'simple' => [
                join("\r\n", [
                    'From: Gustavo <gustavo@example.com>',
                    'To: recipient@example.com',
                    sprintf('Date: %s', $now->toRfc2822String()),
                    sprintf('Message-ID: %s', $messageId),
                    'Subject: Test email',
                    'MIME-Version: 1.0',
                    'Content-Type: text/plain; charset=UTF-8',
                    'Content-Transfer-Encoding: 8bit',
                ]),
                join("\r\n", [
                    'Hello world!',
                    '', '', // two trailing empty lines
                ]),
                [],
                (new Message())
                    ->setMessageId($messageId)
                    ->setSubject('Test email')
                    ->setHeaders(['Date' => $now->toRfc2822String()])
                    ->setFrom(['gustavo@example.com' => 'Gustavo'])
                    ->setTo(['recipient@example.com'])
                    ->setBodyText('Hello world!'),
            ],
        ];
    }

    /**
     * Test {@see SesTransport::send()} method.
     *
     * @param string $expectedHeaders Expected headers.
     * @param string $expectedMessage Expected message.
     * @param array $config Client configuration.
     * @param \Cake\Mailer\Message $email Email message to send.
     * @return void
     */
    #[DataProvider('sendProvider')]
    public function testSend(string $expectedHeaders, string $expectedMessage, array $config, Message $email): void
    {
        $invocations = 0;
        $handler = function (Command $command) use (&$invocations, $expectedHeaders, $expectedMessage): Result {
            $invocations++;
            static::assertSame('SendRawEmail', $command->getName());
            $expected = $expectedHeaders . "\r\n\r\n" . $expectedMessage;
            static::assertSame($expected, $command['RawMessage']['Data']);

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
        $transport = new SesTransport($config);

        $expected = ['headers' => $expectedHeaders, 'message' => $expectedMessage];
        $actual = $transport->send($email);

        static::assertSame($expected, $actual);
        static::assertSame(1, $invocations);
    }
}
