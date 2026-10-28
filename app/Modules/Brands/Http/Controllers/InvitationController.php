<?php

declare(strict_types=1);

namespace App\Modules\Brands\Http\Controllers;

use App\Modules\Auth\Models\User;
use App\Modules\Brands\Actions\AddMemberToBrand;
use App\Modules\Brands\Http\Requests\AcceptInvitationRequest;
use App\Modules\Brands\Models\Brand;
use App\Modules\Brands\Models\Invitation;
use App\Modules\Brands\Models\Membership;
use App\Modules\Core\Enums\MembershipStatus;
use App\Modules\Core\Support\Audit;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Public invite links: GET shows the "set your password" form, POST creates
 * the account, adds the brand and department membership, signs the person
 * in and lands them in their brand.
 *
 * For an email that already has an account the link is not a password: the
 * person accepts while signed in as that account (the sign-in asks for the
 * password and the authenticator code as always), and comes back here after
 * it. A suspended account, a paused membership or an Admin is not changed by
 * a link, and a Manager's invitation does not give somebody a second brand
 * (that is an Admin's decision, see BrandSharing).
 */
final class InvitationController
{
    public function show(Request $request, string $token): View
    {
        $invitation = $this->usable($token);

        if (! $invitation instanceof Invitation) {
            return $invitation;
        }

        $existingUser = User::query()->where('email', $invitation->email)->first();
        $refusal = $this->refusal($invitation, $existingUser);

        if ($refusal !== null) {
            return view('brands::invitations.unavailable', ['invitation' => $invitation, 'reason' => $refusal]);
        }

        // Back here after signing in as that account.
        if ($existingUser !== null && ! $existingUser->is($request->user())) {
            $request->session()->put('url.intended', $invitation->acceptUrl());
        }

        return view('brands::invitations.show', [
            'invitation' => $invitation,
            'existingUser' => $existingUser,
            'signedInAsOther' => $request->user() !== null && ! $request->user()->is($existingUser),
            'mustSignIn' => $existingUser !== null && ! $existingUser->is($request->user()),
        ]);
    }

    public function accept(AcceptInvitationRequest $request, string $token, AddMemberToBrand $addMember): RedirectResponse|View
    {
        $invitation = $this->usable($token);

        if (! $invitation instanceof Invitation) {
            return $invitation;
        }

        /** @var Brand $brand */
        $brand = $invitation->brand;
        $existing = User::query()->where('email', $invitation->email)->first();
        $refusal = $this->refusal($invitation, $existing);

        if ($refusal !== null) {
            return view('brands::invitations.unavailable', ['invitation' => $invitation, 'reason' => $refusal]);
        }

        if ($existing !== null && ! $existing->is($request->user())) {
            $request->session()->put('url.intended', $invitation->acceptUrl());

            return redirect()->route('login')->with('status', 'Sign in as '.$invitation->email.' to join '.$brand->name.'.');
        }

        $user = DB::transaction(function () use ($request, $invitation, $existing, $brand, $addMember): User {
            $user = $existing ?? User::query()->create([
                'name' => $request->validated('name'),
                'email' => $invitation->email,
                'password' => $request->validated('password'),
                'created_by' => $invitation->invited_by,
            ]);

            if ($existing === null) {
                $user->forceFill(['email_verified_at' => now()])->save();
            }

            // Somebody already in the brand keeps the role and department they have: a link does not change them.
            if ($this->membership($user, $brand) === null) {
                $addMember->handle($brand, $user, $invitation->role, $invitation->department, $invitation->department_role, $invitation->inviter);
            }

            $invitation->forceFill(['accepted_at' => now()])->save();

            Audit::log(
                'members.invite_accepted',
                $invitation,
                ['user_id' => $user->getKey(), 'email' => $user->email, 'role' => $invitation->role->value],
                "{$user->name} accepted the invitation",
                brand: $brand,
                department: $invitation->department,
                causer: $user,
                logName: 'members',
            );

            return $user;
        });

        // Only a new account is signed in here, with the password it has just chosen.
        if ($existing === null) {
            Auth::login($user);
            $request->session()->regenerate();
        }

        return redirect()->to('/b/'.$brand->slug);
    }

    /** The invitation, or the page that says why it cannot be used (accepted, expired, or its brand removed). */
    private function usable(string $token): Invitation|View
    {
        $invitation = Invitation::query()->acrossBrands()->where('token', $token)->first();
        $invitation?->load(['brand', 'department', 'inviter']);

        if ($invitation === null || ! $invitation->isUsable() || $invitation->brand === null) {
            return view('brands::invitations.unavailable', ['invitation' => $invitation, 'reason' => null]);
        }

        return $invitation;
    }

    /** Why this invitation may not change this account, or null. */
    private function refusal(Invitation $invitation, ?User $user): ?string
    {
        if ($user === null) {
            return null;
        }

        /** @var Brand $brand */
        $brand = $invitation->brand;

        if ($user->isSuspended()) {
            return 'This account is suspended. Ask an Admin.';
        }

        if ($user->isGlobalAdmin()) {
            return 'This account is an Admin and already sees every brand. Sign in to continue.';
        }

        // IT and Website leads people never work in a brand, however the invitation reached them.
        if ($user->isIt() || $user->isWebsiteLeads()) {
            return 'This account is for IT or website leads and cannot join a brand. Ask an Admin.';
        }

        $membership = $this->membership($user, $brand);

        if ($membership !== null && $membership->status !== MembershipStatus::Active) {
            return 'Your access to '.$brand->name.' is paused. Ask an Admin or your Manager.';
        }

        $elsewhere = Membership::query()->acrossBrands()->where('user_id', $user->getKey())->where('brand_id', '!=', $brand->getKey())->exists();

        if ($membership === null && $elsewhere && ! (bool) $invitation->inviter?->isGlobalAdmin()) {
            return 'You already work in another brand. Only an Admin can give you access to '.$brand->name.' as well.';
        }

        return null;
    }

    private function membership(User $user, Brand $brand): ?Membership
    {
        return Membership::query()->acrossBrands()->where('user_id', $user->getKey())->where('brand_id', $brand->getKey())->first();
    }
}
