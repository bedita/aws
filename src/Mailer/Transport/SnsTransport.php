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

namespace BEdita\AWS\Mailer\Transport;

use Aws\Sns\SnsClient;
use BEdita\AWS\AwsConfigTrait;
use Cake\Mailer\AbstractTransport;
use Cake\Mailer\Message;

/**
 * Send SMS using Amazon SNS.
 *
 * @since 4.0.0
 */
class SnsTransport extends AbstractTransport
{
    use AwsConfigTrait;

    /**
     * @inheritDoc
     */
    protected $_defaultConfig = [
        'region' => null,
        'version' => 'latest',
        'smsType' => null,
    ];

    /**
     * AWS SES instance.
     *
     * @var \Aws\Sns\SnsClient|null
     */
    protected ?SnsClient $client;

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
     * @return \Aws\Sns\SnsClient
     */
    protected function getClient(): SnsClient
    {
        if (!empty($this->client)) {
            return $this->client;
        }

        return $this->client = new SnsClient((array)$this->getConfig());
    }

    /**
     * @inheritDoc
     */
    public function send(Message $message): array
    {
        $from = $message->getFrom();
        $to = $message->getTo();

        $phoneNumber = reset($to);
        $senderId = trim(reset($from));
        $body = trim($message->getBodyText());
        $smsType = $this->getConfig('smsType');

        $attributes = [];
        if (preg_match('/^[[:alnum:]]+$/', $senderId)) {
            $attributes['AWS.SNS.SMS.SenderID'] = [
                'DataType' => 'String',
                'StringValue' => $senderId,
            ];
        }
        if (in_array($smsType, ['Transactional', 'Promotional'])) {
            $attributes['AWS.SNS.SMS.SMSType'] = [
                'DataType' => 'String',
                'StringValue' => $smsType,
            ];
        }

        $this->getClient()->publish([
            'Message' => $body,
            'PhoneNumber' => $phoneNumber,
            'MessageAttributes' => $attributes,
        ]);

        return ['headers' => '', 'message' => $body];
    }
}
