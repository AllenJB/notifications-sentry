<?php
declare(strict_types=1);

namespace AllenJB\Notifications\Services\Tests;

use Sentry\Options;
use Sentry\Transport\TransportFactoryInterface;
use Sentry\Transport\TransportInterface;

class SentryTransportFactory implements TransportFactoryInterface
{
    protected string $file;


    public function __construct(string $file)
    {
        $this->file = $file;
    }


    public function create(Options $options): TransportInterface
    {
        return new DumpToFileSentryTransport($this->file);
    }

}
