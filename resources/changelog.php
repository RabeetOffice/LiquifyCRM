<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| What changed in LiquifyCRM, newest first
|--------------------------------------------------------------------------
| The Updates screen (Admins only) shows this list, and the newest version
| here is the version of the code. After new code arrives on the server the
| Admins see "an update is ready" until someone clicks Update system.
|
| Every change a person can see adds a line here, in plain words:
|   new       something that was not there before
|   improved  something that works or looks better
|   fixed     something that was wrong
|   removed   something that is gone
| Raise the last number for fixes (1.3.1), the middle one for features (1.4.0).
*/

return [
    [
        'version' => '1.9.0',
        'date' => '2026-10-12',
        'title' => 'Live updates, a lead panel that opens at once, clearer brand logos',
        'changes' => [
            ['new', 'Screens update by themselves within a few seconds when something changes: a new lead, an assignment, a status, a note, a chat message. No more reloading the page.'],
            ['new', 'The bell rings at once: a notification is written the moment it happens, instead of waiting for the next minute.'],
            ['improved', 'A website lead, a Facebook lead or a Messenger message is on screen seconds after it arrives, and a Messenger reply is sent right away. They no longer wait for the scheduler.'],
            ['improved', 'The lead panel opens the instant you click a lead, and no longer flickers while it slides in.'],
            ['improved', 'A brand\'s logo is shown whole, with space around it, on a light tile in the brand\'s own colour. A brand whose main colour was read as white now uses its second colour.'],
            ['fixed', 'In dark mode the opened list of a dropdown was white on white.'],
            ['fixed', 'An Inbox with no chats yet did not notice the first chat until the page was reloaded.'],
        ],
    ],
    [
        'version' => '1.8.0',
        'date' => '2026-10-12',
        'title' => 'Privacy policy and "Delete your data" pages',
        'changes' => [
            ['new', 'A public privacy policy at /privacy: what is collected from website forms, Facebook lead forms and Messenger, what it is used for, who sees it and how long it is kept.'],
            ['new', 'A public "Delete your data" page at /data-deletion: how anyone can ask for their information to be removed, and how to disconnect Facebook.'],
            ['new', 'Both pages are linked under the sign-in card, and are the addresses the Facebook app points to.'],
        ],
    ],
    [
        'version' => '1.7.0',
        'date' => '2026-10-12',
        'title' => 'Forgot your password? Reset it with your authenticator app',
        'changes' => [
            ['new', 'On "Forgot password" there is a second way: type the 6-digit code from your authenticator app and choose a new password, without waiting for an email. It works for everyone who has set up an authenticator app.'],
            ['new', 'After such a reset you get a notification and an email, and the logs show it, so a reset that was not yours does not go unnoticed. Five wrong codes close this way for an hour.'],
        ],
    ],
    [
        'version' => '1.6.1',
        'date' => '2026-10-12',
        'title' => 'Logs are for Admins only',
        'changes' => [
            ['fixed', 'Only Admins and Super Admins see logs. Managers no longer have the Logs screen or its downloads, and "Recent activity" on the dashboard is no longer shown to Managers and Agents.'],
            ['improved', '"Update system" now also brings what each role may do in line with the new version.'],
        ],
    ],
    [
        'version' => '1.6.0',
        'date' => '2026-10-12',
        'title' => 'Every screen in the same design',
        'changes' => [
            ['improved', 'Brands: each brand is a card with its leads this month, departments, people and currency; the totals sit on top and one click shows only active or inactive brands.'],
            ['improved', 'System: version, scheduler, jobs and last backup as four facts, health checks as tiles, and the cron line with a Copy button.'],
            ['improved', 'A department opens with its people first, each with their role, and the name and colour beside them.'],
            ['improved', 'Add a lead: who gets it and what happens next sit beside the form.'],
            ['new', 'A wrong address, a closed screen or an expired page now answers in plain words with a way back, instead of a blank white page.'],
            ['fixed', 'In a department every person was shown as Manager, even Agents. Each person now shows the role they really hold.'],
            ['fixed', 'The notifications menu no longer lets the page show through, file fields look like the rest of the form, the sign-in card sits in the middle, and the invitation message has its spacing back.'],
        ],
    ],
    [
        'version' => '1.5.0',
        'date' => '2026-10-12',
        'title' => 'Clients, targets, websites and logs, redesigned',
        'changes' => [
            ['improved', 'Clients: what they are worth together on top (clients, lifetime value, average, how many bought again), and the list in four orders: highest value, latest sale, most sales, A to Z.'],
            ['improved', 'A client opens as its own page: lifetime value, every sale and who closed it, every enquiry, and phone and email with a copy button.'],
            ['improved', 'Targets: one block per department with its people under it, fields that say what they hold (currency, sales), and a reminder at the bottom while typed targets are not saved yet.'],
            ['new', 'Targets show the month at a glance: revenue so far, sales closed, how many people have a target, and the days left.'],
            ['improved', 'Websites: each website says how it is doing in a word, with its five checks as marks; the totals sit on top.'],
            ['improved', 'A website opens with health, connection, last lead and last check as four facts, and its five checks as tiles with what was found.'],
            ['improved', 'Logs read as a feed grouped by day. The kind of event is one click on top, the other filters show as chips, and a click on a line opens its details.'],
            ['improved', 'Logs: one Download button with the three periods instead of three buttons.'],
        ],
    ],
    [
        'version' => '1.4.0',
        'date' => '2026-10-12',
        'title' => 'Leads, pipeline and inbox, redesigned',
        'changes' => [
            ['improved', 'Leads list: views sit on top of the list, the filters you use show as chips you can remove one by one, and bulk actions float at the bottom while leads are ticked.'],
            ['new', 'Every lead shows its pipeline as a path: one click on a step moves the lead there. Won still asks for the sale amount.'],
            ['improved', 'A lead opens with its contact, details and actions in clear blocks, and copies phone or email in one click. On its own page it uses two columns.'],
            ['improved', 'Pipeline: columns scroll on their own, cards show the service, owner, follow-up and how long the lead has been quiet, and a click opens the lead without leaving the board.'],
            ['new', 'Pipeline: show one person\'s leads or only the unassigned ones, and see at a glance how many are unassigned, overdue or gone quiet.'],
            ['improved', 'Inbox in three panes: the chats, the conversation and the lead behind it. Messages are grouped by day, and you can show only unread chats or chats you can still answer.'],
            ['improved', 'Follow-ups read as a checklist with the lead, its owner and a clear overdue mark.'],
            ['fixed', 'Lists no longer light up every row a moment after a page opens; only a row that really arrives is lit.'],
            ['fixed', 'Charts follow the light or dark mode when you switch, without an error.'],
            ['fixed', 'The People, Members and Leads tables fit their card on a laptop screen, and column headings line up with their numbers.'],
        ],
    ],
    [
        'version' => '1.3.0',
        'date' => '2026-10-09',
        'title' => 'System updates and email providers',
        'changes' => [
            ['new', 'Updates screen for Admins: what changed in every version, and one button that applies a new version (database changes included) after new code arrives.'],
            ['new', 'A bar at the top tells Admins when an update is ready. Managers and Agents never see it.'],
            ['new', 'Email settings: choose your provider (Google, Microsoft 365, Hostinger, Titan, webmail, Zoho, Yahoo and more) and the server details fill themselves in, with what each provider asks for.'],
        ],
    ],
    [
        'version' => '1.2.0',
        'date' => '2026-10-09',
        'title' => 'A calmer, clearer LiquifyCRM',
        'changes' => [
            ['new', 'People screen in the admin area: add Admins, Managers and Agents from one form, with designation, department and monthly target.'],
            ['new', 'Round robin: a department can give each new lead to the next Agent in turn. Set it on the Distribute leads screen.'],
            ['new', 'Sales dashboard brand by brand, with a six-month revenue chart and a lead-source chart for Admins, Managers and Agents.'],
            ['new', 'Integrations in three tabs: Facebook in three guided steps, Websites of every brand, and everything Received.'],
            ['new', 'Live updates: the bell, dashboards, leads list and inbox refresh by themselves, and a new notification pops up.'],
            ['improved', 'A new look in light and dark: more room, consistent corners, soft glass in dark mode, and gentle animations.'],
            ['improved', 'Profile page with your photo, designation, role, brands and your numbers for the month.'],
            ['improved', 'Add brand: paste the website link and the name and address fill themselves in.'],
            ['improved', 'Admins now have the same control as Super Admins. A Super Admin still cannot be edited or removed by anyone else.'],
            ['fixed', 'Dark mode stayed off after moving to another page.'],
            ['fixed', 'The search box opened squeezed inside the top bar.'],
            ['removed', 'The Exchange rates screen. Each brand is shown in its own currency.'],
        ],
    ],
    [
        'version' => '1.1.0',
        'date' => '2026-10-09',
        'title' => 'Sources and targets at a glance',
        'changes' => [
            ['new', 'A website lead shows its website icon and name as the source in the lead list, the pipeline and the dashboard queue.'],
            ['new', 'Your target for the month on your Profile.'],
            ['new', 'Admins can correct or remove a recorded sale from the client page.'],
        ],
    ],
    [
        'version' => '1.0.0',
        'date' => '2026-10-08',
        'title' => 'LiquifyCRM launches',
        'changes' => [
            ['new', 'Brands, departments, Managers and Agents, each seeing only their own leads.'],
            ['new', 'Leads: list, pipeline, follow-ups, notes, import, and the New queue.'],
            ['new', 'Leads from your websites, Facebook Lead Ads and Messenger, with an inbox to reply.'],
            ['new', 'Sales, clients, monthly targets and a leaderboard.'],
            ['new', 'Logs of everything that happened, with downloads and archives.'],
        ],
    ],
];
