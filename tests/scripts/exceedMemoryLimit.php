<?php
declare(strict_types=1);

use AllenJB\Notifications\ErrorHandler;
use AllenJB\Notifications\NotificationFactory;
use AllenJB\Notifications\Notifications;
use AllenJB\Notifications\Services\Sentry;
use AllenJB\Notifications\Services\Tests\SentryTransportFactory;
use AllenJB\Notifications\TriggerError;
use Sentry\ClientBuilder;
use Sentry\Options;
use Sentry\SentrySdk;

require_once(__DIR__ . '/../../vendor/autoload.php');

$filePath = __DIR__ . '/data/' . $argv[1];

$sentryOptions = Sentry::getSentryClientDefaultOptions("https://test:test@example.com/fakeDSN", "test", "test");

$sentryClientBuilder = ClientBuilder::create($sentryOptions);
$sentryClientBuilder->setTransportFactory(new SentryTransportFactory($filePath));

// Next 2 lines effectively the same as Senyry's init() function
$sentryClient = $sentryClientBuilder->getClient();
SentrySdk::init()->bindClient($sentryClient);

$service = new Sentry($sentryOptions['dsn'], $sentryOptions['environment'], $sentryOptions['release'], [], null,
    $sentryClient);

$notifications = new Notifications([$service]);
$notificationFactory = new NotificationFactory();
ErrorHandler::setup(__DIR__ . '../', $notifications, $notificationFactory);
ErrorHandler::setupHandlers();

ini_set('log_errors', '0');
ini_set('memory_limit', '6M');

TriggerError::oom();

die();
