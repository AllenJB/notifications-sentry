<?php
declare(strict_types=1);

namespace AllenJB\Notifications\Services\Tests;

use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\PromiseInterface;
use Sentry\Event;
use Sentry\Response;
use Sentry\ResponseStatus;
use Sentry\Transport\TransportInterface;

/**
 * Based on Sentry's own NullTransport and the DumpToFile test service from the Notifications base package
 */
class DumpToFileSentryTransport implements TransportInterface
{
    /**
     * @var resource
     */
    protected $fileHandle;

    public function __construct(string $file)
    {
        $fileHandle = fopen($file, 'wb');
        if ($fileHandle === false) {
            throw new \UnexpectedValueException("Failed to open output file for writing");
        }
        $this->fileHandle = $fileHandle;

    }

    public function send(Event $event): PromiseInterface
    {
        fputs($this->fileHandle, serialize($event) . PHP_EOL);
        fputs($this->fileHandle, $this::getEndOfEventMarker() . PHP_EOL);
        return new FulfilledPromise(new Response(ResponseStatus::skipped(), $event));
    }


    public function close(?int $timeout = null): PromiseInterface
    {
        fclose($this->fileHandle);
        return new FulfilledPromise(true);
    }


    public static function getEndOfEventMarker(): string
    {
        return "--- END OF EVENT ---";
    }
}
