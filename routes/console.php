<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| Console Routes
|--------------------------------------------------------------------------
|
| This file is where you may define all of your Closure based console
| commands. Each Closure is bound to a command instance allowing a
| simple approach to interacting with each command's IO methods.
|
*/

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('notify:fonnte {message*}', function (array $message) {
    $token = config('services.fonnte.token');
    $target = config('services.fonnte.target');

    if (blank($token) || blank($target)) {
        $this->warn('FONNTE_TOKEN dan FONNTE_TARGET belum diisi di .env.');

        return self::FAILURE;
    }

    $response = Http::withHeaders([
        'Authorization' => $token,
    ])->asForm()->post('https://api.fonnte.com/send', [
        'target' => $target,
        'message' => implode(' ', $message),
        'countryCode' => config('services.fonnte.country_code', '62'),
    ]);

    if ($response->failed()) {
        $this->error('Gagal mengirim notifikasi Fonnte: '.$response->body());

        return self::FAILURE;
    }

    $this->info($response->body());

    return self::SUCCESS;
})->purpose('Send a WhatsApp notification through Fonnte');
