<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\EmailUpdatesRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class EmailUpdatesController extends Controller
{
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/email-updates', [
            'productNewsOptIn' => $request->user()->product_news_opt_in,
        ]);
    }

    public function update(EmailUpdatesRequest $request): RedirectResponse
    {
        $request->user()->forceFill([
            'product_news_opt_in' => $request->boolean('product_news_opt_in'),
        ])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Email preference saved.')]);

        return to_route('email-updates.edit');
    }
}
