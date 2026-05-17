<?php

declare(strict_types=1);

namespace App\Logging;

use Illuminate\Log\Logger;
use Monolog\Handler\ProcessableHandlerInterface;

/**
 * Monolog "tap" that attaches {@see SecretRedactionProcessor} to every
 * handler of the channel it is configured on.
 */
final class RedactSecrets
{
    public function __invoke(Logger $logger): void
    {
        $processor = new SecretRedactionProcessor;

        foreach ($logger->getLogger()->getHandlers() as $handler) {
            if ($handler instanceof ProcessableHandlerInterface) {
                $handler->pushProcessor($processor);
            }
        }
    }
}
