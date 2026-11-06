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
            $output = $process->getOutput();
            $output = str_replace("\xEF\xBB\xBF", '', $output);
            if (preg_match('/(?:^|\r?\n)READY(?:\r?\n|$)/', $output)) {
                return;
            }
            if (! $process->isRunning()) {
                throw new RuntimeException('Curriculum worker exited before READY: '.$process->getErrorOutput().$process->getOutput());
            }
            usleep(1000);
        }
    }
}
