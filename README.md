# AWS integrations plugin for BEdita 4
![GitHub Workflow](https://github.com/bedita/aws/actions/workflows/test.yml/badge.svg)
[![Codecov coverage](https://codecov.io/gh/bedita/aws/branch/master/graph/badge.svg)](https://codecov.io/gh/bedita/aws)

This plugin includes a few useful integrations for BEdita 4, such as:

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
