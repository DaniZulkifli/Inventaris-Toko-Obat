<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Providers\RouteServiceProvider;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class EmailVerificationNotificationController extends Controller
{
    /**
     * Send a new email verification notification.
     */
    public function store(Request $request): RedirectResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return redirect()->intended(RouteServiceProvider::HOME)->with('info', 'Email sudah terverifikasi.');
        }

        $request->user()->sendEmailVerificationNotification();

        return back()
            ->with('status', 'verification-link-sent')
            ->with('success', 'Link verifikasi berhasil dikirim.');
    }
}
