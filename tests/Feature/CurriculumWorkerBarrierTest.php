<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\Feature\Support\CurriculumWorkerBarrier;

class CurriculumWorkerBarrierTest extends TestCase
{
    public function test_ready_already_consumed_from_the_pipe_releases_the_barrier(): void
    {
        $input = new InputStream();
        $process = new Process([PHP_BINARY, '-r', 'echo "REA"; flush(); usleep(10000); echo "DY\n"; flush(); fgets(STDIN);']);
        $process->setInput($input);
        $process->setTimeout(5);
        try {
            $process->start();
            // Deliberately drain READY before waiting, reproducing the CI race.
            do {
                $process->checkTimeout();
                $output = $process->getOutput();
                usleep(1000);
            } while (! str_contains($output, "READY\n"));
            CurriculumWorkerBarrier::awaitReady($process);
            $input->write("GO\n");
            $process->wait();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        } finally {
            $process->stop(0);
            $input->close();
        }
    }

    public function test_missing_readiness_still_obeys_the_worker_timeout(): void
    {
        $process = new Process([PHP_BINARY, '-r', 'sleep(10);']);
        $process->setTimeout(0.5);
        try {
            $process->start();
            $this->expectException(ProcessTimedOutException::class);
            CurriculumWorkerBarrier::awaitReady($process);
        } finally {
            $process->stop(0);
            $this->assertFalse($process->isRunning());
        }
    }
}
