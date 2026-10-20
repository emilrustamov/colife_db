<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('bitrix:sync-apartments')->dailyAt('23:20');
Schedule::command('bitrix:sync-landlord-contacts')->dailyAt('23:25');
Schedule::command('bitrix:sync-units')->dailyAt('23:30');
Schedule::command('bitrix:sync-apartment-ownerships')->dailyAt('23:35');
Schedule::command('bitrix:sync-unit-stays')->dailyAt('23:38');
Schedule::command('bitrix:sync-units-snapshot')->dailyAt('23:40');
Schedule::command('bitrix:sync-utilities')->dailyAt('23:45');
Schedule::command('bitrix:sync-contacts')->dailyAt('23:50');
Schedule::command('chatapp:collect')
    ->dailyAt('09:00')
    ->timezone('Europe/Moscow');

Schedule::command('chatapp:collect')
    ->dailyAt('12:00')
    ->timezone('Europe/Moscow');

Schedule::command('chatapp:collect')
    ->dailyAt('14:00')
    ->timezone('Europe/Moscow');

Schedule::command('chatapp:collect')
    ->dailyAt('18:00')
    ->timezone('Europe/Moscow');

Schedule::command('twilio:collect')
    ->dailyAt('09:00')
    ->timezone('Europe/Moscow');

Schedule::command('vdsina:collect')
    ->dailyAt('09:00')
    ->timezone('Europe/Moscow');

Schedule::command('payments:collect')
    ->dailyAt('09:00')
    ->timezone('Europe/Moscow');

Schedule::command('alma:collect')
    ->everyTwoHours()
    ->timezone('Europe/Moscow');

Schedule::command('bitrix:recover-stuck-pauses')->everyFiveMinutes();
