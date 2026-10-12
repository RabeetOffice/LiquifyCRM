@php
    $name = config('liquify.legal.name');
    $email = config('liquify.legal.email');
    $facebookApp = config('liquify.legal.facebook_app');
    $mailto = 'mailto:'.$email.'?subject='.rawurlencode('Delete my data');
@endphp
<x-layouts.legal title="Delete your data" :intro="'You can ask for your information to be removed from '.$name.' at any time. It is free.'">
    <div class="lq-legal-short">
        <p class="lq-legal-short-title">In short</p>
        <ul>
            <li>Write to <a href="{{ $mailto }}">{{ $email }}</a> and say what we should look for.</li>
            <li>We delete it within 30 days and tell you when it is done.</li>
        </ul>
    </div>

    <h2>How to ask</h2>
    <ol class="lq-guide">
        <li class="lq-guide-step">
            <span class="lq-guide-mark">1</span>
            <div>
                <p class="lq-guide-title">Send us an email</p>
                <p class="lq-guide-text">Write to <a href="{{ $mailto }}">{{ $email }}</a> with the subject "Delete my data".</p>
            </div>
        </li>
        <li class="lq-guide-step">
            <span class="lq-guide-mark">2</span>
            <div>
                <p class="lq-guide-title">Tell us what to look for</p>
                <p class="lq-guide-text">The name, email address or phone number you used, and the business, website or Facebook Page you contacted. For a Messenger chat, the name on your Facebook profile.</p>
            </div>
        </li>
        <li class="lq-guide-step">
            <span class="lq-guide-mark">3</span>
            <div>
                <p class="lq-guide-title">We check and delete</p>
                <p class="lq-guide-text">We may ask one question to be sure the request is yours. Within 30 days your information is deleted, and we email you to say so.</p>
            </div>
        </li>
    </ol>

    <h2>If you connected Facebook to {{ $name }}</h2>
    <p>Either of these removes the connection, and you can do both yourself:</p>
    <ul>
        <li><strong>In {{ $name }}:</strong> open Integrations and choose Disconnect. Your Facebook access tokens are removed from {{ $name }} straight away, and new leads and messages stop arriving.</li>
        <li><strong>On Facebook:</strong> open Settings and privacy, then Settings, then Business integrations (or Apps and websites). Find "{{ $facebookApp }}" and choose Remove.</li>
    </ul>
    <p>Leads and chats that had already arrived stay with the business. To have those deleted too, send the email described above.</p>

    <h2>What is deleted</h2>
    <ul>
        <li>Your enquiry: your name, email address, phone number, message and the answers you gave in a form.</li>
        <li>The notes and follow-up tasks written about your enquiry.</li>
        <li>Your Messenger conversation with the business.</li>
        <li>If you became a client: the files kept for your order (such as a contract or an invoice) and any text you shared for the work, such as a login.</li>
        <li>If you connected Facebook: your Facebook name and user ID, the list of your Pages and their access tokens.</li>
        <li>If you are a team member: your {{ $name }} account.</li>
    </ul>

    <h2>What may be kept</h2>
    <ul>
        <li>Records a business must keep by law, such as the record of a sale.</li>
        <li>Backup copies, which are not edited but are removed automatically as they age. The oldest backup we keep can be up to two years old.</li>
    </ul>

    <p>How {{ $name }} uses information is explained in the <a href="{{ route('legal.privacy') }}">privacy policy</a>.</p>
</x-layouts.legal>
