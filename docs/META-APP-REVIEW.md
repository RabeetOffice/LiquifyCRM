# Meta App Review: what to do

The checklist for sending the Meta app **"LiquifyCRM Leads"** (app id `1736979774197502`) to App Review.
Do it **after** LiquifyCRM is live on its own domain. Written on 14 October 2026.

## Why we need it

The app is Live but has only **Standard Access** on every permission (App Review: "Not submitted").
With Standard Access Meta shares data only about people who have a role on the app. For our customers
that means:

| What | Without App Review | After App Review |
| --- | --- | --- |
| Facebook lead forms | Arrive | Arrive |
| Messenger messages | Arrive | Arrive |
| Name and picture of a Messenger contact | Missing ("Facebook did not share this person's profile") | Shown |
| Instagram direct messages from customers | Probably blocked (Meta: "all apps must be submitted for review before they can interact with everyone on Instagram") | Arrive |

Meta does not exempt apps that are only used inside our own office: what counts is that the data belongs
to our customers, who have no role on the app.

Links used below:

- Dashboard: https://developers.facebook.com/apps/1736979774197502/dashboard/
- Use cases: https://developers.facebook.com/apps/1736979774197502/use_cases/
- App Review: https://developers.facebook.com/apps/1736979774197502/app-review/submissions/
- Basic settings: https://developers.facebook.com/apps/1736979774197502/settings/basic/
- Lead Ads testing tool: https://developers.facebook.com/tools/lead-ads-testing

In this file `https://<your-domain>` is the domain LiquifyCRM will live on.

---

## Step 1. Move the Meta app to the new domain

Meta's reviewers also check the app's settings and the privacy pages, so these must point at the new domain first.

- [ ] On the server: `APP_URL=https://<your-domain>` in `.env`. Do not cache the config afterwards.
- [ ] Open `https://<your-domain>/privacy` and `https://<your-domain>/data-deletion` while signed out. Both must
      load, and the company name and email on them must be right (`LIQUIFY_LEGAL_NAME`, `LIQUIFY_LEGAL_EMAIL` in `.env`).
- [ ] **App settings › Basic**:
  - [ ] App domains: `<your-domain>`
  - [ ] Privacy Policy URL: `https://<your-domain>/privacy`
  - [ ] User data deletion: "Data deletion instructions URL" `https://<your-domain>/data-deletion`
  - [ ] Terms of Service URL: it still points at Facebook's own terms. Remove it, or ask Claude to add a
        `/terms` page and put that here. Reviewers may question a terms link that is not ours.
  - [ ] App icon (1024 × 1024), contact email and category "Business and pages" are filled in.
  - [ ] Website platform: Site URL `https://<your-domain>`.
- [ ] **Facebook Login for Business › Settings › Valid OAuth Redirect URIs**: `https://<your-domain>/integrations/facebook/callback`
- [ ] **Webhooks**: change the callback URL to `https://<your-domain>/webhooks/meta` with the same verify token, for
      **both** objects:
  - [ ] Page: `leadgen`, `messages`, `messaging_postbacks`, `message_deliveries`, `message_reads`, `message_echoes`
  - [ ] Instagram: `messages`, `messaging_postbacks`, `messaging_seen`, `messaging_referral`
- [ ] In LiquifyCRM: **Integrations › Connect again** (tick every Page and the Instagram account in Facebook's window),
      then check that each Page says "Leads arriving" and shows its Instagram @username.

## Step 2. Become a Tech Provider (business and access verification)

The dashboard says App Review is only open to Tech Providers. Start this first: Meta can take a few days.

- [ ] Dashboard › **Become a Tech Provider**, and follow the steps.
- [ ] **Business verification** of the company's Business Manager: Meta asks for documents with the company's legal
      name and address (for example a registration certificate, a utility bill or a bank statement), a business phone
      or email, and sometimes the website's domain.
- [ ] **Access verification**: short questions about what the app does and whose data it handles. Answer: an internal
      CRM of our own company; it handles the leads and messages of our own Pages and Instagram accounts.

## Step 3. Put the permissions in the submission

Open each use case › **Customize › Permissions and features**, and for each line below choose
**Actions › Add to App Review**.

| Use case | Permission or feature | What LiquifyCRM uses it for |
| --- | --- | --- |
| Messenger from Meta | `pages_messaging` | Read and answer Messenger chats |
| Messenger from Meta | Business Asset User Profile Access | Name and picture of the person in a chat |
| Messenger from Meta | `instagram_basic` | Find the Instagram account linked to each Page |
| Messenger from Meta | `instagram_manage_messages` | Read and answer Instagram direct messages |
| Messenger from Meta | `pages_manage_metadata` | Subscribe each Page to our webhook |
| Messenger from Meta | `business_management` | See the Pages that live in our Business Manager |
| Capture & manage ad leads | `leads_retrieval` | Read the answers of a Lead Ads form |
| Capture & manage ad leads | `pages_show_list` | List the Pages of the person who connects |
| Manage everything on your Page | `pages_read_engagement` | Read Page details and the names of lead forms |

Do **not** add what LiquifyCRM does not use (for example `ads_management`, `email`, Human Agent): Meta rejects
permissions that the app cannot show in use. Human Agent (replies up to 7 days instead of 24 hours) can be asked
for later, once LiquifyCRM uses it.

## Step 4. Write what each one is for

Meta asks, per permission, how the app uses it. The texts below can be pasted as they are (they are in English
because the reviewers read English).

**General description of the app**

> LiquifyCRM is the internal CRM of our company. Our sales team uses it to follow up the enquiries our brands
> receive: Lead Ads form submissions, Messenger messages to our Facebook Pages and Instagram Direct messages to
> our Instagram professional accounts. An Admin connects Facebook once and maps each Page to the brand it belongs
> to; every lead and chat is then shown only to the team members of that brand. We do not sell data or use it for
> advertising. Privacy policy: https://<your-domain>/privacy. Data deletion: https://<your-domain>/data-deletion.

**pages_show_list**

> After an Admin signs in with Facebook on our Integrations screen, we list the Pages that person manages so the
> Admin can choose which brand each Page belongs to. Without this we cannot know which Pages to connect.

**business_management**

> Our Pages are owned by our company's Business Manager. We use this permission so that the Pages of the Business
> Manager appear in the list when an Admin connects Facebook, and so their access tokens can be used.

**pages_manage_metadata**

> When a Page is connected we subscribe it to our webhook (leadgen, messages and message events) so that new Lead
> Ads submissions and messages reach our CRM within seconds. We also read the Instagram professional account linked
> to the Page so its direct messages go to the same brand.

**pages_read_engagement**

> We read the Page's name and basic details, and the name of the lead form a lead came from, to show where each
> enquiry came from on the lead's screen.

**leads_retrieval**

> When someone submits one of our Lead Ads forms, the webhook gives us the lead id. We fetch the answers of that
> form (name, email, phone and the questions on the form) and create a lead in the CRM so our sales team can contact
> the person. Leads are shown only to our team members of the brand that owns the Page.

**pages_messaging**

> People message our Facebook Pages to ask about our services. Each conversation appears in the CRM Inbox, where our
> team reads it and answers within the 24-hour window. Replies are typed by a person; the app does not send
> automated or promotional messages.

**Business Asset User Profile Access**

> We show the name and profile picture of the person who writes to our Page next to their conversation and on the
> lead it created, so our team knows who they are talking to. The data is not used for anything else.

**instagram_basic**

> We read the id and username of the Instagram professional account linked to each connected Page, so that the
> account's direct messages are shown under the right brand.

**instagram_manage_messages**

> People send Direct messages to our Instagram professional accounts, often after tapping one of our
> Click-to-Instagram-Direct ads. Each conversation appears in the CRM Inbox next to our Messenger chats; our team
> reads it and answers within the 24-hour window. Replies are typed by a person; the app does not send automated
> or promotional messages.

**Data handling questions** (asked in the submission)

- Data processors: Hostinger (hosts the server and the database). No one else receives the data.
- Data is not sold, not shared with other companies and not used for advertising.
- Responsible entity: our company's legal name and country.
- People can ask for deletion through https://<your-domain>/data-deletion; we answer within 30 days.

## Step 5. Record the screencasts

One short video (1 to 3 minutes) per permission, or one longer video that shows every flow with a caption saying
which permission is being shown. Tips:

- Use the live site on the new domain, in English, with the address bar visible.
- Start signed out of Facebook, so the video shows the Facebook Login window listing the permissions.
- Use a second, private Facebook and Instagram account to play the customer.
- Add short captions ("Using pages_messaging to reply") in any video editor, or say it out loud.

| Video | Shows |
| --- | --- |
| A. Connect | Integrations › Connect Facebook › the Facebook window with the permissions › back in LiquifyCRM the Pages appear › choose a brand for a Page › "Leads arriving" and the Instagram @username under the Page. Covers `pages_show_list`, `business_management`, `pages_manage_metadata`, `instagram_basic`. |
| B. Lead Ads | In the Lead Ads testing tool, submit a test lead for one of our forms › in LiquifyCRM the lead appears in Leads › open it: the answers, the form and the Page under "Where it came from". Covers `leads_retrieval`, `pages_read_engagement`. |
| C. Messenger | From the private Facebook account, message the Page › the chat appears in the Inbox with the person's name and picture › type a reply › the reply arrives in Messenger on the phone (show the phone or a second browser). Covers `pages_messaging`, Business Asset User Profile Access. |
| D. Instagram | From the private Instagram account, send a Direct message to our Instagram account › the chat appears in the Inbox under Instagram with the @username › type a reply › it arrives in Instagram. Covers `instagram_manage_messages`. |

## Step 6. A sign-in for the reviewers

Meta may want to try the app. Admins must use an authenticator app on LiquifyCRM, which a reviewer cannot do, so:

- [ ] Create a **Manager** (not an Admin) in one brand, for example `meta-review@<your-domain>`, with a strong password.
      A Manager can open Leads and the Inbox and answer chats; the Connect step is shown in video A.
- [ ] Put the email and password in the submission's "testing instructions", with:
      "Sign in at https://<your-domain>/login. Open Inbox to see the Messenger and Instagram conversations, and Leads
      to see the Lead Ads leads. Connecting Facebook is limited to our Admins; it is shown in the video."
- [ ] Suspend or remove this account on /admin/people once the review is over.

## Step 7. Submit

- [ ] App Review page › check that every permission from step 3 is listed with its text and video › **Submit**.
- [ ] Once submitted it cannot be edited or cancelled, so check everything before.
- [ ] Meta answers by email and in the Alert Inbox, usually within a few days. If something is refused, read the
      reason, fix that point, and submit again.

## Step 8. After approval

- [ ] Each permission shows Advanced Access. Nothing changes in LiquifyCRM's code.
- [ ] In LiquifyCRM: Integrations › **Connect again** once more.
- [ ] Ask somebody **without** a role on the app to message the Page and the Instagram account: the chats should
      arrive with the person's name and picture ("Name and picture from Facebook" in the Inbox now works for everyone).
- [ ] Remove the reviewer account (step 6).
