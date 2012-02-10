=== No More Passwords ===
Contributors: jackreichert
Tags: Login, QR Code, Password
Requires at least: 3.3
Tested up to: 3.3.1
Stable tag: trunk

Log into your WordPress site using a smartphone... No typing and no passwords! (almost)

== Description ==

With this plugin you can make passwords a thing of the past*. All you need is your trusty smartphone with a QR Code reading app.

*So you need to log in on your phone browser, but how often do you clear those browser cookies?
 
You’re on the go and the most brilliant idea for your blog hits you. You KNOW that if you don’t post it NOW it won’t be BRILLIANT anymore.
The problem is that you’re out visiting your Aunt Sally and
Your password is so secure even YOU can’t remember it off the top of your head
Aunt Sally has so many junk apps that there are most probably keyloggers installed, and there goes your secure password.
That’s where No More Passwords comes in handy. Log into your blog, keyboard — and remembering — free!

Disclaimer: This plugin is fairly secure and has been audited by several security experts, but the user should know that they are using it at their own discretion.

== Installation ==

1. Upload plugin to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. That's it

= Usage =

1. Scan QR code on login screen of your site.
2. Open link scanned in your mobile browser.
3. That's it! (If you don't have a cookie on your mobile browser recognizing you, the first time you try this you'll have to log in on your phone. After that you should be home free)

== Frequently Asked Questions ==

= Why do I need to log in on my phone? =

You wouldn't want just ANYONE being able to access your site. Verification is still necessary.

= So what's this plugin good for? =

Once you log in once, you won't have to again until your phone cookie runs out (every two weeks or so). That should save you SOME hassle.

= What about foo bar? =

I have no answer to foo bar dilemma.

== Screenshots ==

1. This is how your login page will look all pimped out with it's QR code.

== Changelog ==
= 1.0 =
* Out of Beta.
* IP confirmation fixed.

= 0.6 = 
* XSS fix. Special thanks to Julio from [Boiteaweb.fr](http://Boiteaweb.fr/) for his security analysis and recommendations

= 0.5 = 
* Delay added to prevent dDos attack

= 0.4 =
* CSRF fix. Special thanks to Julio from [Boiteaweb.fr](http://Boiteaweb.fr/) for his security analysis and recommendations
* AJAX, Cron jobs optimized

= 0.3 =
* $wpdb->prepare added to db queries. Special thanks to [scribu](http://wordpress.stackexchange.com/users/205/scribu)

= 0.2 =
* nonce added. 
* get_userdatabylogin updated to get_user_by. Special thanks to [ericktedeschi](http://wordpress.org/support/profile/ericktedeschi)

= 0.1.1 =
* Fixed to work in subdirectory installs of wp. Special thanks to [hlcws](http://wordpress.org/support/profile/hlcws).

= 0.1 =
* First attempt
