<?php

namespace Tests\Feature\Support;

use RuntimeException;
use Symfony\Component\Process\Process;

final class CurriculumWorkerBarrier
{
    public static function awaitReady(Process $process): void
    {
        while (true) {
            $process->checkTimeout();
            // Read the durable buffer, including output consumed by status checks.
            // waitUntil() only supplies new chunks and can miss an earlier READY.
            if (str_contains("\n".$process->getOutput(), "\nREADY\n")) {
                return;
            }
            if (! $process->isRunning()) {
                throw new RuntimeException('Curriculum worker exited before READY: '.$process->getErrorOutput().$process->getOutput());
            }
            usleep(1000);
        }
    }
}
