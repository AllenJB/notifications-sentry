<?php
declare(strict_types=1);

namespace AllenJB\Notifications\Services\Tests;

use PHPUnit\Framework\TestCase;
use Sentry\Event;

class HardMemoryLimitTest extends TestCase
{
    public function testExceeded(): void
    {
        $dumpFileName = "hardLimitExceeded.dat";
        exec("php ". __DIR__ ."/scripts/exceedMemoryLimit.php {$dumpFileName}", $output);
        $this->assertEmpty($output);

        // Retrieve dumped Sentry events
        /**
         * @var array<Event> $events
         */
        $events = [];
        $fileHandle = fopen(__DIR__ ."/scripts/data/". $dumpFileName, "rb");
        $this->assertIsResource($fileHandle);

        $currentEventData = '';
        while (false !== ($line = fgets($fileHandle))) {
            if (trim($line) === DumpToFileSentryTransport::getEndOfEventMarker()) {
                $events[] = unserialize(trim($currentEventData));
                $currentEventData = '';
                continue;
            }
            $currentEventData .= $line;
        }

        $this->assertCount(1, $events);
        $this->assertNull($events[0]->getMessage());
        $this->assertStringContainsStringIgnoringCase('allowed memory size', $events[0]->getExceptions()[0]->getValue());
    }
}
