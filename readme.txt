=== WP OTP - One-time password (OTP) 2FA for WordPress ===
Contributors: santerref
Tags: sms, otp, 2fa, listing, wordpress, rest, api, security, authentication, two-factor, safe
Requires at least: 5.9
Tested up to: 6.1
Stable tag: 1.0.1
Requires PHP: 7.4
License: GPLv2 or later

Two-factor authentication for WordPress (SMS 2FA): A small plugin that's easy to use that let you add a second layer of protection in addition to your password.

== Description ==

Improve the security of your WordPress by using the One-time Password feature of WP OTP: [https://wpotp.com/](https://wpotp.com/)

WP OTP is an external service and you must create an account (which can be created from WordPress) to use the plugin. You only have to provide an email and a password.

Once the plugin is configured, an SMS will be sent to you at your next logins to authenticate yourself.

By adding this extra layer of security, it's much harder for hackers to break into your accounts.

= User-specific =

Each user can have their own number and the 2FA feature does not have to be enabled for all users.

The process to assign a number to a user is very simple and is done in 2 steps.

= Plugin features =

* Canada and United States phone numbers only
* Free 25 SMS when registering to try our plugin ($0.25)
* Unlimited WordPress sites per account
* Easily add funds into your account: $0.01 per SMS (minimum  $5.00)
* Each user can have his own phone number
* Free support and live chat
* Simple UI/UX
* Easy to use and setup

= DEMO =

[youtube https://youtu.be/sIWX-0HcENI]

= Documentation =

[https://wpotp.com/documentation](https://wpotp.com/documentation)

= Coming soon =

* More supported countries

== Frequently Asked Questions ==

= Is there hooks or filters? =

Only one hook (action) is available when a user has successfully logged in: wpotp_authentication_successful

= How balance is synced? =

There is a cron job that is running daily and we update the total when you log in.

= How to bypass 2FA if I have no more funds? =

You simply have to edit your wp-config.php file and define the following constant to true: WPOTP_DISABLED

== Installation ==

1. Extract `wpotp.zip` to the `/wp-content/plugins/` directory
1. Activate the plugin through the 'Plugins' menu in WordPress
1. Create your account on the configuration page
1. Link your phone number in your user profile

== Screenshots ==

1. Create account or login
2. Add phone number to a user
3. Two-factor authentication
4. Account balance and email

== Changelog ==

= 1.0.1 =
Remove unsafe filters for SSL when using WP_DEBUG.

= 1.0.0 =
First stable version.
