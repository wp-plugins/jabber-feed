=== Jabber Feed ===

Contributors: Jehan Hysseo
Donate link: http://jehan.zemarmot.net/blog/jabber-feed/
Tags: jabber, xmpp, pubsub, xep-0060, notification, feed
requires at least: 2.0
Tested up to: 2.5
Stable tag: 0.2

This plugin feeds Jabber server pubsub nodes when new posts are published on
Wordpress and comments are added.

== Description ==

This plugin enables the blog owner to publish their posts on a xmpp pubsub
node. This way, anyone subscribed to this same node will be notified through
Jabber when a new post is published.

It enables also to do the same with comments on separated xmpp pubsub nodes, so
that readers can also subscribe and get notified about new comments of a
specific post.

Note: this is a early version, you can try it if you are interested (there can
be no harm! ;-), but I will provide far better versions progressively...

= Detailed Features =

* Posts are published on the subnode posts/ of the given pubsub node;
* Comments are published on the subnode comments/<id> with <id> being the id
of the corresponding post;
* Posts, as well as comments, are deleted automatically from the associated
pubsub nodes if you delete them, disapprove them, or flag them as spam from the Wordpress website;
* Posts, as well as comments, are updated automatically on the associated
pubsub nodes if you edit them on your Wordpress website;
* Autodiscover xmpp link for all posts is automatically set on all pages,
except single posts (if the 'wp_head' function is used by your theme, which is
the common procedure);
* Autodiscover xmpp link for comments of the current post is automatically set
on each single post (if the 'wp_head' function is used by your theme, which is
the common procedure);
* 2 templates functions for returning or displaying different xmpp links are
provided for your themes.

== Installation ==

1. Upload the plugin archive to wp-content/plugins/ directory on your Wordpress installation;
2. Uncompress it by keeping it in its own sub-directory called jabber_feed/;
3. Activate the plugin through the 'Plugins' menu in Wordpress;
4. Configure the plugin through the appearing sub-menu 'Jabber Feed configuration' under the 'Plugins' menu.
5. When aknowledging the configuration by pressing the "Update" button, the
jabber login will be tested and the pubsub nodes created. Hence if anything is
wrong with your configuration, you will be immediately informed.
6. This plugin also defines two templates:
* `jabber_feed_get ($node = 'posts', $what = 'url', $text = '')` will return a string being a url (bare, or in a 'a' or 'link' tag).
* `jabber_feed_display ($node = 'posts', $what = 'url', $text = '')` is the same as the previous template but display the string instead of returning it!
These template functions can be used in your theme.
Note that if your theme uses the 'wp_head' function in its header (most cases), a autodiscovery link on the post node will be automatically generated in the header. Otherwise, you can add it by yourself with these functions for instance.

= dependencies =

* PHP must be built with the option `--enable-sockets` on PHP 4 >= 4.0.7 or PHP 5 (though I haven't tried with such a version, with PHP 5.3.0 and more, this extension is moved to the PECL repository!). If you have an error relating to socket, this is most likely a dependency issue.
Note for gentoo users: you must set the 'sockets' USE flag.

* It uses the library 'expat' to parse XML (enabled with the '--with-xml'
option of the php compilation).
Note for gentoo users: you must set the 'xml' USE flag.

* It uses the 'Auth_SASL PEAR' module ("pear install Auth_SASL" or install with your distribution's package manager) for SASL authentication. For now, this dependency is mandatory because this is the only supported authentication mechanism. Maybe in some future will it become optional.

= Working Platforms =

This script has been tested only currently on Wordpress 2.0 and Wordpres 2.5 with PHP 5.2.1.
Hopefully it should work with other software versions (even PHP4), but I cannot guarantee.
Tell me please if you tried this successfully with another configuration so that I update the known working platforms list.

At the opposite, if you find a bug or encounter an issue on some configuration, don't hesitate to tell me, and I will try and fix it.

= Examples for using the function templates =

`jabber_feed_get` and `jabber_feed_display` have the same parameters, but the
first returns the link whereas the latter displays it:

1. `jabber_feed_get ($node = 'posts', $what = 'url', $text = '')`
2. `jabber_feed_display ($node = 'posts', $what = 'url', $text = '')`

* 'node' can be 'all', 'posts', 'pages', 'comments', 'current' (comments of
the current page/post) or a number (comments of the given numbered post/page);
* 'what' can be the bare 'url', or included in a 'a' tag, or a 'link' tag;
* 'text' is optional displayed text (only when 'a' tag).

So for instance, if the pubsub server is 'pubsub.jabber.org' and the node is
'blog':
* `jabber_feed_display ('posts', 'bare')` displays simply:

	`xmpp:pubsub.jabber.org?action=subscribe;node=blog/posts`

which is a bare url of the node containing all the posts.
* `jabber_feed_get ('comments', 'a', 'All the comments')` will return:

	`<a rel='alternate'
	href='xmpp:pubsub.jabber.org?action=subscribe;node=blog/comments;subscription_type=items;subscription_depth=1'>All
	the comments</a>`

which is a link for all comments.
* `jabber_feed_display (5, 'link')` will display:
	
	`<link rel='alternate'
	href='xmpp:pubsub.jabber.org?action=subscribe;node=blog/comments/5' />`

which is an autodiscovery link for the comments of post 5.

Etc.

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

* the Jabber server (often the same as 'myseveraddress' of the jid);
* the Jabber port (usually 5222).

= PubSub configuration =

Where to publish the notifications. It can be on a separate server.

* the pubsub server;
* the root publication node.

Note that this node does not have to exist. When you will press the "Update"
button, the providden Jabber login and connection parameters will be tested
and the node will be created with all its tree. If anything goes wrong, you
will be informed about it.

== Manage Posts ==

The page with the list of published posts have been modified. A new column
called "Jabber Feed" will display:
* The publication date on the node (and the last update will appear on a
bubble when the mouse passes by);
* 'Error on publication' when an error has occured during publication (and the
error text will be displayed when the mouse passes by);
* 'Not Published' when no publication never occurred (which is simply when the post has
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

= From myself: which jabber server has a good pubsub implementation? =

I am using ejabberd 2.0 and 2.0.1 for my own, but it is pretty flawed on many ways.
Especially it is impossible to configure your subscription to a node. What a
shame! :-(
But especially it is apparently impossible in the last current version to
subscribe to a node on an outside server.
Anyway it is good enough basically for the proof of concept.

I don't know sufficiently others server softwares to give an opinion about
them.

== Screenshots ==

1. The configuration page;
2. The modified post management page;
3. The templates in practice: I added the code `Subscribe to the Jabber feeds:
<?php jabber_feed_display ('posts', 'a', "Publication jabber"); ?> and
<?php jabber_feed_display ('comments', 'a'); ?>` to the footer.php of the
theme (here default).
4. The templates in practice again: I added `<?php jabber_feed_display
('current', 'a', 'Jabber') ?>` in the single.php file of the theme (between
the p tag of class 'postmetadata alt' in the default theme).

== Todo ==

* Manage menu: with failed publication, it should be possible to retry the
 publication from this page (with multiple checkbox if possible to massively
 run publication!).

* The item should also be updated when a published post is edited.

* Why not add support to pages? -> current work.

* Create the bot account as well?

* Why not retry to publish after a timer (let's say 1 day?) after a failure,
 with a number maximum of tries?

* What could be fun: if the subscribers could be also publisher, hence
answering to a post by directly publishing to the node?
But how is a publisher identified?! No "from" with a pseudo in it, and data
about real jid accessible from admin.

* Add xmtml-im support for the messages sent. Anyway improve globally the
message syntax (for instance the message 'updated' and 'created' tags).

* Configure the node instead of simply create it with default configuration.

* Check the node authorizations if it is already existing.

* Improve the internationalization strings (and set at least 2 default
languages: English and French).

* And what if the post or page is private? Should it be added on a node? Maybe
a node with a white list that the admin can manage?

== Contacts ==

You can have some news about this plugin on [my freedom haven](http://jehan.zemarmot.net "my public diary") or of course, soon by subscribing on a xmpp pubsub node.
If you want to contact me by jabber, ask me first by email (which you can find
on my website. Yes I know, this is complicated: but I like my tranquility, so
I make a filter).

Have a nice life!
