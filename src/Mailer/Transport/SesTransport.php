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

namespace BEdita\AWS\Mailer\Transport;

use Aws\Ses\SesClient;
use BEdita\AWS\AwsConfigTrait;
use Cake\Mailer\AbstractTransport;
use Cake\Mailer\Email;

/**
 * Send emails using Amazon SES.
 *
 * @since 4.0.0
 */
class SesTransport extends AbstractTransport
{
    use AwsConfigTrait;

    /**
     * End-of-line.
     *
     * @var string
     */
    protected const EOL = "\r\n";

    /**
     * @inheritDoc
     */
    protected $_defaultConfig = [
        'region' => null,
        'version' => 'latest',
    ];

    /**
     * AWS SES instance.
     *
     * @var \Aws\Ses\SesClient|null
     */
    protected $client;

    /**
     * @inheritDoc
     */
    public function __construct($config = [])
    {
        $config = $this->reformatCredentials($config);

        parent::__construct($config);
    }

    /**
     * Get SES client.
     *
     * @return \Aws\Ses\SesClient
     */
    protected function getClient(): SesClient
    {
        if (!empty($this->client)) {
            return $this->client;
        }

        return $this->client = new SesClient((array)$this->getConfig());
    }

    /**
     * @inheritDoc
     */
    public function send(Email $email): array
    {
        $headers = $email->getHeaders(['from', 'sender', 'replyTo', 'readReceipt', 'returnPath', 'to', 'cc', 'bcc', 'subject']);
        foreach ($headers as $key => $header) {
            $headers[$key] = str_replace(str_split(static::EOL), '', $header);
        }
        $headers = $this->_headersToString($headers, static::EOL);

        $message = implode(static::EOL, (array)$email->message());

        $this->getClient()->sendRawEmail([
            'RawMessage' => [
                'Data' => $headers . static::EOL . static::EOL . $message,
            ],
        ]);

        return compact('headers', 'message');
    }
}
