# Ozh\Log

A minimalist PSR-3 compliant logger, that logs into an array.

[![Latest Version on Packagist][ico-version]][link-packagist] 
[![Software License][ico-license]](LICENSE.md)
[![Build Status](https://travis-ci.org/ozh/log.svg?branch=master)](https://travis-ci.org/ozh/log)
[![Code Coverage](https://scrutinizer-ci.com/g/ozh/log/badges/coverage.png?b=master)](https://scrutinizer-ci.com/g/ozh/log/?branch=master) 
[![Scrutinizer Code Quality](https://scrutinizer-ci.com/g/ozh/log/badges/quality-score.png)](https://scrutinizer-ci.com/g/ozh/log/?branch=master) 

## Install

Via Composer

``` bash
$ composer require ozh/log
```

## Usage

``` php
use \Ozh\Log\Logger;

require '../vendor/autoload.php';

$logger = new Logger();
$logger->debug('This is a debug message');
```

See `examples/examples.php` for more examples.

This library is fully tested on PHP 5.3 to 7.2 and HHVM.

[ico-version]: https://img.shields.io/packagist/v/ozh/log.svg
[ico-license]: https://img.shields.io/badge/license-MIT-brightgreen.svg

[link-packagist]: https://packagist.org/packages/ozh/log
[link-travis]: https://travis-ci.org/ozh/log
