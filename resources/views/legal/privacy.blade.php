@php
    $name = config('liquify.legal.name');
    $email = config('liquify.legal.email');
@endphp
<x-layouts.legal title="Privacy policy" :intro="'What '.$name.' collects, why, who sees it and how to have it removed.'">
    <div class="lq-legal-short">
        <p class="lq-legal-short-title">In short</p>
        <ul>
            <li>{{ $name }} keeps the enquiries a business receives, from its website, its Facebook and Instagram lead forms, its Facebook Page inbox and its Instagram direct messages, in one place so its team can answer them.</li>
            <li>We use that information only to provide this service. We do not sell it and we do not use it for advertising.</li>
            <li>You can ask for your information to be deleted at any time: see <a href="{{ route('legal.data-deletion') }}">Delete your data</a>.</li>
        </ul>
    </div>

    <h2>1. Who we are</h2>
    <p>{{ $name }} is a customer relationship tool. Businesses use it to collect their sales enquiries and to follow them up. In this policy "we" means {{ $name }}, and "the business" means the company whose website, lead form, Facebook Page or Instagram account you contacted.</p>
    <p>Questions about this policy go to <a href="mailto:{{ $email }}">{{ $email }}</a>.</p>

    <h2>2. Whose information we hold</h2>
    <ul>
        <li><strong>People who make an enquiry.</strong> You filled in a form on a business's website, filled in one of its lead forms on Facebook or Instagram, sent a message to its Facebook Page, or sent a direct message to its Instagram account.</li>
        <li><strong>People who connect Facebook.</strong> You signed in with Facebook to connect your Pages to {{ $name }}.</li>
        <li><strong>Team members.</strong> You have a {{ $name }} account and sign in to work.</li>
    </ul>

    <h2>3. What we collect</h2>

    <h3>When you make an enquiry</h3>
    <ul>
        <li><strong>From a website form:</strong> what you typed (usually your name, email address, phone number and message), the page you were on, and the campaign tags in the link you followed. The website may also send your IP address and browser type; those stay only in a temporary record that is deleted within 30 days, not with your enquiry.</li>
        <li><strong>From a Facebook or Instagram lead form:</strong> the answers you gave in the form, and the names of the Page, the form, the ad and the campaign it came from.</li>
        <li><strong>From Messenger:</strong> the messages you send to the business's Page and the replies you get, your name and profile picture if Facebook shares them, and the ID Facebook gives you for that Page. We do not download attachments; we keep only the reference Facebook sends with the message.</li>
        <li><strong>From an Instagram direct message:</strong> the messages you send to the business's Instagram account and the replies you get, your name, Instagram username and profile picture if Instagram shares them, the ID Instagram gives you for that account and, if you wrote after tapping an ad, the name of that ad. As with Messenger, we do not download photos, videos or shared posts; we keep only the reference Instagram sends with the message. If you delete a message, we remove its words too.</li>
        <li><strong>What the team adds</strong> while working on your enquiry: notes, follow-up tasks, its status and, if you buy, the amount and date of each sale.</li>
        <li><strong>If you become a client:</strong> the files of your order that the team keeps for you (such as a contract, an invoice or the material you send), and any text you share with them for the work, such as a login to a service of yours.</li>
    </ul>

    <h3>When you connect Facebook</h3>
    <ul>
        <li>Your Facebook name and user ID.</li>
        <li>The names and IDs of the Pages you manage, and the username and ID of the Instagram account linked to each.</li>
        <li>Access tokens that let {{ $name }} receive the leads and messages of those Pages and their Instagram accounts, and send replies. They are stored encrypted.</li>
    </ul>
    <p>We ask Facebook only for the permissions this needs. {{ $name }} does not post on your Page, your Instagram account or your profile, and it never sees your Facebook or Instagram password.</p>

    <h3>When you have a {{ $name }} account</h3>
    <ul>
        <li>Your name and email address, and your phone number and job title if you add them.</li>
        <li>Your password, stored as a one-way hash that nobody can read, and the key of your authenticator app, stored encrypted.</li>
        <li>When, and from which IP address, you last signed in.</li>
        <li>A record of what you do in {{ $name }}, such as changing the status of an enquiry, with the time, your IP address and your browser type.</li>
    </ul>

    <h3>Cookies</h3>
    <p>{{ $name }} uses only the cookies it needs to work: one for your session, one that protects forms against forgery, one that keeps you signed in if you tick "Remember me", and one that remembers light or dark mode. There are no advertising or analytics cookies.</p>

    <h2>4. What we use it for</h2>
    <ul>
        <li>To show each enquiry to the team of the business it was sent to, so they can answer it, follow it up and record the result.</li>
        <li>To send the replies the team writes in Messenger or Instagram and, if the business has switched it on, an email saying your website enquiry arrived.</li>
        <li>To tell team members about new and assigned enquiries, in the app and by email.</li>
        <li>To show the business its own reports: how many enquiries arrived, from where, and how many became sales.</li>
        <li>To keep accounts safe and to keep a record of who did what.</li>
    </ul>
    <p>We do not sell personal information and we do not use it for advertising. Information received from Facebook and Instagram is used only to provide this service to the business that connected its Page.</p>

    <h2>5. Who can see it</h2>
    <ul>
        <li><strong>The business you contacted:</strong> its team members, each limited by their role. An enquiry sent to one business is never shown to another.</li>
        <li><strong>The administrators</strong> who run {{ $name }}.</li>
        <li><strong>Companies that work for us:</strong> the hosting company whose servers store the data (Hostinger), and the email service each business uses to send its emails.</li>
        <li><strong>Meta,</strong> when a reply is sent in Messenger or Instagram: it travels through Facebook or Instagram, as every message there does.</li>
        <li><strong>Authorities,</strong> when the law requires it.</li>
    </ul>

    <h2>6. How long we keep it</h2>
    <ul>
        <li><strong>Enquiries, their notes, Messenger and Instagram chats and sales, and a client's files and shared text:</strong> for as long as the business uses {{ $name }}, or until you or the business ask for them to be deleted.</li>
        <li><strong>The raw copy of an incoming form, Facebook or Instagram event:</strong> 14 days after it has been handled, and never longer than 30 days.</li>
        <li><strong>Activity records:</strong> between 7 and 90 days in the live system, then in archive files for up to one year. The history shown on an enquiry stays with that enquiry.</li>
        <li><strong>Facebook access tokens:</strong> until Facebook is disconnected in {{ $name }}; they are removed at that moment.</li>
        <li><strong>Backups:</strong> made daily and removed automatically as they age. The oldest backup we keep can be up to two years old.</li>
    </ul>

    <h2>7. How we protect it</h2>
    <ul>
        <li>The connection to {{ $name }} is encrypted (https).</li>
        <li>Passwords are stored as one-way hashes; Facebook tokens, authenticator keys and email passwords are stored encrypted.</li>
        <li>Text a client shares for their work, such as a login, is stored encrypted, and a client's files are kept in private storage that only the team members working for that client can open.</li>
        <li>Administrators must sign in with a second step from an authenticator app.</li>
        <li>Each team member sees only what their role allows, and each business's data is kept apart from the others.</li>
        <li>Actions are recorded, so it can be checked who did what.</li>
    </ul>

    <h2>8. Your choices</h2>
    <p>You can ask us for a copy of the information we hold about you, to correct it, to stop using it or to delete it. Write to <a href="mailto:{{ $email }}">{{ $email }}</a>; we answer within 30 days. The steps for deletion are on <a href="{{ route('legal.data-deletion') }}">Delete your data</a>.</p>
    <p>If your enquiry went to a particular business, you can also ask that business directly: it decides how your enquiry is used, and we act on its instructions. Depending on where you live, you may also have the right to complain to your data protection authority.</p>

    <h2>9. Children</h2>
    <p>{{ $name }} is made for businesses. It is not meant for children, and we do not knowingly collect information from anyone under 16.</p>

    <h2>10. Changes to this policy</h2>
    <p>When this policy changes, the date at the top of this page changes with it.</p>

    <h2>11. Contact</h2>
    <p>{{ $name }}, <a href="mailto:{{ $email }}">{{ $email }}</a>.</p>
</x-layouts.legal>
