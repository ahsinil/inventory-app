<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('inventory:expire-reservations')->hourly();
Schedule::command('idempotency:prune')->daily();
