<?php

declare(strict_types=1);

namespace App\Modules\Brands\Http\Controllers;

use App\Modules\Auth\Models\User;
use App\Modules\Brands\Actions\AddMemberToBrand;
use App\Modules\Brands\Http\Requests\AcceptInvitationRequest;
use App\Modules\Brands\Models\Invitation;
use App\Modules\Core\Support\Audit;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Public invite links: GET shows the "set your password" form, POST creates
 * the account (or attaches an existing one), adds the brand and department
 * membership, signs the person in and lands them in their brand.
 */
final class InvitationController
{
    public function show(string $token): View
    {
        $invitation = $this->find($token);

        if ($invitation === null || ! $invitation->isUsable()) {
            return view('brands::invitations.unavailable', ['invitation' => $invitation]);
        }

        $invitation->load(['brand', 'department', 'inviter']);

        $existingUser = User::query()->where('email', $invitation->email)->first();

        return view('brands::invitations.show', [
            'invitation' => $invitation,
            'existingUser' => $existingUser,
        ]);
    }

    public function accept(AcceptInvitationRequest $request, string $token, AddMemberToBrand $addMember): RedirectResponse|View
    {
        $invitation = $this->find($token);

        if ($invitation === null || ! $invitation->isUsable()) {
            return view('brands::invitations.unavailable', ['invitation' => $invitation]);
        }

        $invitation->load(['brand', 'department']);

        $user = DB::transaction(function () use ($request, $invitation, $addMember): User {
            $user = User::query()->where('email', $invitation->email)->first();

            if ($user === null) {
                $user = User::query()->create([
                    'name' => $request->validated('name'),
                    'email' => $invitation->email,
                    'password' => $request->validated('password'),
                    'created_by' => $invitation->invited_by,
                ]);
                $user->forceFill(['email_verified_at' => now()])->save();
            }

            $addMember->handle(
                $invitation->brand,
                $user,
                $invitation->role,
                $invitation->department,
                $invitation->department_role,
                $invitation->inviter,
            );

            $invitation->forceFill(['accepted_at' => now()])->save();

            Audit::log(
                'members.invite_accepted',
                $invitation,
                ['user_id' => $user->getKey(), 'email' => $user->email, 'role' => $invitation->role->value],
                "{$user->name} accepted the invitation",
                brand: $invitation->brand,
                department: $invitation->department,
                causer: $user,
                logName: 'members',
            );

            return $user;
        });

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->to('/b/'.$invitation->brand->slug);
    }

    private function find(string $token): ?Invitation
    {
        return Invitation::query()->acrossBrands()->where('token', $token)->first();
    }
}
