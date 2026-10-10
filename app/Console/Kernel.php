<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        // Requires something on the server to actually call
        // `php artisan schedule:run` once a minute (cron on Linux, Task
        // Scheduler on Windows) — see App\Console\Commands\SendLiveClassReminders
        // for what breaks silently if that isn't set up, and how to test the
        // command directly without it.
        $schedule->command('live-classes:send-reminders')->everyFiveMinutes();
        $schedule->command('online-exams:send-result-emails')->everyFiveMinutes();
        $schedule->command('online-exams:send-start-reminders')->everyFiveMinutes();
        $schedule->command('applications:reconcile-pesapal')->everyFiveMinutes()->withoutOverlapping();
        $schedule->command('applications:retry-notifications')->everyFiveMinutes()->withoutOverlapping();

        // Close applications left unpaid after their intake's application period
        // ended. Daily shortly after midnight, and only once the intake is fully
        // past its close_date (the command compares against end-of-day, so an
        // intake closing today is not swept). Run it by hand with --dry-run first
        // whenever an intake date is changed.
        $schedule->command('admissions:expire-unpaid')->dailyAt('01:10')->withoutOverlapping();
    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
