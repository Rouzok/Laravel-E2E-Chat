# Issue #9 Progress

Issue: [Contact names, automatic contacts, and contact profiles](https://github.com/Rouzok/vibe-engineering-2/issues/9)

## Acceptance checklist

- [x] Adding `user2@gmail.com` with an empty name displays `user2@gmail.com`; adding it with `Friend` displays `Friend` for user1 only.
- [x] After user1 sends the first successful message, user2 sees user1 as a contact named `user1@gmail.com` and can reply without manually adding them.
- [x] Automatic contacts work online and offline; repeated messages preserve existing labels and avoid duplicates.
- [x] Profile name edits appear throughout the owner's chat UI and survive reloads; clearing the label restores the email.
- [x] One cleanup vote leaves history intact; mutual approval clears only the agreed history for those two users. Decline and cancellation preserve history.
- [x] Blocking prevents messages through both the UI and direct requests; unblocking restores messaging only when neither participant still blocks the other.
- [x] Reports persist for administrator review and remain private from the reported user.
- [x] Users cannot edit another owner's contacts, vote for someone else, remove someone else's block, or access private reports.
- [x] Existing contacts, message history, unread indicators, and encryption continue to work. Run affected Pest tests and complete the project's required formatting checks during implementation.

## Implementation tasks

- [x] Extend persistent storage and relationships for custom contact names, blocks, cleanup votes, and reports.
- [x] Update contact addition, contact labels, and contact-list/chat display behavior.
- [x] Create reverse contacts on successful message saves and refresh online recipients' contact lists and unread counts.
- [x] Build the contact profile page and implement custom-name editing, cleanup voting, blocking, and reporting.
- [x] Add focused regression coverage, run affected Pest tests, and run required formatting.

## Verification

- Focused Pest coverage passed: 39 tests, 156 assertions across issue #9, add-contact, message API/database, role-access, and message broadcasting tests.
- Pint formatting passed for changed and newly created PHP files.
