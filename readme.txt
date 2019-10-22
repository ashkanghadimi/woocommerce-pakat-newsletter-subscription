=== WooCommerce - SendinBlue Add-on ===
Contributors: neeraj_slit
Tags: woocommerce email, woocommerce SMS, woocommerce text message, woocommerce sendinblue, ecommerce email confirmation, ecommerce sms confirmation, ecommerce statistics, woocommerce Add-on, confirmation emails, confirmation text message, email autoresponder 
Requires at least: 4.3.1
Tested up to: 5.2.2
Stable tag: trunk
License: GNU General Public License v2.0 or later

The all-in-one marketing add-on for WooCommerce users.
Design, send and track automatic emails and text messages for customer communications.

== Description ==

> <strong>Why is email marketing so important for WooCommerce users?</strong><br>
> WooCommerce includes limited confirmation email functionality, however these emails are not easily modified or monitored. Use the SendinBlue add-on to deploy effective email and SMS campaigns, improve email deliverability, and track detailed metrics including delivery, open and click rates. 

= TRANSACTIONAL MESSAGING = 
= Confirmation Emails - Design =
Use the SendinBlue add-on to populate WooCommerce order variables directly within your SendinBlue email templates. SendinBlue's responsive design tools make it easy to create email templates that are engaging and include the most important order details.
= Confirmation Emails - Delivery =
The SendinBlue add-on allows you to use professional SMTP to send your WooCommerce confirmation emails. Optimized deliverability ensures your confirmation emails reach the inbox. 

= Confirmation Emails - Reporting =
Get up-to-the-minute analytics on your message deliverability and engagement metrics. Choose a custom time period and review statistics for each email template (order receipt, shipping confirmation, etc.) including the number of emails sent, and delivery, open and click rates. Additional detailed reporting is accessible within the SendinBlue online account dashboard. 

= Confirmation Text Messages =
Text messages (SMS) are powerful relationship building tools. The SendinBlue add-on allows you to send confirmation SMS triggered by specific events in your customer’s order life cycle, such as order confirmation, order shipment and order delivery. Customize messages with your customer’s first name, last name, order price or order date. Full SMS reporting is available within the SendinBlue online account dashboard. 


= MARKETING CAMPAIGNS = 

= Subscription Options =
You can choose whether to display an opt-in field on checkout. If enabled, opt-in customers will be added to the selected list after order creation or order completion. Customize opt-in settings, such as the opt-in field description (e.g. “Send me monthly updates and deals!”) and whether the checkbox is checked by default. You can also activate the "Double Opt-in" feature to invite customers to confirm their subscription by clicking a link in an automated email. 

= SMS & Email Campaigns =
You can send a SMS message directly from WooCommerce settings to all of your customers or all of your subscribers. You can personalize the SMS with dynamic information and test your campaign by sending a test SMS. Please login to your SendinBlue online account dashboard to send email campaigns. 


= FULL FEATURE LIST =
* Send confirmation emails with optimized deliverability
* Use WooCommerce order variables directly within your SendinBlue email templates
* Monitor the most important email metrics: delivery, open and click rates
* Enable and manage customer subscriptions: opt-out, opt-in or double opt-in after order creation or completion
* Order tracking: transactional data (order ID, price, etc.) is saved in SendinBlue to enable powerful segmentation
* Create and send confirmation text messages after key events, such as a new order or order shipment
* Send text messages campaigns to all customers or subscribers


= Credits =
This plugin was created by <a href="http://www.sendinblue.com" title="SendinBlue">SendinBlue</a>.


== Installation ==

1. Install the SendinBlue - WooCommerce Add-on either via the WordPress.org plugin repository or by uploading the files to your server.
2. Activate the SendinBlue - WooCommerce Add-on from the Plugins tab - Installed Plugins.
3. Navigate to WooCommerce Settings - you will see "SendinBlue" next to the API tab. Follow the instructions on the homepage to create a SendinBlue account and enter your API key. 

== Frequently Asked Questions ==
= What is SendinBlue? =
SendinBlue empowers businesses to build and grow relationships through marketing campaigns, transactional messaging and marketing automation. Our goal is to provide the most simple, reliable and cost-effective marketing tools for growing businesses. SendinBlue is available in 6 languages: English, Spanish, French, German, Italian and Portuguese.

= Why use SendinBlue as an SMTP relay for my WooCommerce confirmation emails? =
By using SendinBlue’s SMTP, you will avoid the risk of having your legitimate emails ending up in the spam folder. You will also have access to detailed reporting for emails sent: deliverability, opens, clicks, etc. SendinBlue’s proprietary infrastructure optimizes your deliverability and lets you focus on the content of your emails.

= Why do I need a SendinBlue account? =
The SendinBlue - WooCommerce Add-on uses SendinBlue’s API to synchronize contacts, send emails or get statistics. Creating an account on SendinBlue is free and takes less than 2 minutes. Once logged in your account, you can get the API key from the Settings page.
 
= Do I have to pay to use the plugin and send emails? =
No, the plugin is totally free and SendinBlue offers a free plan with 9,000 emails per month. If you need to send more than 9,000 emails / month, we invite you to explore our <a href="https://www.sendinblue.com/pricing/" target="_blank">pricing plans</a>. For example, the Micro plan is $7.37 / month and allows you to send up to 40,000 emails per month. SendinBlue plans do not require a contract and can be cancelled or updated at any time. 

= How do I synchronize my lists? =
Once you have enabled synchronization from the “Subscription Options” tab, the process is automatic. It doesn't matter whether your contact lists are uploaded on your WordPress interface or on your SendinBlue account: they will always remain up-to-date on both sides.

= How can I receive support? =
If you need some assistance, please post an issue in the “Support” tab or send us an email at contact@sendinblue.com.


== Screenshots ==
1. After entering your SendinBlue API key, you are logged in and statistics appear on the SendinBlue Add-on homepage. 
2. When subscription is enabled, all of your customers will automatically be added in one list. 
3. Enable SendinBlue to send WooCommerce emails to get reliable deliverability and complete reporting. You can even replace WooCommerce emails with custom SendinBlue templates.
4. You can choose to send a confirmation SMS for order confirmations or order shipments.
5. You can send SMS campaigns to all your customers in just a few clicks. 


== Changelog ==

= 1.1.0 =
* Improved transactional email
* Improved SMS campaign

= 1.1.1 =
* Updated descriptions

= 1.1.2 =
* Fix some warning issues
* Updated SMS credit notification

= 1.1.3 =
* Fix SMTP issue using wp_mail
* Fix to send transactional email

= 1.1.4 =
* Fix transactional email issue

= 1.1.5 =
* Fix a save change button problem since version 2.5
* Fix incorrect sender detail

= 1.1.6 =
* Fix warning issue by error_log
* Fix attachment issue in transactional email

= 1.1.7 =
* Update to use all Woocommerce variables in templates
* Fix Statistics warning issue
* Update Double Opt-in procedure
* Udpate transactional attributes of existing customer

= 1.1.8 =
* Fix warning issue to send SMS

= 1.1.9 =
* Apply nl2br on text/plain only
* Fix set_magic_quotes_runtime() error
* Fix some warning issue

= 1.1.10 =
* Fix warning issue by WP_Error
* Fix jquery issue in admin page

= 1.2.0 =
* Add new feature to sync old your customers to the desired list
* Add French language
* Fix transient error
* Fix UI issue by h2 tag
* Change content of test sms

= 1.2.1 =
* Add a variable {ORDER_DOWNLOAD_LINK} for product link
* Add new feature to match customers attributes and sendinblue list attributes
* Use wordpress function for CURL request

= 1.2.2 =
* Fix warning to select multi-list in sync users feature

= 1.2.3 =
* Add a variable {ORDER_PRODUCTS} for order products

= 1.2.4 =
* Fix fatal error in preview email template

= 1.2.5 =
* add more email templates
* fix some issues appeared on wp multisite
* fix compatibility issue with woocommerce 3.0 and above

= 1.2.6 =
* fix an error on product page

= 1.2.7 =
* add independence between SendinBlue plugins

= 1.2.8 =
* remove unnecessary text

= 1.2.9 =
* change the position of Opt-In Field at Checkout
* fix products variation price issue
* add new variables {USER_LOGIN} and {USER_PASSWORD} for New Account email template
* add new variable {REFUNDED_AMOUNT} for refunded order email template

= 1.2.10 =
* update for compatible with woocommerce 3.4.4
* fix to display account info issue
* fix order date format issue

= 1.2.11 =
* Double opt-in compatibility with NTL

= 1.2.12 =
* The plugin now includes an abandoned cart tracking feature.
* Once the feature is activated in the plugin, clients only have to set up their workflow.
* Without any technical implementation - using the detailed abandoned cart template.

= 1.2.13 =
* Abandoned cart tracking feature issue fixed.

= 1.2.14 =
* Save button display issue fixed.

= 1.2.15 =
* added condition for check attribute value.

= 1.2.16 =
* added condition for check ma script and abaondoned cart function.

