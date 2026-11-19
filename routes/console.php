<?php

use Illuminate\Support\Facades\Schedule;

// Bagian 9 PRD QA: Audit invarian malam hari terjadwal menjalankan pemeriksaan Bagian 5
Schedule::command('erp:audit-invariants')->dailyAt('23:30');
