<?php

namespace AllenJB\Notifications\Services;

use AllenJB\Notifications\LoggingServiceInterface;
use AllenJB\Notifications\Notification;
use Sentry\Client;
use Sentry\ClientInterface;
use Sentry\Event;
use Sentry\EventHint;
use Sentry\ExceptionDataBag;
use Sentry\ExceptionMechanism;
use Sentry\Integration\EnvironmentIntegration;
use Sentry\Integration\FrameContextifierIntegration;
use Sentry\Integration\RequestIntegration;
use Sentry\Integration\TransactionIntegration;
use Sentry\SentrySdk;
use Sentry\Severity;
use Sentry\State\Scope;
use Sentry\UserDataBag;

use function Sentry\captureEvent;
use function Sentry\configureScope;
use function Sentry\init;

class Sentry implements LoggingServiceInterface
{

    protected static ?self $instance = null;

    protected ClientInterface $client;

    protected ?UserDataBag $user = null;

    protected string $appEnvironment;

    protected ?string $appVersion;

    protected ?string $publicDSN = null;

    /**
     * @var array<string, string>
     */
    protected array $globalTags;


    /**
     * @param array<string, string> $globalTags
     */
    public function __construct(
        string $sentryDSN,
        string $appEnvironment,
        ?string $appVersion,
        array $globalTags,
        ?string $publicDSN = null,
        ?Client $sentryClient = null
    ) {
        $this->appEnvironment = $appEnvironment;
        $this->appVersion = $appVersion;
        $this->publicDSN = $publicDSN;

        if ($sentryClient === null) {
            init(self::getSentryClientDefaultOptions($sentryDSN, $appEnvironment, $appVersion));
            $sentryClient = SentrySdk::getCurrentHub()->getClient();
            if ($sentryClient === null) {
                throw new \UnexpectedValueException("Failed to retrieve Sentry Client via CurrentHub");
            }
        }
        $this->client = $sentryClient;

        $this->setupGlobalTags($globalTags);
    }


    protected function setupGlobalTags(array $globalTags): void
    {
        $globalTags['sapi'] = PHP_SAPI;
        foreach ($globalTags as $key => $value) {
            if ($key === "") {
                throw new \InvalidArgumentException("Tag key cannot be an empty string");
            }
            if ($value === "") {
                throw new \InvalidArgumentException("Tag value cannot be an empty string");
            }
        }
        $this->globalTags = $globalTags;
        configureScope(function (Scope $scope) use ($globalTags): void {
            $scope->setTags($globalTags);
        });
    }


    public static function getSentryClientDefaultOptions(
        string $sentryDSN,
        string $appEnvironment,
        ?string $appVersion
    ): array {
        return [
            'release' => $appVersion,
            'environment' => $appEnvironment,
            'dsn' => $sentryDSN,
            'max_value_length' => 4096,
            'send_default_pii' => true,
            'attach_stacktrace' => true,
            'default_integrations' => false,
            'integrations' => [
                // List of integrations from Sentry\Integration\IntegrationRegistry::getDefaultIntegrations()
                // Minus ModulesIntegration (because it's unnecessary spam)
                // and the error handler / shutdown handler overrides
                new RequestIntegration(),
                new TransactionIntegration(),
                new FrameContextifierIntegration(),
                new EnvironmentIntegration(),
            ],
        ];
    }


    /**
     * @param array<string, mixed>|null $user
     */
    public function setUser(array $user = null): void
    {
        if (empty($user)) {
            $this->user = null;
            return;
        }

        foreach ($user as $key => $value) {
            if (! is_scalar($value)) {
                throw new \InvalidArgumentException("User data array may only contain scalar values and may not contain arrays ({$key})");
            }
        }

        $this->user = UserDataBag::createFromArray($user);
    }


    public function send(Notification $notification): bool
    {
        $sentryEvent = Event::createEvent();

        // user is not null or empty array (we know user is either array or null)
        if ($this->user !== null) {
            $sentryEvent->setUser($this->user);
        }

        if ($notification->getTimestamp() !== null) {
            $sentryEvent->setTimestamp($notification->getTimestamp()->getTimestamp());
        }

        $level = Severity::info();
        if ($notification->getLevel() !== null) {
            $levelStr = $notification->getLevel();
            switch ($levelStr) {
                case 'debug':
                    $level = Severity::debug();
                    break;

                case 'info':
                    $level = Severity::info();
                    break;

                case 'warning':
                    $level = Severity::warning();
                    break;

                case 'error':
                    $level = Severity::error();
                    break;

                case 'fatal':
                    $level = Severity::fatal();
                    break;

                default:
                    trigger_error("Unhandled event level: " . $levelStr, E_USER_WARNING);
                    break;
            }
        }
        $sentryEvent->setLevel($level);

        if ($notification->getLogger() !== null) {
            $sentryEvent->setLogger($notification->getLogger());
        }
        $sentryEvent->setTags($this->globalTags);

        $additionalContext = $notification->getContext();
        if (count($additionalContext)) {
            foreach ($additionalContext as $section => $kvData) {
                if (! is_array($kvData)) {
                    $kvData = ["value" => $kvData];
                }
                if (count($kvData) === 0) {
                    continue;
                }
                $sentryEvent->setContext($section, $kvData);
            }
        }
        $sentryEvent->setContext('_SERVER', $_SERVER);
        if ($notification->shouldIncludeSessionData()) {
            // https://github.com/getsentry/sentry-php/issues/993
            // Sentry shows errors on the Sentry UI if you set a context value to an empty array
            if (count($_REQUEST)) {
                $sentryEvent->setContext('_REQUEST', $_REQUEST);
            }
            if (isset($_SESSION) && count($_SESSION)) {
                $sentryEvent->setContext('_SESSION', $_SESSION);
            }
        }

        $eventHint = null;
        $exception = $notification->getException();
        if ($exception !== null) {
            $eventHint = new EventHint();
            $eventHint->exception = $exception;
            $exceptions = [];

            $stackTraceBuilder = $this->client->getStacktraceBuilder();
            do {
                $exceptions[] = new ExceptionDataBag(
                    $exception,
                    $stackTraceBuilder->buildFromException($exception),
                    new ExceptionMechanism(ExceptionMechanism::TYPE_GENERIC, true)
                );
            } while ($exception = $exception->getPrevious());

            $sentryEvent->setExceptions($exceptions);
        } elseif (! $notification->shouldExcludeStackTrace()) {
            $this->client->getOptions()->setAttachStacktrace(false);
        }

        if ($notification->getMessage() !== null) {
            $sentryEvent->setMessage($notification->getMessage());
        }
        $lastEventId = captureEvent($sentryEvent, $eventHint);

        $this->client->getOptions()->setAttachStacktrace(true);

        return ($lastEventId !== null);
    }


    /**
     * @deprecated
     */
    public function getBrowseJSConfig(): \stdClass
    {
        return $this->getBrowserJSConfig();
    }


    public function getBrowserJSConfig(): \stdClass
    {
        $retval = (object) [
            "release" => $this->appVersion,
            "environment" => $this->appEnvironment,
            "initialScope" => (object) [
                "tags" => $this->globalTags,
            ],
            "server_name" => gethostname(),
        ];
        if ($this->publicDSN !== null) {
            $retval->dsn = $this->publicDSN;
        }
        if ($this->user !== null) {
            $retval->initialScope->user = (object) [
                "id" => $this->user->getId(),
                "email" => $this->user->getEmail(),
                "username" => $this->user->getUsername(),
                "ip_address" => $this->user->getIpAddress(),
            ];
        }
        return $retval;
    }

}
