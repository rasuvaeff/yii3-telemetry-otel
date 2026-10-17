<?php

declare(strict_types=1);

// The engine function does not exist in CLI. Defining this test double in the
// GLOBAL namespace (the file has no namespace directive) makes the
// finish-request branch of ShutdownFlushHook observable in tests. Pure no-op
// counter; loaded by ShutdownFlushHookTest.
if (!\function_exists('fastcgi_finish_request')) {
    function fastcgi_finish_request(): void
    {
        \Rasuvaeff\Yii3TelemetryOtel\Tests\ShutdownFlushHookTest::$fastcgiCalls++;
    }
}
