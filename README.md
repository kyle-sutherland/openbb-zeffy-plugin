# OpenBB Zeffy Contact Sync

A separate WordPress plugin that listens to Open Bulk Buying member hooks and calls Zeffy's contact API. Install it beside the main `open-bulk-buying` plugin. No HTTP endpoint between the two plugins is needed: OpenBB fires WordPress actions and this plugin handles them.

## Setup

1. Activate Open Bulk Buying.
2. Wire the signup checkbox to the companion after signup succeeds. Pass a native PHP boolean, including `false` when unchecked:

   ```php
   do_action( 'openbb_newsletter_consent_changed', $user_id, $checked );
   ```

   The companion saves the choice in its own site-specific user meta so it can wait for approval before creating a contact. The main plugin needs no newsletter profile field. Call the same hook if a WordPress preference control later changes the choice.

   An optional alternative is a member-visible boolean OpenBB profile field. Its key defaults to `newsletter`, and `openbb_profile_values_changed` already reaches this companion. If the key differs, set `OPENBB_ZEFFY_NEWSLETTER_FIELD_KEY` in `wp-config.php`.

3. Generate an organization API key in Zeffy **Settings → Integrations**. Zeffy requires organization verification for contact writes. Put the key in `wp-config.php` (or inject it there from your host's secret store):

   ```php
   define( 'OPENBB_ZEFFY_API_KEY', 'your-secret-key' );
   ```

4. Activate this plugin before processing signups with either consent path.

The current Open Bulk Buying main branch does not emit the dedicated consent hook, so the form-only path still needs to be wired there. An unchecked box or missing consent never creates a contact. If neither path supplies a choice for an active member, an administrator error is recorded. Keep the API key out of the repository and browser code.

### Main plugin handoff for the form-only checkbox

The current signup JavaScript sends unknown form fields under `profile`, where OpenBB rejects keys that are not configured profile fields. Add the checkbox as a top-level boolean in the signup request and REST schema. For visitor signup, carry it through the email-code pending payload and fire the action after `OPENBB_Signup::register()` succeeds in the verify request. For signed-in join, fire it after `OPENBB_Signup::join()` succeeds. The existing member-created/status hooks can fire before a later signup step fails, so the consent action must wait for a successful result.

## Sync behavior

- `openbb_member_created`, `openbb_member_status_changed`, `openbb_profile_values_changed`, and `openbb_newsletter_consent_changed` queue a reconciliation. Contact-detail changes also queue one.
- A short delay lets signup and staff member saves finish before the current member state is read. WordPress Cron runs the queued job; ensure it is enabled or invoked by a server cron.
- Active members with an explicit newsletter opt-in are upserted by email using `PUT /api/v1/contacts`. The saved Zeffy contact ID is used for later email changes and deletion. Applicants, declined members, and inactive members are never added.
- A newsletter opt-out keeps an existing Zeffy contact and pauses WordPress-driven updates. A move away from active status deletes a contact previously linked by this plugin, even after an opt-out. A WordPress account or multisite membership removal also deletes a previously linked contact once the removal completes. OpenBB's bulk site-data cleanup captures linked IDs before erasing member meta, then reconciles them. A member with no saved Zeffy ID never triggers a contact deletion by email. Shared contacts are kept while another active member in the same WordPress installation is linked to the same Zeffy ID, including one who has opted out and paused updates.
- Zeffy `429` and server failures are retried with capped backoff while the condition persists; the `Retry-After` header is respected. An administrator notice identifies the last failure by local user ID and error code, without exposing the API key or provider response body.

Zeffy contact deletion is permanent and unlinks past payment history. It does not stop a recurring donation. Review that behavior before activating the plugin on a production site: [Zeffy's contact deletion guide](https://support.zeffy.com/managing-and-editing-contacts-437tm).

The Zeffy contact API does not expose a newsletter list or subscription setting. This plugin uses the saved checkbox choice as its eligibility rule for initial contact sync; creating a Zeffy contact does not itself mark a subscription in Zeffy. Members can unsubscribe from Zeffy emails through the link in those emails; Zeffy does not report that choice to this plugin. Existing active members are not backfilled automatically: only a later relevant OpenBB hook queues them.

## Local checks

Run the standalone WordPress HTTP and hook mocks without a Zeffy key or network access:

```sh
php tests/api-client-test.php
php tests/sync-test.php
```

These tests exercise the API request contract and hook decisions. A live WordPress installation and a verified Zeffy organization are still needed to verify an end-to-end sync.
