=== Jabber Feed ===

Contributors: Jehan Hysseo
Donate link: http://jehan.zemarmot.net/blog/jabber-feed/
Tags: jabber, xmpp, pubsub, xep-0060, notification, feed
requires at least: 2.0
Tested up to: 2.5
Stable tag: 0.1

This plugin feeds Jabber server pubsub nodes when new posts are published on
Wordpress and comments are added.

== Description ==

This plugin enables the blog owner to publish their posts on a xmpp pubsub
node. This way, anyone subscribed to this same node will be notified through
Jabber when a new post is published.

It enables also to do the same with comments on another xmpp pubsub node, so
that readers can also subscribe and get notified about new comments
(you can subscribe to both but also separately either one or the other node).

Note: this is a first version, you can try it if you are interested (there can
be no harm! ;-), but I will soon provide far better versions...

== Installation ==

1. Upload the plugin archive to wp-content/plugins/ directory on your
Wordpress installation;
2. Uncompress it by keeping it in its own sub-directory called jabber_feed/;
3. Activate the plugin through the 'Plugins' menu in Wordpress;
4. Configure the plugin through the appearing sub-menu 'Jabber Feed
configuration' under the 'Plugins' menu.
5. This plugin also defines two templates:
* jabber_feed_get ($node = 'posts', $what = 'url', $text = '')
will return a string being a url (bare, or in a 'a' or 'link' tag).
and
* jabber_feed_display ($node = 'posts', $what = 'url', $text = '')
is the same as the previous template but display the string instead of
returning it!
This template function can be used in your theme.
Note that if your theme uses the 'wp_head' function in its header (most
cases), a autodiscovery link on the post node will be automatically generated
in the header.

== dependencies ==

* PHP must be built with the option '--enable-sockets' on PHP 4 >= 4.0.7 or PHP 5 (though I haven't tried with such a version, with PHP 5.3.0 and more, this extension is moved to the PECL repository!). If you have an error relating to socket, this is most likely a dependency issue.
 Note for gentoo users, you must set the sockets USE flag.

* It uses the library expat to parse XML.

* It uses the Auth_SASL PEAR module ("pear install Auth_SASL" or install with your distribution's package manager) for SASL authentication. For now, this dependency is mandatory because this is the only supported authentication mechanism. Maybe in some future will it become optional.

== Working Platforms ==

This script has been tested only currently on Wordpress 2.0 and Wordpres 2.5 with PHP 5.2.1.
Hopefully it should work with other software versions (even PHP4), but I cannot guarantee.
Yet if you find a bug or encounter an issue, don't hesitate to tell me, and I will try and fix it.

== Features ==

1. Automatically publish posts and/or comments on a Jabber pubsub node;
2. Add an autodiscovery of the posts node in your website header if your theme
uses the wp_head function;
3. Provide 2 templates to get or display addresses to your Jabber publication
nodes.

== Configuration ==

In the 'Jabber Feed configuration' menu, you will see the following sections:

= Publishing Account =

This section contains the connection parameters of the account which will be
used to publish the field. I would personnaly advice to create a new account
just for it (you can also use your personal account of course, anyway the plugin's
bot will create a resource identifier unique for every connection) and to
configure it to refuse any contact and communication (as noone will have to
add it to one's roster, except you maybe for test or debugging purpose?).
The fields are:

* The bot adress (full jid form: mybotname@myserveraddress);
* the password.

= Connection Parameters =

This is an advanced section in case your server uses a server which is not the
one shown on the jid or a port different from the default one (5222).
These are not mandatory fields. The default values will be used if empty.

* the Jabber server (Often the same as 'myseveraddress' of the jid);
* the Jabber port (usually 5222).

= The nodes =

Where to publish the notifications. It can be on a separate server.

== Manage Posts ==

This page with the list of published posts have been modified. A new column
called "Jabber Feed" will display:
* The publication date on the node and the name of the item.
* 'X' when an error has occured during publication.
* '-' when no publication never occurred (which is simply when the post has
 been published on Wordpress before the plugin has been installed.

== Frequently Asked Questions ==

= Question from myself: how people can subscribe on a Jabber node? =

Unfortunately this plugin is rather a "proof of concept" plugin as long as
there is no Jabber client allowing users to easily subscribe (and configure
their subscription) to xmpp pubsub nodes.
I could use Gajim to subscribe to nodes though, but I had much difficulty to
"browse" nodes with it, and it is impossible to create a node (at least I have
not found).
As for Psi, I could not do any subscription at all.

The only mean for the time being is to use a client with a XML console (the
only ones I know with such a console are Gajim, Psi, and Sameplace with an
official plugin) and send this raw XML subscription (which is indeed a non very
user-friendly method, I must admit).

It would be nice if people could implement soon a node subscription feature in
their favorite Jabber client (I will probably try to do so myself as a next
step) and also a relation in their favorite web-browser to Jabber links (so
that they could have their Jabber client propose them to subscribe and
configure a node just by clicking on a link on their browser).
Hopefully then I will update this readme to advice such Jabber client and web
browser! :-)

== Screenshots ==

1.
2.

== Todo ==

* Manage menu: with failed publication, it should be possible to retry the
 publication from this page (with multiple checkbox if possible to massively
 run publication!).

* The item should also be updated when a published post is edited.

* An item should be deleted from the node when the corresponding post is
 removed.

* Why not add support to pages?

* remove the node for post separated with the one for comments.
There should be one single node with a given tree inside: a leaf node for the
posts (people can subscribe to this one to get only the posts); a container
node for the comments (people can subscribe here to receive all comments) with
inside a new leaf node for the comments of every new post (people can
subscribe to a specific post once it has been published to get comments only
on this post).

node 	-> posts (leaf)
	-> comments (Collection)	-> post_1
					-> post_2
					-> post_3
					...

* Why not be able to create the node and configure it all from Wordpress?

* Create the bot account as well?

* Why not retry to publish after a timer (let's say 1 day?) after a failure,
 with a number maximum of tries?

== Contacts ==

You can contact me on Jabber at xmpp:hysseo [@] zemarmot.net and you can have some news about this plugin on [my freedom haven](http://jehan.zemarmot.net "my public diary") or of course, soon by subscribing on a xmpp pubsub node (I have configured one but I must make some finalization first).

Have a nice life!
