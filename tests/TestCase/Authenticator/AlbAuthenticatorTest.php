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

namespace BEdita\AWS\Test\TestCase\Authenticator;

use ArrayAccess;
use ArrayObject;
use Authentication\Authenticator\ResultInterface;
use Authentication\Identifier\CallbackIdentifier;
use Authentication\Identifier\IdentifierInterface;
use BEdita\AWS\Authenticator\AlbAuthenticator;
use Cake\Http\ServerRequest;
use Cake\I18n\FrozenTime;
use Cake\Utility\Text;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Lcobucci\JWT\Encoding\ChainedFormatter;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer\Ecdsa\Sha256;
use Lcobucci\JWT\Signer\Key;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\None;
use Lcobucci\JWT\Token\Builder;
use PHPUnit\Framework\TestCase;

/**
 * Test {@see \BEdita\AWS\Authenticator\AlbAuthenticator}.
 *
 * @covers \BEdita\AWS\Authenticator\AlbAuthenticator
 */
class AlbAuthenticatorTest extends TestCase
{
    /**
     * Key ID.
     *
     * @var string
     */
    protected string $keyId;

    /**
     * Private key material.
     *
     * @var \Lcobucci\JWT\Signer\Key
     */
    protected Key $privateKey;

    /**
     * Requests history.
     *
     * @var array<int, array{request: \GuzzleHttp\Psr7\Request, response: \GuzzleHttp\Psr7\Response|null, error: \GuzzleHttp\Exception\GuzzleException|null, options: array}>
     */
    protected array $history = [];

    /**
     * Guzzle HTTP handler.
     *
     * @var HandlerStack
     */
    protected HandlerStack $handler;

    /**
     * @inheritDoc
     */
    protected function setUp(): void
    {
        parent::setUp();

        $privateKey = static::runCmd(['openssl', 'ecparam', '-name', 'prime256v1', '-genkey', '-noout']);
        assert($privateKey !== '');
        $publicKey = static::runCmd(['openssl', 'ec', '-pubout'], $privateKey);

        $this->keyId = Text::uuid();
        $this->privateKey = InMemory::plainText($privateKey);

        $this->handler = HandlerStack::create(new MockHandler([new Response(200, [], $publicKey)]));
        $this->handler->push(Middleware::history($this->history));
    }

    /**
     * Run a command.
     *
     * @param string[] $cmd Command to run.
     * @param string $stdinData Data to provide to command stdin.
     * @return string Data written to stdout.
     */
    protected static function runCmd(array $cmd, string $stdinData = ''): string
    {
        $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        assert($proc !== false);

        [$stdin, $stdout, $stderr] = $pipes;
        fwrite($stdin, $stdinData);
        fclose($stdin);

        $stdoutData = stream_get_contents($stdout);
        fclose($stdout);

        $stderrData = stream_get_contents($stderr);
        fclose($stderr);

        $exitCode = proc_close($proc);
        if ($exitCode !== 0) {
            throw new \RuntimeException(sprintf('Process [%s] exited with %d: %s', implode($cmd), $exitCode, $stderrData));
        }

        return (string)$stdoutData;
    }

    /**
     * @inheritDoc
     */
    protected function tearDown(): void
    {
        unset($this->handler, $this->privateKey, $this->keyId);
        $this->history = [];

        parent::tearDown();
    }

    /**
     * Test authentication flow.
     *
     * @return void
     */
    public function testAuthenticate(): void
    {
        $authenticator = new AlbAuthenticator(
            new CallbackIdentifier(['callback' => function (): void {
                static::fail('Unexpected call to identifier');
            }]),
            ['region' => 'eu-south-1', 'guzzleClient' => ['handler' => $this->handler]]
        );

        $token = (new Builder(new JoseEncoder(), ChainedFormatter::default()))
            ->issuedAt(FrozenTime::now())
            ->canOnlyBeUsedAfter(FrozenTime::now())
            ->expiresAt(FrozenTime::now()->addMinutes(1))
            ->withHeader('kid', $this->keyId)
            ->relatedTo('gustavo@example.com')
            ->getToken(Sha256::create(), $this->privateKey)
            ->toString();

        $result = $authenticator->authenticate(
            new ServerRequest(['environment' => ['HTTP_X_AMZN_OIDC_DATA' => $token]])
        );

        static::assertSame(ResultInterface::SUCCESS, $result->getStatus());
        static::assertTrue($result->isValid());
        static::assertEmpty($result->getErrors());
        $data = $result->getData();
        unset($data['iat'], $data['nbf'], $data['exp']);
        static::assertEquals(new ArrayObject(['sub' => 'gustavo@example.com']), $data);

        static::assertCount(1, $this->history);
        static::assertSame('GET', $this->history[0]['request']->getMethod());
        $expectedRequestUrl = sprintf('https://public-keys.auth.elb.eu-south-1.amazonaws.com/%s', $this->keyId);
        static::assertSame($expectedRequestUrl, (string)$this->history[0]['request']->getUri());

        $payload = $authenticator->getPayload();
        static::assertIsArray($payload);
        static::assertArrayHasKey('sub', $payload);
        static::assertSame('gustavo@example.com', $payload['sub']);
    }

    /**
     * Test authentication flow with missing credentials.
     *
     * @return void
     */
    public function testAuthenticateMissingCredentials(): void
    {
        $authenticator = new AlbAuthenticator(
            new CallbackIdentifier(['callback' => function (): void {
                static::fail('Unexpected call to identifier');
            }]),
            ['region' => 'eu-south-1', 'guzzleClient' => ['handler' => $this->handler]]
        );

        $result = $authenticator->authenticate(
            new ServerRequest(['environment' => []])
        );

        static::assertSame(ResultInterface::FAILURE_CREDENTIALS_MISSING, $result->getStatus());
        static::assertFalse($result->isValid());
        static::assertEmpty($result->getErrors());

        static::assertCount(0, $this->history);

        static::assertNull($authenticator->getPayload());
    }

    /**
     * Test authentication flow with a malformed token.
     *
     * @return void
     */
    public function testAuthenticateMalformedToken(): void
    {
        $authenticator = new AlbAuthenticator(
            new CallbackIdentifier(['callback' => function (): void {
                static::fail('Unexpected call to identifier');
            }]),
            ['region' => 'eu-south-1', 'guzzleClient' => ['handler' => $this->handler]]
        );

        $token = 'NOT A JWT';

        $result = $authenticator->authenticate(
            new ServerRequest(['environment' => ['HTTP_X_AMZN_OIDC_DATA' => $token]])
        );

        static::assertSame(ResultInterface::FAILURE_CREDENTIALS_INVALID, $result->getStatus());
        static::assertFalse($result->isValid());
        $errors = $result->getErrors();
        static::assertNotEmpty($errors);
        static::assertSame('The JWT string must have two dots', $errors['message']);

        static::assertCount(0, $this->history);

        static::assertNull($authenticator->getPayload());
    }

    /**
     * Test authentication flow with a token that includes malformed JSON.
     *
     * @return void
     */
    public function testAuthenticateMalformedJsonInToken(): void
    {
        $authenticator = new AlbAuthenticator(
            new CallbackIdentifier(['callback' => function (): void {
                static::fail('Unexpected call to identifier');
            }]),
            ['region' => 'eu-south-1', 'guzzleClient' => ['handler' => $this->handler]]
        );

        $encoder = new JoseEncoder();
        $token = sprintf(
            '%s.%s',
            $encoder->base64UrlEncode($encoder->jsonEncode(['typ' => 'JWT', 'alg' => 'ES256', 'kid' => $this->keyId])),
            $encoder->base64UrlEncode('NOT A JSON'),
        );
        $token .= sprintf('.%s', $encoder->base64UrlEncode(Sha256::create()->sign($token, $this->privateKey)));

        $result = $authenticator->authenticate(
            new ServerRequest(['environment' => ['HTTP_X_AMZN_OIDC_DATA' => $token]])
        );

        static::assertSame(ResultInterface::FAILURE_CREDENTIALS_INVALID, $result->getStatus());
        static::assertFalse($result->isValid());
        $errors = $result->getErrors();
        static::assertNotEmpty($errors);
        static::assertSame('Error while decoding from JSON', $errors['message']);

        static::assertCount(0, $this->history);

        static::assertNull($authenticator->getPayload());
    }

    /**
     * Test authentication flow with a token that has no `kid` header.
     *
     * @return void
     */
    public function testAuthenticateMissingKid(): void
    {
        $authenticator = new AlbAuthenticator(
            new CallbackIdentifier(['callback' => function (): void {
                static::fail('Unexpected call to identifier');
            }]),
            ['region' => 'eu-south-1', 'guzzleClient' => ['handler' => $this->handler]]
        );

        $token = (new Builder(new JoseEncoder(), ChainedFormatter::default()))
            ->issuedAt(FrozenTime::now()->subDay())
            ->canOnlyBeUsedAfter(FrozenTime::now()->subDay())
            ->expiresAt(FrozenTime::now()->subDay()->addMinutes(1))
            ->relatedTo('gustavo@example.com')
            ->getToken(Sha256::create(), $this->privateKey)
            ->toString();

        $result = $authenticator->authenticate(
            new ServerRequest(['environment' => ['HTTP_X_AMZN_OIDC_DATA' => $token]])
        );

        static::assertSame(ResultInterface::FAILURE_CREDENTIALS_INVALID, $result->getStatus());
        static::assertFalse($result->isValid());
        static::assertEmpty($result->getErrors());

        static::assertCount(0, $this->history);

        static::assertNull($authenticator->getPayload());
    }

    /**
     * Test authentication flow with a non-existing key.
     *
     * @return void
     */
    public function testAuthenticateKeyNotFound(): void
    {
        $expectedRequestUrl = sprintf('https://public-keys.auth.elb.eu-south-1.amazonaws.com/%s', $this->keyId);
        $handler = HandlerStack::create(new MockHandler([
            RequestException::create(new Request('GET', $expectedRequestUrl), new Response(404)),
        ]));
        $handler->push(Middleware::history($this->history));

        $authenticator = new AlbAuthenticator(
            new CallbackIdentifier(['callback' => function (): void {
                static::fail('Unexpected call to identifier');
            }]),
            ['region' => 'eu-south-1', 'guzzleClient' => ['handler' => $handler]]
        );

        $token = (new Builder(new JoseEncoder(), ChainedFormatter::default()))
            ->issuedAt(FrozenTime::now()->subDay())
            ->canOnlyBeUsedAfter(FrozenTime::now()->subDay())
            ->expiresAt(FrozenTime::now()->subDay()->addMinutes(1))
            ->withHeader('kid', $this->keyId)
            ->relatedTo('gustavo@example.com')
            ->getToken(Sha256::create(), $this->privateKey)
            ->toString();

        $result = $authenticator->authenticate(
            new ServerRequest(['environment' => ['HTTP_X_AMZN_OIDC_DATA' => $token]])
        );

        static::assertSame(ResultInterface::FAILURE_CREDENTIALS_INVALID, $result->getStatus());
        static::assertFalse($result->isValid());
        $errors = $result->getErrors();
        static::assertNotEmpty($errors);
        static::assertSame(
            sprintf('Client error: `GET %s` resulted in a `404 Not Found` response', $expectedRequestUrl),
            $errors['message']
        );

        static::assertCount(1, $this->history);
        static::assertSame('GET', $this->history[0]['request']->getMethod());
        static::assertSame($expectedRequestUrl, (string)$this->history[0]['request']->getUri());

        static::assertNull($authenticator->getPayload());
    }

    /**
     * Test authentication flow with an expired token.
     *
     * @return void
     */
    public function testAuthenticateExpiredToken(): void
    {
        $authenticator = new AlbAuthenticator(
            new CallbackIdentifier(['callback' => function (): void {
                static::fail('Unexpected call to identifier');
            }]),
            ['region' => 'eu-south-1', 'guzzleClient' => ['handler' => $this->handler]]
        );

        $token = (new Builder(new JoseEncoder(), ChainedFormatter::default()))
            ->issuedAt(FrozenTime::now()->subDay())
            ->canOnlyBeUsedAfter(FrozenTime::now()->subDay())
            ->expiresAt(FrozenTime::now()->subDay()->addMinutes(1))
            ->withHeader('kid', $this->keyId)
            ->relatedTo('gustavo@example.com')
            ->getToken(Sha256::create(), $this->privateKey)
            ->toString();

        $result = $authenticator->authenticate(
            new ServerRequest(['environment' => ['HTTP_X_AMZN_OIDC_DATA' => $token]])
        );

        static::assertSame(ResultInterface::FAILURE_CREDENTIALS_INVALID, $result->getStatus());
        static::assertFalse($result->isValid());
        $errors = $result->getErrors();
        static::assertNotEmpty($errors);
        static::assertSame("The token violates some mandatory constraints, details:\n- The token is expired", $errors['message']);

        static::assertCount(1, $this->history);
        static::assertSame('GET', $this->history[0]['request']->getMethod());
        $expectedRequestUrl = sprintf('https://public-keys.auth.elb.eu-south-1.amazonaws.com/%s', $this->keyId);
        static::assertSame($expectedRequestUrl, (string)$this->history[0]['request']->getUri());

        static::assertNull($authenticator->getPayload());
    }

    /**
     * Test authentication flow with an invalid signature.
     *
     * @return void
     */
    public function testAuthenticateInvalidSignature(): void
    {
        $authenticator = new AlbAuthenticator(
            new CallbackIdentifier(['callback' => function (): void {
                static::fail('Unexpected call to identifier');
            }]),
            ['region' => 'eu-south-1', 'guzzleClient' => ['handler' => $this->handler]]
        );

        // Generate another private key, which is different from the first.
        $privateKey = static::runCmd(['openssl', 'ecparam', '-name', 'prime256v1', '-genkey', '-noout']);
        assert($privateKey !== '');
        $privateKey = InMemory::plainText($privateKey);

        $token = (new Builder(new JoseEncoder(), ChainedFormatter::default()))
            ->issuedAt(FrozenTime::now())
            ->canOnlyBeUsedAfter(FrozenTime::now())
            ->expiresAt(FrozenTime::now()->addMinutes(1))
            ->withHeader('kid', $this->keyId)
            ->relatedTo('gustavo@example.com')
            ->getToken(Sha256::create(), $privateKey)
            ->toString();

        $result = $authenticator->authenticate(
            new ServerRequest(['environment' => ['HTTP_X_AMZN_OIDC_DATA' => $token]])
        );

        static::assertSame(ResultInterface::FAILURE_CREDENTIALS_INVALID, $result->getStatus());
        static::assertFalse($result->isValid());
        $errors = $result->getErrors();
        static::assertNotEmpty($errors);
        static::assertSame("The token violates some mandatory constraints, details:\n- Token signature mismatch", $errors['message']);

        static::assertCount(1, $this->history);
        static::assertSame('GET', $this->history[0]['request']->getMethod());
        $expectedRequestUrl = sprintf('https://public-keys.auth.elb.eu-south-1.amazonaws.com/%s', $this->keyId);
        static::assertSame($expectedRequestUrl, (string)$this->history[0]['request']->getUri());

        static::assertNull($authenticator->getPayload());
    }

    /**
     * Test authentication flow with an invalid algorithm.
     *
     * @return void
     */
    public function testAuthenticateInvalidAlgorithm(): void
    {
        $authenticator = new AlbAuthenticator(
            new CallbackIdentifier(['callback' => function (): void {
                static::fail('Unexpected call to identifier');
            }]),
            ['region' => 'eu-south-1', 'guzzleClient' => ['handler' => $this->handler]]
        );

        $token = (new Builder(new JoseEncoder(), ChainedFormatter::default()))
            ->issuedAt(FrozenTime::now())
            ->canOnlyBeUsedAfter(FrozenTime::now())
            ->expiresAt(FrozenTime::now()->addMinutes(1))
            ->withHeader('kid', $this->keyId)
            ->relatedTo('gustavo@example.com')
            ->getToken(new None(), InMemory::plainText('key'))
            ->toString();

        $result = $authenticator->authenticate(
            new ServerRequest(['environment' => ['HTTP_X_AMZN_OIDC_DATA' => $token]])
        );

        static::assertSame(ResultInterface::FAILURE_CREDENTIALS_INVALID, $result->getStatus());
        static::assertFalse($result->isValid());
        $errors = $result->getErrors();
        static::assertNotEmpty($errors);
        static::assertSame("The token violates some mandatory constraints, details:\n- Token signer mismatch", $errors['message']);

        static::assertCount(1, $this->history);
        static::assertSame('GET', $this->history[0]['request']->getMethod());
        $expectedRequestUrl = sprintf('https://public-keys.auth.elb.eu-south-1.amazonaws.com/%s', $this->keyId);
        static::assertSame($expectedRequestUrl, (string)$this->history[0]['request']->getUri());

        static::assertNull($authenticator->getPayload());
    }

    /**
     * Test authentication flow with a token without `sub` claim.
     *
     * @return void
     */
    public function testAuthenticateMissingSub(): void
    {
        $authenticator = new AlbAuthenticator(
            new CallbackIdentifier(['callback' => function (): void {
                static::fail('Unexpected call to identifier');
            }]),
            ['region' => 'eu-south-1', 'guzzleClient' => ['handler' => $this->handler]]
        );

        $token = (new Builder(new JoseEncoder(), ChainedFormatter::default()))
            ->issuedAt(FrozenTime::now())
            ->canOnlyBeUsedAfter(FrozenTime::now())
            ->expiresAt(FrozenTime::now()->addMinutes(1))
            ->withHeader('kid', $this->keyId)
            ->getToken(Sha256::create(), $this->privateKey)
            ->toString();

        $result = $authenticator->authenticate(
            new ServerRequest(['environment' => ['HTTP_X_AMZN_OIDC_DATA' => $token]])
        );

        static::assertSame(ResultInterface::FAILURE_CREDENTIALS_MISSING, $result->getStatus());
        static::assertFalse($result->isValid());
        static::assertEmpty($result->getErrors());

        static::assertCount(1, $this->history);
        static::assertSame('GET', $this->history[0]['request']->getMethod());
        $expectedRequestUrl = sprintf('https://public-keys.auth.elb.eu-south-1.amazonaws.com/%s', $this->keyId);
        static::assertSame($expectedRequestUrl, (string)$this->history[0]['request']->getUri());
    }

    /**
     * Test authentication flow when using an identifier.
     *
     * @return void
     */
    public function testAuthenticateIdentify(): void
    {
        $invoked = 0;
        $authenticator = new AlbAuthenticator(
            new CallbackIdentifier(['callback' => function (array $credentials) use (&$invoked): ArrayAccess {
                $invoked++;
                static::assertArrayHasKey('email', $credentials);
                static::assertSame('gustavo@example.com', $credentials['email']);

                return new ArrayObject(['id' => 42, 'username' => 'gustavo', 'email' => 'gustavo@example.com']);
            }]),
            [
                'returnPayload' => false,
                'fields' => [
                    'email' => IdentifierInterface::CREDENTIAL_JWT_SUBJECT,
                ],
                'region' => 'eu-south-1',
                'guzzleClient' => ['handler' => $this->handler],
            ]
        );

        $token = (new Builder(new JoseEncoder(), ChainedFormatter::default()))
            ->issuedAt(FrozenTime::now())
            ->canOnlyBeUsedAfter(FrozenTime::now())
            ->expiresAt(FrozenTime::now()->addMinutes(1))
            ->withHeader('kid', $this->keyId)
            ->relatedTo('gustavo@example.com')
            ->getToken(Sha256::create(), $this->privateKey)
            ->toString();

        $result = $authenticator->authenticate(
            new ServerRequest(['environment' => ['HTTP_X_AMZN_OIDC_DATA' => $token]])
        );

        static::assertSame(ResultInterface::SUCCESS, $result->getStatus());
        static::assertTrue($result->isValid());
        static::assertEmpty($result->getErrors());
        static::assertEquals(new ArrayObject(['id' => 42, 'username' => 'gustavo', 'email' => 'gustavo@example.com']), $result->getData());
        static::assertSame(1, $invoked, 'Expected identifier to be invoked exactly once');

        static::assertCount(1, $this->history);
        static::assertSame('GET', $this->history[0]['request']->getMethod());
        $expectedRequestUrl = sprintf('https://public-keys.auth.elb.eu-south-1.amazonaws.com/%s', $this->keyId);
        static::assertSame($expectedRequestUrl, (string)$this->history[0]['request']->getUri());

        $payload = $authenticator->getPayload();
        static::assertIsArray($payload);
        static::assertArrayHasKey('sub', $payload);
        static::assertSame('gustavo@example.com', $payload['sub']);
    }

    /**
     * Test authentication flow when using an identifier and user could not be found.
     *
     * @return void
     */
    public function testAuthenticateIdentifyFailure(): void
    {
        $invoked = 0;
        $authenticator = new AlbAuthenticator(
            new CallbackIdentifier(['callback' => function (array $credentials) use (&$invoked): ?ArrayAccess {
                $invoked++;
                static::assertArrayHasKey('email', $credentials);
                static::assertSame('gustavo@example.com', $credentials['email']);

                return null;
            }]),
            [
                'returnPayload' => false,
                'fields' => [
                    'email' => IdentifierInterface::CREDENTIAL_JWT_SUBJECT,
                ],
                'region' => 'eu-south-1',
                'guzzleClient' => ['handler' => $this->handler],
            ]
        );

        $token = (new Builder(new JoseEncoder(), ChainedFormatter::default()))
            ->issuedAt(FrozenTime::now())
            ->canOnlyBeUsedAfter(FrozenTime::now())
            ->expiresAt(FrozenTime::now()->addMinutes(1))
            ->withHeader('kid', $this->keyId)
            ->relatedTo('gustavo@example.com')
            ->getToken(Sha256::create(), $this->privateKey)
            ->toString();

        $result = $authenticator->authenticate(
            new ServerRequest(['environment' => ['HTTP_X_AMZN_OIDC_DATA' => $token]])
        );

        static::assertSame(ResultInterface::FAILURE_IDENTITY_NOT_FOUND, $result->getStatus());
        static::assertFalse($result->isValid());
        static::assertEmpty($result->getErrors());
        static::assertSame(1, $invoked, 'Expected identifier to be invoked exactly once');

        static::assertCount(1, $this->history);
        static::assertSame('GET', $this->history[0]['request']->getMethod());
        $expectedRequestUrl = sprintf('https://public-keys.auth.elb.eu-south-1.amazonaws.com/%s', $this->keyId);
        static::assertSame($expectedRequestUrl, (string)$this->history[0]['request']->getUri());

        $payload = $authenticator->getPayload();
        static::assertIsArray($payload);
        static::assertArrayHasKey('sub', $payload);
        static::assertSame('gustavo@example.com', $payload['sub']);
    }
}
