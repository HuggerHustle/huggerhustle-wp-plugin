=== HuggerHustle ===
Contributors: huggerhustle
Tags: leads, prospecting, outreach, email, b2b
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Find businesses that could use what you sell, read each introduction, approve the ones to send.

== Description ==

HuggerHustle finds businesses that could use what you sell, writes an introduction to each one, and sends it on your behalf. This plugin puts that work in your WordPress dashboard: it reads your site, starts a campaign from it, shows the businesses waiting for your approval, and lets you read and approve each introduction without leaving `wp-admin`.

Nothing is sent by this plugin. It talks to your HuggerHustle account, which does the finding, the writing and the sending; approving a business here is what authorizes HuggerHustle to send that one.

**What it does**

* Connects this site to your HuggerHustle account with an API key.
* Starts a campaign from this site's address, name and published pages.
* Shows your email balance, your campaigns, and the businesses waiting for approval.
* Shows the introduction written for each business, so you approve words you have read.

**What it does not do**

* It does not send email, collect replies or store a copy of anything.
* It does not create accounts, publish posts or change your site.
* It does not read or store visitor data.

== External services ==

This plugin connects to the HuggerHustle service, which you must have an account with.

**What is sent, and when**

Nothing is sent until you save an API key. With no key the plugin makes no request at all. Once a key is saved, the plugin sends:

* Your API key, in an `Authorization: Bearer` header, on every request.
* When you press **Find businesses for this site**: this site's address, its name, and the text of up to five published pages or products.
* When you approve a business or open the dashboard: only the campaign and business identifiers being read or changed.

**Where it goes**

`https://huggerhustle.com/api/v1` — the address is shown and can be changed on the plugin's settings screen.

**What comes back**

Your email balance, your campaigns and their counts, and the businesses of a campaign with the introduction written for each. An unsent business's email address is redacted by HuggerHustle's server before it is sent to this plugin.

**Terms and privacy**

* Terms of service: https://huggerhustle.com/terms
* Privacy policy: https://huggerhustle.com/privacy
* HuggerHustle's own privacy policy covers the account data it holds: https://huggerhustle.com/privacy

== Installation ==

1. Upload the `huggerhustle` folder to `/wp-content/plugins/`, or install the zip from Plugins → Add New.
2. Activate the plugin.
3. Open HuggerHustle → Settings, and paste an API key from your HuggerHustle account (Account → API keys).

== Frequently Asked Questions ==

= Does this plugin send email? =

No. HuggerHustle sends from its own mailboxes. Approving a business here tells HuggerHustle to send that one introduction.

= What happens if I revoke the key? =

The dashboard stops working and says so. Nothing on your site breaks, and no campaign is affected — campaigns live in your HuggerHustle account.

= Can I use it on a multisite? =

Activate it per site. Each site keeps its own key, so each site's campaigns stay separate.

== Screenshots ==

1. The dashboard: your balance, your campaigns, and the businesses waiting for approval.
2. The introduction written for one business.
3. Settings: the API key.

== Changelog ==

= 0.1.0 =
* First version: connect a site, start a campaign from it, and approve the businesses waiting for you.
