=== Pinpointe Form Integration ===
Contributors: pinpointe
Tags: email marketing, forms, opt-in, mailing list, subscription
Requires at least: 3.5
Tested up to: 6.5.2
Stable tag: 1.7
Requires PHP: 5.4
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Add Pinpointe email marketing forms to your WordPress site

== Description ==
Pinpointe is a feature-rich, cloud-based email marketing software solution for B2B companies. Pinpointe's award-winning system brings "big-business" features, services and automation capabilities to small and mid-size B2B email marketing customers at an affordable price. For large volume customers sending from 1 million to 20 million emails / month and beyond, Pinpointe's Enterprise Edition provides scalable dedicated infrastructure.

The Pinpointe Form Integration plugin for WordPress lets you easily add dynamic forms to your site or blog. Advanced features include:

* Display forms on home page, pages, posts and in other WordPress content 
* Show popups and customize how often they appear
* Users can submit their info without ever leaving your pages (AJAX)
* Create a library of forms for any number of Pinpointe mailing lists
* Advanced rules for selecting where to display different forms
* Use Pinpointe custom fields to save detailed customer data
* Automatically add a checkbox to signup for your mailing list on comment forms
* Choose from 9 different color palettes
* Optionally customize the look of your forms using CSS

This plugin is easy for non-experts to use but gives power users a wide array of tools for getting the most out of WordPress and Pinpointe. Increase the return on investment on your marketing campaigns by using the Pinpointe Form Integration plugin for WordPress.

NOTE: Forms created with this plugin are not the same as forms created via the Pinpointe web application. Forms created with this plugin are specifically built for WordPress integration and exist within your WordPress instance. Forms are not editable from the Pinpointe web application.

== Installation ==
If you have downloaded this plugin, you can use the built-in WordPress plugin installer.

1.  Log into your WordPress site as an administrator.
2.  Click Plugins on the left menu bar.
3.  Click Add New.
4.  Click Upload Plugin.
5.  Click Choose File and select the plugin\'s .zip file .
6.  Click Install Now, then follow the instructions for activating this plugin.

== Frequently Asked Questions ==
Please visit our official site for the latest information:

https://help.pinpointe.com/support/solutions/5000163177

== Known Issues ==
* The custom field dropdowns do not correctly display icons in the dropdown list when not on a Chromium-based browser.

== Dependencies ==
This plugin use the following dependencies:
1. Font awesome 6 - Provides beautiful icons [https://fontawesome.com/icons]
2. HTML5 Shiv - Useful for old browsers that may not support HTML5 [https://github.com/aFarkas/html5shiv]
3. Jquery Placeholder - Useful for old browsers, e.g. IE to support placeholders in form fields [https://github.com/mathiasbynens/jquery-placeholder]
4. JQuery Form - Allows you to easily and unobtrusively upgrade HTML forms to use AJAX [https://github.com/jquery-form/form]
5. JQuery Validate - Provides drop-in validation for your existing forms [https://github.com/jquery-validation/jquery-validation]
6. Tom Select - Provides native <select> element with more powerful options, simply a <select> on steroids. [https://github.com/orchidjs/tom-select]
7. JS Cookie - A simple JS API for handling browser cookies [https://github.com/js-cookie/js-cookie]


== Changelog ==
1.7
* Replace deprecated Jquery chosen plugin with Tom Select.
* Update Font Awesome 4 to Font Awesome 6
* Fixed undefined method get_error_message() on array when making a request using wp_remote_post
* Fixed popup modal not showing on frontend pages.
* Upgrade dependencies to their latest versions.
* Add nonce to all ajax handlers.
* Ensure a mailing list is selected on both admin and frontend

1.6
* Added ability to display multiple mailing lists on the subscription form
* Added option to override default pinpointe confirmation email sent to the subscriber upon form submission
* Fixed bugs related to PHP deprecations for PHP 8.0 and PHP 8.2
* Tested with WordPress v6.4.2

1.5
* Fixed an issue where not passing a list (tag) would cause the updated Pinpointe API to detect an error with the XML structure
* Fixed layout issue with inline dropdown arrow icon next to form names
* Fixed missing FontAwesome icons due to http call (missing "s")
* Tested with WordPress v5.7.2

1.4.1
* Tested with WordPress v5.6

1.4
* Changed language of "Lists" and "Tags" to "Databases" and "Lists", to be consistent with the Pinpointe web application

1.3
* Added option to send a confirmation request email to the subscriber upon form submission
* Fixed a bug where 'Update existing subscribers' didn't update them
* Being generally more awesome (and tested with WordPress v4.9.7)

1.2
* Tag lists can now be added to a form so when someone subscribes, they are added to that List in your Pinpointe account
* Improved the display of tooltips within the settings pages
* Added basic bot/spam deterrents to form processing
* Further isolated custom JQuery and CSS code
* Updated form submissions to use POST rather than GET
* Custom fields now support special characters, such as Latin characters
* Custom fields now support radio and checkboxes

1.1
* Streamlined workflow for so new users can create forms more intuitively

1.0
* Initial release
