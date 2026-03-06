# AWS integrations plugin for BEdita
![GitHub Workflow](https://github.com/bedita/aws/actions/workflows/php.yml/badge.svg)
[![Codecov coverage](https://codecov.io/gh/bedita/aws/branch/master/graph/badge.svg)](https://codecov.io/gh/bedita/aws)
[![phpstan](https://img.shields.io/badge/PHPStan-level%204-brightgreen.svg)](https://phpstan.org)
[![psalm](https://img.shields.io/badge/psalm-level%208-brightgreen.svg)](https://psalm.dev)
[![Scrutinizer Code Quality](https://scrutinizer-ci.com/g/bedita/aws/badges/quality-score.png?b=master)](https://scrutinizer-ci.com/g/bedita/aws?branch=master)
[![image](https://img.shields.io/packagist/v/bedita/aws.svg?label=stable)](https://packagist.org/packages/bedita/aws)

This plugin includes a few useful integrations for BEdita, such as:

 - S3 (storage)
 - SES (mailer)
 - SNS (mailer, for SMS)

## Installation

Run this command to add this package to your application's dependencies:

```console
$ composer require bedita/aws
```

Then, in your `Application::bootstrap()` method, add:

```php
$this->addPlugin('BEdita/AWS');
```
