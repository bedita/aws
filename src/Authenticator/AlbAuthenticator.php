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

namespace BEdita\AWS\Authenticator;

use ArrayObject;
use Authentication\Authenticator\Result;
use Authentication\Authenticator\ResultInterface;
use Authentication\Authenticator\TokenAuthenticator;
use Authentication\Identifier\JwtSubjectIdentifier;
use Cake\Cache\Cache;
use Cake\I18n\DateTime;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;
use JsonException;
use Lcobucci\Clock\FrozenClock;
use Lcobucci\JWT\Decoder;
use Lcobucci\JWT\Encoding\CannotDecodeContent;
use Lcobucci\JWT\Signer\Ecdsa\Sha256;
use Lcobucci\JWT\Signer\Key;
use Lcobucci\JWT\SodiumBase64Polyfill;
use Lcobucci\JWT\Token\Parser as TokenParser;
use Lcobucci\JWT\UnencryptedToken;
use Lcobucci\JWT\Validation\Constraint\LooseValidAt;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Lcobucci\JWT\Validation\Validator;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Authenticate users based on the `x-amzn-oidc-data` header set by ALB when OIDC or Cognito authentication is enabled.
 */
class AlbAuthenticator extends TokenAuthenticator
{
    /**
     * Authenticator configuration.
     *   - `header`: the header name where JWT token should be read from.
     *   - `returnPayload`: whether the result of authentication should be the JWT payload itself — if `false,
     *                      the JWT claims are used to identify the user using the configured identifier.
     *   - `fields`: map fields to JWT claims for building credentials to be passed to the identifier (only relevant
     *              when `returnPayload` is `false`).
     *   - `publicKeyEndpoint`: the endpoint where public keys are available at — by default it is in the form:
     *                          `https://public-keys.auth.elb.<region>.amazonaws.com/<key-id>`.
     *   - `region`: AWS region, only necessary when `publicKeyEndpoint` is not set.
     *   - `guzzleClient`: instance of {@see \GuzzleHttp\Client}, or array of configurations for the Guzzle client.
     *   - `cacheConfig`: name of cache configuration where to store public key data to avoid flooding the
     *                    public keys' endpoint with identical requests.
     *
     * @var array
     */
    protected array $_defaultConfig = [
        'header' => 'x-amzn-oidc-data',
        'returnPayload' => true,
        'fields' => [
            JwtSubjectIdentifier::CREDENTIAL_JWT_SUBJECT => JwtSubjectIdentifier::CREDENTIAL_JWT_SUBJECT,
        ],
        'publicKeyEndpoint' => null,
        'region' => null,
        'guzzleClient' => [
            RequestOptions::ALLOW_REDIRECTS => false,
            RequestOptions::CONNECT_TIMEOUT => 1,
            RequestOptions::TIMEOUT => 1,
        ],
        'cacheConfig' => 'default',
    ];

    /**
     * Payload data.
     *
     * @var array|null
     */
    protected ?array $payload = null;

    /**
     * Authenticates the identity based on a JWT token contained in a request.
     *
     * @link https://jwt.io/
     * @param \Psr\Http\Message\ServerRequestInterface $request The request that contains login information.
     * @return \Authentication\Authenticator\ResultInterface
     */
    public function authenticate(ServerRequestInterface $request): ResultInterface
    {
        $token = $this->getTokenFromHeader($request, $this->getConfigOrFail('header'));
        if ($token === null) {
            return new Result(null, ResultInterface::FAILURE_CREDENTIALS_MISSING);
        }

        try {
            $this->payload = $result = $this->decodeToken($token);
        } catch (Exception $e) {
            return new Result(
                null,
                ResultInterface::FAILURE_CREDENTIALS_INVALID,
                [
                    'message' => $e->getMessage(),
                    'exception' => $e,
                ]
            );
        }

        if (!is_array($result)) {
            return new Result(null, ResultInterface::FAILURE_CREDENTIALS_INVALID);
        }

        if (empty($result[JwtSubjectIdentifier::CREDENTIAL_JWT_SUBJECT])) {
            return new Result(null, ResultInterface::FAILURE_CREDENTIALS_MISSING);
        }

        if ($this->getConfig('returnPayload')) {
            $user = new ArrayObject($result);

            return new Result($user, ResultInterface::SUCCESS);
        }

        $credentials = [];
        foreach ((array)$this->getConfig('fields') as $field => $claim) {
            $credentials[$field] = $result[$claim] ?? null;
        }
        $user = $this->_identifier->identify($credentials);

        if (empty($user)) {
            return new Result(null, ResultInterface::FAILURE_IDENTITY_NOT_FOUND, $this->_identifier->getErrors());
        }

        return new Result($user, ResultInterface::SUCCESS);
    }

    /**
     * Getter for Guzzle client.
     *
     * @return \GuzzleHttp\Client
     */
    protected function getGuzzleClient(): Client
    {
        $client = $this->getConfig('guzzleClient');
        if ($client instanceof Client) {
            return $client;
        }

        return new Client((array)$client);
    }

    /**
     * Getter for key.
     *
     * @param string $keyId Key ID.
     * @return \Lcobucci\JWT\Signer\Key
     */
    protected function getKey(string $keyId): Key
    {
        $key = Cache::remember(
            sprintf('aws-alb-public-key-%s', $keyId),
            function () use ($keyId): string {
                $baseUrl = sprintf(
                    'https://public-keys.auth.elb.%s.amazonaws.com',
                    $this->getConfig('region', env('AWS_DEFAULT_REGION'))
                );
                $baseUrl = $this->getConfig('publicKeyEndpoint', $baseUrl);
                $keyUrl = sprintf('%s/%s', rtrim($baseUrl, '/'), $keyId);

                $response = $this->getGuzzleClient()->get($keyUrl);

                return (string)$response->getBody();
            },
            $this->getConfig('cacheConfig', 'default')
        );

        return Key\InMemory::plainText($key);
    }

    /**
     * Decode JWT token.
     *
     * @param string $token JWT token to decode.
     * @return array|null The JWT payload as a PHP object, null on failure.
     */
    protected function decodeToken(string $token): ?array
    {
        $jwt = (new TokenParser(new class implements Decoder {
            /** @inheritDoc */
            public function jsonDecode(string $json)
            {
                try {
                    return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
                } catch (JsonException $exception) {
                    throw CannotDecodeContent::jsonIssues($exception);
                }
            }

            /** @inheritDoc */
            public function base64UrlDecode(string $data): string
            {
                return SodiumBase64Polyfill::base642bin(
                    rtrim($data, '='),
                    SodiumBase64Polyfill::SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING,
                );
            }
        }))->parse($token);
        $kid = $jwt->headers()->get('kid');
        if (empty($kid) || !is_string($kid) || !$jwt instanceof UnencryptedToken) {
            return null;
        }

        (new Validator())->assert(
            $jwt,
            new SignedWith(Sha256::create(), $this->getKey($kid)),
            new LooseValidAt(new FrozenClock(DateTime::now())),
        );

        return $jwt->claims()->all();
    }

    /**
     * Getter for parsed payload.
     *
     * @return array|null
     */
    public function getPayload(): ?array
    {
        return $this->payload;
    }
}
