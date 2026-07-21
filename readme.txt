=== BrainStudioz MailPilot ===
Contributors: brainstudioz
Tags: newsletter, email marketing, mailchimp, subscriber management, lead generation
Requires at least: 6.8
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Build newsletter signup forms, manage subscribers, and sync contacts with Mailchimp, Brevo, MailerLite, Kit, and other email marketing services.

== Description ==

BrainStudioz MailPilot helps you build your email list, manage subscribers, and synchronize contacts with popular email marketing services from one WordPress dashboard. Create newsletter signup forms, store subscribers in your own WordPress database, and connect services such as Mailchimp, Brevo, MailerLite, Kit (ConvertKit), ActiveCampaign, and more.

Many newsletter integrations connect a form directly to one email service. MailPilot instead stores subscribers locally and synchronizes them with one or more supported providers, allowing you to add or switch services without rebuilding your signup forms.

It works with the tools you already have. Capture subscribers from WordPress comments and registration, and from popular form plugins including Contact Form 7, WPForms, Gravity Forms, Ninja Forms, Fluent Forms, Formidable Forms, Elementor Forms, and JetFormBuilder.

## Features

* Local newsletter subscriber database in WordPress
* Newsletter signup forms (drag-and-drop builder)
* Supports Mailchimp
* Supports Brevo
* Supports MailerLite
* Supports Kit (ConvertKit)
* Supports ActiveCampaign
* Supports GetResponse, AWeber, Campaign Monitor, Drip, and Constant Contact
* Subscriber tags and per-provider list mapping
* WordPress form plugin integrations (Contact Form 7, WPForms, Gravity Forms, Elementor Forms, and more)
* Background synchronization with automatic retries
* Consent recording and suppression handling
* CSV import and export
* WordPress REST API and developer hooks

## Perfect for

* Bloggers and publishers
* WooCommerce stores
* Small businesses
* Membership sites
* Course creators
* Agencies and freelancers
* Nonprofits
* SaaS companies

## How it works

Visitor signs up
&rarr; MailPilot stores the subscriber in your WordPress database
&rarr; MailPilot queues a background sync
&rarr; Your email provider is updated automatically

## What the free plugin does

* **Local subscriber database.** Every signup is stored in your own WordPress tables as the source of truth — searchable, filterable, and exportable to CSV.
* **Newsletter signup forms.** Build subscription forms with a drag-and-drop builder and place them via a block, an Elementor widget, a shortcode, or a PHP function. Inline, floating-bar, and slide-in display types are included.
* **10 email/CRM providers.** Connect Mailchimp, Brevo, MailerLite, Kit (ConvertKit), ActiveCampaign, GetResponse, AWeber, Campaign Monitor, Drip, and Constant Contact. Select lists or groups, map custom fields, apply tags, and enable double opt-in per connection. Multiple accounts of the same provider are supported.
* **Consent and suppression.** Record consent with a consent field, honor subscriber status everywhere, and never sync unsubscribed, bounced, or blocked contacts to any provider.
* **Reliable background synchronization.** Outgoing syncs run through a background processing queue with automatic retries to help avoid API timeouts and rate-limit failures, so slow or busy provider APIs never block your visitors.
* **Capture from the plugins you already use.** Built-in capture from WordPress comments and registration, plus Contact Form 7, WPForms, Gravity Forms, Ninja Forms, Fluent Forms, Formidable Forms, Elementor Forms, and JetFormBuilder.
* **Import and export.** Import existing subscribers from a CSV file and export your audience anytime, with bulk actions in the subscriber list.
* **Basic analytics.** Track subscribers, form views, submissions, and conversion rate.

## Designed for developers

MailPilot is built to be extensible. Developers can add custom providers, create new capture sources, and extend functionality using the WordPress REST API, PHP API, hooks, and provider registries.

* WordPress REST API
* PHP API
* Action hooks
* Filter hooks
* Provider registry (add your own providers)
* Integration registry (add your own capture sources)
* Background queue
* Extensible, namespaced architecture

Provider API keys are encrypted at rest. MailPilot contacts a provider only after you add a connection with your own credentials — see the External Services section below.

## MailPilot Pro (optional add-on)

MailPilot Pro is a separate, optional add-on that extends the free plugin with audience routing rules, premium providers, WooCommerce order tracking, popups and lead-magnet delivery, automations and webhooks, AI-powered writing and optimization tools, and provider-to-provider migration. The free plugin works independently; MailPilot Pro simply adds more.

== Privacy ==

MailPilot stores subscriber data in your own WordPress database. It collects no telemetry, sends no usage analytics, and makes no external requests until you configure a provider connection with your own API credentials. No data is ever sent to the plugin author.

== Privacy by Design ==

* Stores subscribers locally in WordPress
* No telemetry
* No usage analytics
* No external requests until you configure a provider
* Stores configured provider credentials in encrypted form

== Requirements ==

* WordPress 6.8 or later
* PHP 8.1 or later
* HTTPS recommended for provider connections

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/mailpilot` directory, or install the plugin through the Plugins screen in WordPress.
2. Activate the plugin through the Plugins screen.
3. Open the **MailPilot** menu, go to **Providers**, and connect an email or CRM service with your own API key.
4. Go to **Forms** to build a signup form and place it on your site, or enable a capture integration under **Integrations**.

== Frequently Asked Questions ==

= Does MailPilot send my data anywhere by default? =

No. MailPilot sends nothing until you add a connection with your own API credentials for a specific provider. It does not send any data to the plugin author and collects no telemetry. See the External Services section for details.

= Where are subscribers stored? =

Subscribers are stored in your WordPress database. MailPilot synchronizes them with supported providers only after you configure a connection.

= Does MailPilot require an external account? =

No. The plugin is fully usable on its own to build forms and store subscribers in your WordPress database. You only need an external account (such as Mailchimp) if you want to sync your contacts to that service.

= Which email services can I connect for free? =

Mailchimp, Brevo, MailerLite, Kit (ConvertKit), ActiveCampaign, GetResponse, AWeber, Campaign Monitor, Drip, and Constant Contact.

= Can I connect multiple providers at once? =

Yes. You can connect several providers and route the same subscriber to more than one.

= Can I connect multiple Mailchimp accounts? =

Yes. MailPilot supports multiple accounts of the same provider — for example, two Mailchimp connections — each with its own list and field mapping.

= Does MailPilot support Elementor? =

Yes. MailPilot includes an Elementor widget for its form builder and can capture submissions from Elementor Forms.

= Does MailPilot work with WooCommerce? =

The free plugin captures subscribers from WordPress core and the supported form plugins. WooCommerce order tracking is available in the optional MailPilot Pro add-on.

= Can I import subscribers? =

Yes. You can import existing subscribers from a CSV file, including tags and list membership.

= Can I export subscribers? =

Yes. You can export your subscriber database to CSV at any time.

= Does MailPilot include consent and privacy tools? =

Yes. MailPilot includes a consent field, consent recording, subscriber status handling, and a suppression list. These tools can support your site's privacy workflows, but using them does not by itself guarantee compliance with any law or regulation.

= Will switching providers affect my forms? =

No. Your forms capture into MailPilot's subscriber database, so you can add or switch providers without rebuilding your forms.

= Can developers extend MailPilot? =

Yes. MailPilot offers a WordPress REST API, a PHP API, action and filter hooks, and provider/integration registries to add your own providers and capture sources.

= What is MailPilot Pro? =

An optional, separately installed add-on that unlocks advanced features such as routing rules, premium providers, WooCommerce tracking, and automations. The free plugin works independently and does not require the add-on.

== External Services ==

MailPilot does not contact any external service on its own and sends no data anywhere by default. It transmits data to a third-party service only after you add a connection for that service with your own API credentials. When you do, and a sync runs, the subscriber data you have collected — typically email address, name, any custom fields you map, tags, and list membership — is sent to that provider's API so your contacts stay in sync. No data is sent to the plugin author, and no usage tracking or telemetry is collected.

Each integration below is optional and inactive until you connect it:

* Mailchimp — sends contact data to the Mailchimp API (https://{data-center}.api.mailchimp.com).
  Terms: https://mailchimp.com/legal/terms/ — Privacy: https://www.intuit.com/privacy/statement/
* ActiveCampaign — sends contact data to your ActiveCampaign account API URL.
  Terms: https://www.activecampaign.com/legal/terms — Privacy: https://www.activecampaign.com/legal/privacy-policy
* Brevo — sends contact data to the Brevo API (https://api.brevo.com).
  Terms: https://www.brevo.com/legal/termsofuse/ — Privacy: https://www.brevo.com/legal/privacypolicy/
* MailerLite — sends contact data to the MailerLite API (https://connect.mailerlite.com).
  Terms: https://www.mailerlite.com/terms-of-service — Privacy: https://www.mailerlite.com/legal/privacy-policy
* Kit (formerly ConvertKit) — sends contact data to the Kit API (https://api.kit.com).
  Terms: https://kit.com/terms — Privacy: https://kit.com/privacy
* AWeber — sends contact data to the AWeber API (https://api.aweber.com).
  Terms: https://www.aweber.com/tos.htm — Privacy: https://www.aweber.com/privacy.htm
* Campaign Monitor — sends contact data to the Campaign Monitor API (https://api.createsend.com).
  Terms: https://www.campaignmonitor.com/terms/ — Privacy: https://www.campaignmonitor.com/policies/
* Constant Contact — sends contact data to the Constant Contact API (https://api.cc.email).
  Terms: https://www.constantcontact.com/legal/service-provider — Privacy: https://www.constantcontact.com/legal/privacy-center
* Drip — sends contact data to the Drip API (https://api.getdrip.com).
  Terms: https://www.drip.com/terms — Privacy: https://www.drip.com/privacy
* GetResponse — sends contact data to the GetResponse API (https://api.getresponse.com).
  Terms: https://www.getresponse.com/legal/terms-conditions — Privacy: https://www.getresponse.com/legal/privacy

== Screenshots ==

1. Dashboard — an overview of your audience and recent activity.
2. Subscribers — your local WordPress subscriber database with search, filters, and CSV export.
3. Subscriber profile — personal details, provider connections, and activity timeline.
4. Form builder — build a newsletter signup form and place it anywhere on your site.
5. Providers — connect email and CRM platforms and select lists, tags, and field mappings.
6. Integrations — capture subscribers from the forms and plugins you already use.
7. Settings — configure defaults and options.
8. Analytics — subscribers, form views, submissions, and conversion rate.

== Changelog ==

= 1.0.0 =
* Initial release.
* Added subscriber engine and local subscriber database.
* Added drag-and-drop newsletter signup forms.
* Added 10 email/CRM provider integrations.
* Added multiple-account support per provider.
* Added consent and suppression handling.
* Added background synchronization with automatic retries.
* Added capture integrations for WordPress core and popular form plugins.
* Added CSV import and export.
* Added basic analytics.
* Added a WordPress REST API, PHP API, and developer hooks.

== Upgrade Notice ==

= 1.0.0 =
Initial release of MailPilot.
