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
        'version' => '1.15.2',
        'date' => '2026-10-14',
        'title' => 'No false alarm about caches on the System screen',
        'changes' => [
            ['fixed', 'The System screen no longer shows "Optimized App: Failed, Configs are not cached". The settings and routes are left uncached on purpose: the site updates itself from GitHub, and a cached copy would keep using the old ones after an update.'],
            ['fixed', '"Backup and run database update" on the System screen now only clears the caches, like Update system. Before, it built them again, which could have kept old settings and pages after the next update.'],
        ],
    ],
    [
        'version' => '1.15.1',
        'date' => '2026-10-14',
        'title' => 'Instagram: the ad of a returning person, a count while you type',
        'changes' => [
            ['improved', 'When somebody who already has an Instagram chat taps one of your ads again, the ad shows on their lead (if it had none yet) and on its timeline ("Tapped the ad ... on Instagram").'],
            ['improved', 'While you write an Instagram reply, a count shows how much of Instagram\'s 1,000 characters is used (an emoji counts as four). Over the limit it turns red and Send waits until the message is shorter.'],
        ],
    ],
    [
        'version' => '1.15.0',
        'date' => '2026-10-14',
        'title' => 'Instagram direct messages in the Inbox',
        'changes' => [
            ['new', 'Instagram direct messages arrive in the Inbox next to Messenger chats. The first message from someone starts a lead in the New queue (source "Instagram"), and later messages join the same chat. Replies written here go out on Instagram, within 24 hours of the person\'s last message, as on Messenger.'],
            ['new', 'Somebody who writes after tapping your ad brings the ad\'s name with them: it shows on the lead under where it came from.'],
            ['new', 'The Inbox has a choice between every channel, Messenger and Instagram once Instagram chats are there, and each chat carries its channel\'s mark. Search finds an Instagram chat by its @username too.'],
            ['new', 'An Instagram chat shows the person\'s @username with a link to their profile, and their name and picture when Instagram shares them. Shared posts and reels, story mentions and replies to your story open as links; a message the person deletes is removed here too.'],
            ['new', 'Integrations shows the Instagram account linked to each Page. Its messages go to the brand of the Page and follow the Page\'s rule on the Distribute screen.'],
            ['improved', 'The privacy policy and the data deletion page now cover Instagram messages.'],
            ['improved', 'To switch it on: click Update system, link each Instagram account to its Page in Meta Business Suite, turn on "Allow access to messages" in the Instagram app, then click Connect again on Integrations. GIFs and stickers are not passed on by Instagram.'],
        ],
    ],
    [
        'version' => '1.14.0',
        'date' => '2026-10-13',
        'title' => 'A lead at a glance, one click to assign, one person as one lead',
        'changes' => [
            ['improved', 'What a lead asked for is in view the moment you open it: the message and every answer of the Facebook or website form, under "What they asked for". It no longer sits behind the Message tab. Answers read as sentences ("Fully written and ready to publish", not "fully_written_and_ready_to_publish").'],
            ['improved', 'Where the lead came from (Facebook Page, form, campaign, ad, website, page) has its own block, apart from the answers, with a button that opens the person\'s chat in the Page\'s inbox on Facebook.'],
            ['improved', 'Under the lead the tabs are Notes, Follow-ups, Messenger and Timeline, and a note is written right on the Notes tab instead of in a dialog.'],
            ['new', 'Assign with one click. On the Leads list, click the Owner of a lead ("Unassigned" or a name) and choose a person; the name changes at once. On a lead, the Assign tile and the Owner line open the same list. Admins can also send the lead to a team from there. A lead that is in a department is offered that department\'s people.'],
            ['new', 'One person, one lead. Somebody who fills in a Facebook lead form and also writes to the Page on Messenger is no longer two leads: the form and the chat join the same lead, whichever comes first. This works for forms that carry Facebook\'s link to the person\'s chat.'],
            ['new', 'For the pairs that are already there, a lead says "This person has another lead" (the same Messenger chat, email or phone) or "This may be the same person" (only the name agrees), with a Merge button for Admins and Managers. The chat, notes, follow-ups and history of the other lead move to the one you are on.'],
            ['fixed', 'A click on a lead in the list always opens it at the side. The small icon that appeared beside the name on hover took a click to the full page instead; it is gone. Ctrl + click on the name still opens the lead in a new tab.'],
            ['fixed', '"Open full page" on the side panel is in its place from the start and always leads to the lead you are looking at. It used to appear a moment late.'],
            ['improved', 'People who only work on an Upsell or Project Management team are not offered when a lead is assigned.'],
        ],
    ],
    [
        'version' => '1.13.0',
        'date' => '2026-10-13',
        'title' => 'Add a brand from its website alone',
        'changes' => [
            ['improved', 'Add brand now starts with one field: the website. LiquifyCRM reads it and fills in the name, the link, the currency and time zone of the country the brand works in, and its phone, email and address. You check it, change what is not right and create the brand. What you typed yourself is never replaced.'],
            ['new', 'Company details on every brand: phone, email, address, registered company name, company number and VAT or tax number. They are read from the website where it states them (also from its contact page) and can be changed under Edit brand, where "Fill from website" fills the empty ones. The brand\'s invoices and contracts will carry them.'],
            ['new', 'The brand\'s main logo is fetched from the website as well as its favicon, and can be replaced by an upload in the same step. A white logo, made for a dark page, is left out because it could not be seen on a white document. A logo or favicon you uploaded yourself is never replaced by a later fetch.'],
            ['improved', 'The look: the colour read from the website is now the brand\'s second colour (the ring around its mark, its chips and its line in charts) and the main colour is white, the tile behind the mark. A logo no longer disappears on a tile of its own colour. For a white logo, choose a dark main colour.'],
            ['new', 'Time zone is a search box: type a city or a country ("London", "Sydney", "Pakistan", "UK"). The usual ones are offered first instead of a list of four hundred.'],
            ['new', 'The teams: after the look, Add brand offers the departments most brands start with (Front Sales, Upsell, Project Management), ticked and created in one click. The same offer stands on the Departments screen while a brand has none.'],
            ['improved', 'The check at the end of Add brand also says whether the logo, the company details and the departments are in place, and leads on to adding the people.'],
            ['fixed', 'The link field of a brand left almost no room to type when the address of LiquifyCRM itself was long.'],
        ],
    ],
    [
        'version' => '1.12.0',
        'date' => '2026-10-12',
        'title' => 'After the first sale: hand-over, upsells and a storage for each client',
        'changes' => [
            ['new', 'Where new leads go: on Distribute leads you choose, for each website and each Facebook Page, the department or the one person its leads go to the moment they arrive. Anything without a choice waits in the queue to be assigned by hand, as before.'],
            ['new', 'A department is now Front sales or Upsell (set on the department itself). Front sales works leads until the first sale; an Upsell department looks after the clients that sale made.'],
            ['new', 'Hand-over: when a lead is marked Won, the seller can attach the contract and hand the client to an Upsell team, or to one person in it, in the same step or later from the lead. An Upsell department on round robin gives each new client to the next person in turn.'],
            ['new', 'Upsells: "Record an upsell" on a client adds another sale, as many times as the client buys. It goes under the name of whoever sold it and counts towards that person\'s target, their department\'s and the brand\'s.'],
            ['new', 'The client page tells the story in order: the front sale, the hand-over, every upsell with who sold it and the total so far, and the day the client was closed.'],
            ['new', 'A storage for each client. Files: contracts, invoices and anything else, up to 20 MB each. Saved text: what a client shares in words, such as a login; it is stored encrypted, and one marked hidden stays covered until someone clicks Show (the log says who looked).'],
            ['new', 'More than one person can work on a client, for example a project manager beside the upseller. Each records upsells under their own name.'],
            ['new', 'The Clients list has views for "Waiting for a person", "Not handed over" and "Closed", shows who looks after each client and how much came from upsells, and lets a Manager or an Admin tick several clients and give them to a person or a team.'],
            ['new', 'Close a client when the work is over. Everything is kept, and the client can be reopened.'],
            ['improved', 'After the hand-over front sales no longer sees the client. The seller keeps the lead and the sale they closed; later upsells do not show on their screens or in their Manager\'s numbers.'],
            ['improved', 'People who work only on an Upsell team start on My clients, and the lead screens are out of their menu.'],
            ['improved', 'The sales dashboard says how many of the month\'s sales were upsells and what they were worth.'],
        ],
    ],
    [
        'version' => '1.11.0',
        'date' => '2026-10-12',
        'title' => 'A faster, fuller chat',
        'changes' => [
            ['improved', 'A reply shows in the chat the moment you send it and is marked Sent as soon as Facebook has it. It no longer sits on "Sending".'],
            ['new', 'Replies written on Facebook itself (the Page inbox, the phone app) now appear in the chat too, marked "Sent on Facebook".'],
            ['new', 'Pictures, videos, voice notes, files and links a person sends are shown in the chat.'],
            ['new', 'The name is also looked up in the Page\'s own list of chats, and "Name and picture from Facebook" beside a chat asks again and says in plain words what Facebook answered.'],
            ['fixed', 'After your own action a later change (for example a message on its way) was taken for your own and did not show until the next minute.'],
        ],
    ],
    [
        'version' => '1.10.1',
        'date' => '2026-10-12',
        'title' => 'Quicker from screen to screen',
        'changes' => [
            ['improved', 'A screen in the menu starts loading the moment the mouse is over its name, so it is there when you click.'],
            ['improved', 'Screens settle in faster after they open.'],
            ['improved', 'The app itself is a third smaller to load: the part that draws charts is only fetched on screens with a chart.'],
        ],
    ],
    [
        'version' => '1.10.0',
        'date' => '2026-10-12',
        'title' => 'Work a chat without leaving it',
        'changes' => [
            ['new', 'Inbox: assign the lead of a chat to an Agent right beside the conversation, or take it back.'],
            ['new', 'Inbox and every lead: "Edit details" keeps what the person tells you (name, phone, email, company, address, the service they want). It stays on the lead and goes with it when it becomes a client.'],
            ['new', 'A chat shows the person\'s Messenger name and picture when Facebook shares them, and a lead still called "Messenger contact" takes the real name by itself.'],
            ['new', 'A link from the chat to the Page\'s own inbox on Facebook, where the person\'s profile can be seen (Facebook gives no link to a profile).'],
            ['improved', 'On a narrower screen, where the lead does not fit beside the chat, assign and edit sit in the head of the conversation.'],
        ],
    ],
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
