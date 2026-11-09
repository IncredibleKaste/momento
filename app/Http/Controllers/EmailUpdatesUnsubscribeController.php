<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class EmailUpdatesUnsubscribeController extends Controller
{
    public function confirm(Request $request, int $user): Response
    {
        User::query()->findOrFail($user);

        return Inertia::render('email-updates/unsubscribe', [
            'action' => $request->fullUrl(),
        ]);
    }

    public function unsubscribe(int $user): Response
    {
        $owner = User::query()->findOrFail($user);

        $owner->forceFill(['product_news_opt_in' => false])->save();

        return Inertia::render('email-updates/unsubscribed');
    }
}
