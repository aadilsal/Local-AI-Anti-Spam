=== Local AI Anti-Spam ===
Contributors: aadilsal
Tags: spam, anti-spam, contact form 7, wpforms, comments
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Spam filtering for Contact Form 7, WPForms and comments, with a model that runs on your own server. No CAPTCHA, no API key, no check limits.

== Description ==

Local AI Anti-Spam checks messages from **Contact Form 7**, **WPForms** and **WordPress comments** with a small machine-learning model that runs inside your site's own PHP. Nothing is sent anywhere to decide whether a message is spam.

* **No CAPTCHA, no API key, no monthly limit.** Install it and it starts working.
* **Nothing leaves your server.** The free plugin makes no outside connections at all.
* **Nothing is lost.** Messages that look like spam are held in a quarantine for 30 days (you can change this), never deleted on arrival. "Not spam" delivers a held message exactly as the form would have.
* **It explains itself.** Every held message shows its score and the words that pushed it towards spam.
* **Unsure messages are still delivered**, with "[Possible spam]" added to the subject line, so a real enquiry is never silently dropped.
* **Your rules win.** Always allow or always block an email address, a domain, an IP address or a phrase.
* **A short digest** (weekly by default) lists newly held messages, lowest score first, so a real customer held by mistake is easy to spot.
* **Fast.** A check takes a few milliseconds and uses under 16 MB of memory.

= Which messages are checked =

* Contact Form 7 and WPForms submissions, and comments from visitors who are not logged in.
* The model reads English. Messages in other languages are delivered without a check and marked "not checked".

= Status: beta =

This is an early version. The model was trained and tested on public datasets and on synthetic messages; its error rate on real contact-form traffic has not been measured yet. Please check the quarantine regularly, especially in the first weeks, and use "Not spam" whenever something real was held. That is exactly how a real customer is never lost.

== Installation ==

1. Install and activate the plugin.
2. That's it. Protection starts immediately for every supported form plugin it finds.
3. Optional: Anti-Spam → Settings to change sensitivity, how long held messages are kept, or the digest.

== Frequently Asked Questions ==

= Does any message leave my server? =

No. The model file ships inside the plugin and runs in PHP on your server. The free plugin makes no outside connections.

= What happens if a real message is marked as spam? =

It is held, not deleted. Open Anti-Spam → Quarantine, where the messages most likely to be real are listed first, and click "Not spam". The message is delivered to the form's normal recipients, and you can choose to always allow that sender.

= What does a visitor see when their message is held? =

Contact Form 7 shows its usual "There was an error trying to send your message" text. WPForms shows its normal confirmation, as it does for spam it catches itself. Held comments go to the WordPress Spam list.

= Can I release a message that had a file upload? =

The message is delivered, but Contact Form 7 deletes uploaded files after each submission, so the file itself can't be resent; the email lists the file's name.

= How long are held messages kept? =

30 days by default (1 to 365 in Settings). They are then deleted automatically. The plugin also removes all its data when you delete it.

= What about privacy laws? =

Held messages contain what the visitor typed, so the plugin adds suggested text to your privacy policy page and supports WordPress's "Export personal data" and "Erase personal data" tools. Storing IP addresses can be turned off.

= Does it work with page caching? =

Yes. Checks run when a form is submitted, which page caches don't serve.

== Privacy ==

The free plugin sends no data anywhere. Held messages are stored in your site's database and deleted after the retention period. No telemetry, no tracking.

== Credits ==

The model was trained on data from these sources, used under their licences:

* UCI SMS Spam Collection, by Tiago A. Almeida and José María Gómez Hidalgo (CC BY 4.0).
* UCI YouTube Spam Collection, by Túlio C. Alberto, Johannes V. Lochter and Tiago A. Almeida (CC BY 4.0).
* BANKING77, by PolyAI (CC BY 4.0).
* CLINC150, by Clinc (CC BY 3.0).
* HWU64 NLU Evaluation Data, by Xingkun Liu et al. (CC BY 4.0).
* Taskmaster 1–3, by Google Research (CC BY 4.0).
* SNIPS NLU benchmark (CC0), ABCD by ASAPP and MultiWOZ 2.2 (MIT), India Spam SMS Classification (MIT), Bitext customer support data (CDLA-Sharing-1.0).
* Synthetic training messages generated locally with Qwen2.5-7B-Instruct (Apache-2.0) and Phi-4-mini-instruct (MIT).

== Changelog ==

= 0.1.0 =
* First beta: Contact Form 7, WPForms and comments; quarantine with release; allow and block rules; dashboard widget; digest email; privacy export and erase.
* Model local-baseline-20261107-0958. On held-out synthetic contact-form messages it held 0.33% of genuine messages (95% interval 0.19–0.58%) and caught 90.5% of spam. Not yet measured on real contact-form traffic.
