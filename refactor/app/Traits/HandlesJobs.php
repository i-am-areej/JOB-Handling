<?php
namespace App\Traits;
use Carbon\Carbon;

trait HandlesJobs
{
    /**
     * Convert job duration to hours and minutes.
     */
    public function convertToHoursMins($time, $format = '%02dh %02dmin'): string
    {
        return $time < 60 ? "{$time}min" : sprintf($format, floor($time / 60), $time % 60);
    }

    /**
     * Check if a job is valid for a translator based on time and user constraints.
     */
    public function validateJobForTranslator($job, $user): bool
    {
        // Example check: Add your conditions here.
        return $job->due > Carbon::now() && !$job->isUserBlacklisted($user->id);
    }
}
