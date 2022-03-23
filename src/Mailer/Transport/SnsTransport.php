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

use Aws\Sns\SnsClient;
use BEdita\AWS\AwsConfigTrait;
use Cake\Mailer\AbstractTransport;
use Cake\Mailer\Email;

/**
 * Send SMS using Amazon SNS.
 *
 * @since 4.0.0
 */
class SnsTransport extends AbstractTransport
{
    use AwsConfigTrait;

    /**
     * {@inheritDoc}
     */
    protected $_defaultConfig = [
        'region' => null,
        'version' => 'latest',
    ];

    /**
     * AWS SES instance.
     *
     * @var \Aws\Sns\SnsClient
     */
    protected $client;

    /**
     * {@inheritDoc}
     */
    public function __construct($config = [])
    {
        $config = $this->reformatConfig($config);

        return parent::__construct($config);
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

        return $this->client = new SnsClient($this->getConfig());
    }

    /**
     * Send mail
     *
     * @param \Cake\Mailer\Email $email Email instance.
     * @return array
     */
    public function send(Email $email): array
    {
        $from = $email->getFrom();
        $to = $email->getTo();

        $phoneNumber = reset($to);
        $senderId = trim(reset($from));
        $message = trim($email->message(Email::MESSAGE_TEXT));
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
            'Message' => $message,
            'PhoneNumber' => $phoneNumber,
            'MessageAttributes' => $attributes,
        ]);

        return compact('message') + ['headers' => ''];
    }
}
