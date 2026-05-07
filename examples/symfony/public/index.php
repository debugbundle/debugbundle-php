<?php

declare(strict_types=1);

use DebugBundle\Examples\Symfony\ExampleApplication;
use Symfony\Component\HttpFoundation\Request;

require dirname(__DIR__, 3) . '/vendor/autoload.php';
require dirname(__DIR__) . '/ExampleApplication.php';

$app = new ExampleApplication([
    'projectToken' => (string) ($_ENV['DEBUGBUNDLE_TOKEN'] ?? getenv('DEBUGBUNDLE_TOKEN') ?: ''),
    'service' => (string) ($_ENV['DEBUGBUNDLE_SERVICE'] ?? getenv('DEBUGBUNDLE_SERVICE') ?: 'symfony-example'),
    'environment' => (string) ($_ENV['DEBUGBUNDLE_ENVIRONMENT'] ?? getenv('DEBUGBUNDLE_ENVIRONMENT') ?: 'development'),
    'endpoint' => (string) ($_ENV['DEBUGBUNDLE_ENDPOINT'] ?? getenv('DEBUGBUNDLE_ENDPOINT') ?: 'https://api.debugbundle.com/v1/events'),
]);

$app->handle(Request::createFromGlobals())->send();