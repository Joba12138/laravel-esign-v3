<?php

$autoloader = dirname(__DIR__) . '/vendor/autoload.php';

if (!is_file($autoloader)) {
    throw new RuntimeException('Run composer install in this package before running its tests.');
}

require_once $autoloader;
