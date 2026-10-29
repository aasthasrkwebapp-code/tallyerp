<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use App\Support\Shortcuts;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Illuminate\View\View;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): View
    {
        return view('profile.edit', [
            'user' => $request->user(),
        ]);
    }

    /** Save the user's own Alt + key shortcuts, or go back to the defaults. */
    public function shortcuts(Request $request): RedirectResponse
    {
        $request->validate(['keys' => ['nullable', 'array'], 'keys.*' => ['nullable', 'string', 'max:5']]);
        if ($request->boolean('reset')) {
            $request->user()->forceFill(['shortcuts' => null])->save();

            return Redirect::route('profile.edit')->with('status', 'shortcuts-updated')->withFragment('shortcuts');
        }
        [$keys, $errors] = Shortcuts::parse((array) $request->input('keys', []));
        if ($errors) {
            return Redirect::route('profile.edit')->withInput()->withErrors(collect($errors)->mapWithKeys(fn ($m, $page) => ["keys.$page" => $m])->all(), 'shortcuts')->withFragment('shortcuts');
        }
        $request->user()->forceFill(['shortcuts' => $keys])->save();

        return Redirect::route('profile.edit')->with('status', 'shortcuts-updated')->withFragment('shortcuts');
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        return Redirect::route('profile.edit')->with('status', 'profile-updated');
    }

    /**
     * Delete the user's account.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validateWithBag('userDeletion', [
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        if ($user->ownedCompanies()->exists()) {
            return Redirect::route('profile.edit')->withErrors(
                ['password' => 'You still own one or more companies. Delete them or transfer ownership first.'],
                'userDeletion'
            );
        }

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }
}
