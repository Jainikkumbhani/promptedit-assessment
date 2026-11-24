<?php

use Illuminate\Support\Facades\Schedule;

// Polling fallback for late or missing webhooks.
Schedule::command('generations:sync')->everyMinute()->withoutOverlapping();
