# Internal chat enhancements — 5 October 2026

Additive changes to the existing internal chat (`app/Domain/Chat`). Migration `2026_10_05_000122_extend_internal_chat.php` adds `internal_chat_mentions`, `internal_chat_attachments` and `internal_chat_calls.kind`. It is applied automatically to the `testing` database by the test suite; it has **not** been run on the local business database.

## Behaviour

- **Read state.** `internal_chat_members.last_read_message_id` is now used. `POST /chat/rooms/{room}/read {message_id}` only moves the pointer forward. A user's first visit to the company chat starts caught up, so history is not shown as unread.
- **Room list.** Each room returns `unread`, `mentioned`, `member_count` and `last_message` (`id`, `sender`, `own`, `body` preview of 120 characters, `attachment_kind`, `created_at`). The company chat's `member_count` is the organization size.
- **Viewed by.** `viewedBy` (page prop) and `viewed_by` (messages endpoint) list the members who have read the caller's latest message in the room.
- **Mentions.** `POST /chat/rooms/{room}/messages` accepts `mentions: [userId]`, limited to the room's participants. Each mention is stored and sends one `chat_mention` notification (no message text in the notice).
- **Attachments.** `POST /chat/rooms/{room}/attachments` (multipart `files[]`, optional `body`, `mentions[]`, `voice`, `duration`): up to 5 files of 10 MB; allowlist `jpg jpeg png webp gif pdf doc docx xls xlsx csv txt mp3 m4a wav ogg oga webm mp4` (SVG, HTML and executables are rejected). Files live on the private `local` disk under `organizations/{org}/chat/{room}/`. `GET /chat/attachments/{id}` checks room access, serves images and audio inline and everything else as a download, with `nosniff`.
- **Voice notes.** `voice=1` with a single audio file (max 5 MB) and `duration` of 1–300 seconds.
- **Search.** `GET /chat/rooms/{room}/messages?q=` searches the latest 2,000 messages of the room. Bodies are encrypted at rest, so this is done after decryption; older history is not searched.
- **Video calls.** `POST /chat/rooms/{room}/call {kind: audio|video}`; direct chats only. Group and company-wide calls would need a media server and are not supported.

## Limits to know about

- Attachment files are not virus-scanned.
- Read pointers are per member; leaving and re-joining is not modelled.
- Message bodies stay encrypted; attachment contents are not encrypted at rest.
