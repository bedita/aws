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

namespace BEdita\AWS\Test\TestCase\Mailer\Transport;

use Aws\Command;
use Aws\Result;
use Aws\Ses\SesClient;
use BEdita\AWS\Mailer\Transport\SesTransport;
use Cake\I18n\FrozenTime;
use Cake\Mailer\Email;
use Cake\Utility\Text;
use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\TestCase;

/**
 * Test {@see \BEdita\AWS\Mailer\Transport\SesTransport}.
 *
 * @coversDefaultClass \BEdita\AWS\Mailer\Transport\SesTransport
 */
class SesTransportTest extends TestCase
{
    /**
     * Test {@see SesTransport::__construct()} and {@see SesTransport::getClient()} methods.
     *
     * @return void
     *
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
        $sesTransport = new class($config) extends SesTransport {
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
        static::assertAttributeSame($expected, '_config', $sesTransport);

        $client = $sesTransport->getClient();
        static::assertSame('eu-south-1', $client->getRegion());
        /** @var \Aws\Credentials\Credentials $credentials */
        $credentials = $client->getCredentials()->wait();
        static::assertSame('AKIAEXAMPLE', $credentials->getAccessKeyId());
        static::assertSame('example', $credentials->getSecretKey());
        static::assertNull($credentials->getSecurityToken());

        $anotherClient = $sesTransport->getClient();
        static::assertSame($client, $anotherClient, 'SNS client is not preserved');
    }

    /**
     * Data provider for {@see SesTransportTest::testSend()} test case.
     *
     * @return array
     */
    public function sendProvider(): array
    {
        $messageId = sprintf('<%s@example.com>', Text::uuid());

        return [
            'simple' => [
                join("\r\n", [
                    'From: Gustavo <gustavo@example.com>',
                    'To: recipient@example.com',
                    sprintf('Date: %s', FrozenTime::getTestNow()->toRfc2822String()),
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
                (new Email())
                    ->setMessageId($messageId)
                    ->setSubject('Test email')
                    ->setHeaders(['Date' => FrozenTime::getTestNow()->toRfc2822String()])
                    ->setFrom(['gustavo@example.com' => 'Gustavo'])
                    ->setTo(['recipient@example.com']),
                'Hello world!',
            ],
        ];
    }

    /**
     * Test {@see SesTransport::send()} method.
     *
     * @param string $expectedHeaders Expected headers.
     * @param string $expectedMessage Expected message.
     * @param array $config Client configuration.
     * @param \Cake\Mailer\Email $email Email to send.
     * @param string $content Message contents.
     * @return void
     *
     * @dataProvider sendProvider()
     * @covers ::send()
     */
    public function testSend(string $expectedHeaders, string $expectedMessage, array $config, Email $email, string $content): void
    {
        $invocations = 0;
        $handler = function (Command $command, Request $request) use (&$invocations, $expectedHeaders, $expectedMessage): Result {
            $invocations++;
            parse_str((string)$request->getBody(), $payload);
            static::assertSame('SendRawEmail', $payload['Action']);
            $expected = $expectedHeaders . "\r\n\r\n" . $expectedMessage;
            static::assertSame($expected, base64_decode($payload['RawMessage_Data']));

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
        $actual = $email->setTransport($transport)->send($content);

        static::assertSame($expected, $actual);
        static::assertSame(1, $invocations);
    }
}
