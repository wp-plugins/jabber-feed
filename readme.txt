=== Jabber Feed ===

Contributors: Jehan Hysseo
Donate link: http://jehan.zemarmot.net/blog/jabber-feed/
Tags: jabber, xmpp, pubsub, xep-0060, notification, feed, posts, comments
requires at least: 2.0
Tested up to: 2.9.1
Stable tag: 0.5

This plugin feeds Jabber server pubsub nodes when new posts are published on
Wordpress and comments are added.

== Description ==

This plugin enables the blog owner to publish their posts on a xmpp pubsub
node. This way, anyone subscribed to this same node will be notified through
Jabber when a new post is published.

It enables also to do the same with comments on separated xmpp pubsub nodes, so
that readers can also subscribe and get notified about new comments of a
specific post only if prefered.

Note: this is a early version, you can try it if you are interested (there can
be no harm! ;-), but I will provide far better versions progressively...

= Detailed Features =

* Connection with SRV records, TLS if available and known authentication
mechanisms (in this order of preference): Digest-MD5, CramMD5, PLAIN,
ANONYMOUS.
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
- `jabber_feed_get ($node = 'posts', $what = 'url', $text = '')` will return a string being a url (bare, or in a 'a' or 'link' tag).

- `jabber_feed_display ($node = 'posts', $what = 'url', $text = '')` is the same as the previous template but display the string instead of returning it!
These template functions can be used in your theme.
Note that if your theme uses the 'wp_head' function in its header (most cases), a autodiscovery link on the post node will be automatically generated in the header. Otherwise, you can add it by yourself with these functions for instance.

= dependencies =

* PHP must be built with the option `--enable-sockets` on PHP 4 >= 4.0.7 or PHP 5 (though I haven't tried with such a version, with PHP 5.3.0 and more, this extension is moved to the PECL repository!). If you have an error relating to socket, this is most likely a dependency issue.
Note for gentoo users: you must set the 'sockets' USE flag.

* It uses the library 'expat' to parse XML (enabled with the `--with-xml`
option of the php compilation).
Note for gentoo users: you must set the 'xml' USE flag.

* It uses the `Auth_SASL PEAR` module (`pear install Auth_SASL` or install with your distribution's package manager) for SASL authentication. For now, this dependency is mandatory because this is the only supported authentication mechanism. Maybe in some future will it become optional.

* OPTIONAL: to have the option of sending formated notification in XHTML, the "tidy" PECL extension must be installed.
It is based on the libtidy library which must first be installed: http://tidy.sourceforge.net/
Then with PHP 4.3.X or PHP 5, you can install it as a PECL module: `pecl install tidy`; or with the
`--with-tidy` compilation option in PHP 5.
Without this, you won't have access to the XHTML option and all notifications will be sent as a normal textual message.

* OPTIONAL: if the plugin is installed on a BSD (Mac included),
in order to use the SRV records on the admin JID, which is the correct way of resolving the server and port addresses for a domain, the PEAR extension NET_DNS must be installed: 'pear install NET_DNS' (Note that it will ask to have php compiled with 'mhash' option).
If it is installed on Windows, it is not anymore useful if you have PHP
5.3.0 or later installed (under this version of PHP, you should also install
this extension to benefit SRV records).
Linux servers do not need this extension to have SRV.
Note for gentoo users: you must set the 'mhash' USE flag.

* OPTIONAL: for more efficient resource use (in particular in order to avoid
 multiple and useless connection to the XMPP server when you could do only
 one), it is recommended to compile PHP with the semaphores system V: option
 --enable-sysvsem at compilation time.
 This is in particular recommended if your publication system has a lot of
 activity (new posts, pages, comments, deletions, etc.).
 Note for Gentoo users: you must set the 'sysvipc' USE flag for PHP.

= Working Platforms =

This script has been tested only currently on Wordpress 2.0 up to Wordpress
2.9.1 with PHP 5.2.1 to 5.2.11, running on a GNU/Linux 64 bits (Gentoo Linux).
Hopefully it should work with other software versions (not for PHP4, because
of the TLS feature with is PHP5 specific. Yet if you are really interested
into PHP4 compatibility and if TLS is not required for your connection, just
ask me, I will try to make a compatibility layer), but I cannot guarantee.
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

By default Jabber Feed can now use SRV records which is a recommended way to
advertize server and port from a domain name (see for instance
http://dns.vanrein.org/srv/ for details).

This is an advanced section in case your server does not use SRV AND uses a server
which is not the same as the domain from the jid or a port different from the default one (5222).

Hence there will be very very new cases where you will have to fill this
section and if you don't understand all what I say here, just don't fill
anything here (if you fill even only one field, then it will be used instead
of SRV and default values).

The default values will be used if fields empty and no SRV is configured on
the Jabber server:

* the Jabber server (often the same as 'myseveraddress' of the jid);
* the Jabber port (usually 5222).

= PubSub configuration =

Where to publish the notifications. It can be on a separate server.

* the pubsub server;
* the root publication node;
* 2 checkboxes to uncheck if you don't want to publish the posts or the comments.

Note that this node does not have to exist. When you will press the "Update"
button, the providden Jabber login and connection parameters will be tested
and the node will be created with all its tree. If anything goes wrong, you
will be informed about it.

The providden Jabber account can have no right to create the required post/
node, but then it must be created first and publisher rights at least must
have been given.

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
Anyway it is sufficient basically for the proof of concept, but probably not
for long-term (even mid-term) real use.
Yet I heard that the coming development version of ejabberd (3.0) has had a
rewrite of the pubsub implementation. I have not tested though.

I don't know sufficiently others server softwares to give an opinion about
them. But I was told Openfire should have soon a pretty good PubSub support. I
am still waiting to test it though.

= From myself: which Jabber client could I advice to visitors of my website? =

As explained, there are few, if none, clients with good PubSub support. This
is why I told this plugin is more a proof of concept than an useful one. But
hopefully, soon it will be better!
Process One has developped recently a pubsub client called `one-channel`, as
far as I know, the first one ever publicly released, fully dedicated to
pubsub. I had only one review from someone, not so enthousiast though. For my
own, I have unfortunately been unable to test, as it uses Flash technology
(Adobe AIR) and my MIPS computer does not take Flash very well (usually it
will crash, if it works at all, anyway). If someone had a chance to test it
with publication generated by my plugin, I would be happy to get a feedback...

= From myself: but no browser can detect XMPP autodiscovery links. What are they for?! =

I am writting a small Firefox plugin for this. Soon more infos!

= Do you like to ask questions to yourself? Do you feel lonely? =

Maybe. ;-)

== Screenshots ==

1. The configuration page;
2. The modified post management page;
3. The templates in practice: I added the code `Subscribe to the Jabber feeds:
<?php jabber_feed_display ('posts', 'a', "entries (jabber)"); ?> and
<?php jabber_feed_display ('comments', 'a'); ?>` to the footer.php of the
theme (here default).
4. The templates in practice again: I added `<?php jabber_feed_display
('current', 'a', 'Jabber') ?>` in the single.php file of the theme (between
the p tag of class 'postmetadata alt' in the default theme).

== Todo ==

* TTL support for DNS/SRV records.

* Still need to test extensively (and if necessary improve) the right system
in Wordpress:
	- will the non-approved comments be published on the comment nodes?
	- will the private posts be published?

* In the "configuration" window, I should make a detection of the
prerequisites, gray everything if a mandatory one is not fulfilled and give an
explanation text.

* And what about internationalization?!

* Manage menu: with failed publication, it should be possible to retry the
 publication from this page (with multiple checkbox if possible to massively
 run publication!).

* And what about an equivalent of post management for comments?! Is it
possible (apparently not, according to Wordpress documentation)?

* The item should also be updated when a published post is edited.

* Why not add support to pages? -> current work.

* Create the bot account as well?

* Why not retry to publish after a timer (let's say 1 day?) after a failure,
 with a number maximum of tries?

* What could be fun: if the subscribers could be also publisher, hence
answering to a post by directly publishing to the node?
But how is a publisher identified?! No "from" with a pseudo in it, and data
about real jid accessible from admin.

* Improve globally the
message syntax (for instance the message 'updated' and 'created' tags).

* Configure the node instead of simply create it with default configuration.

* Check the node authorizations if it is already existing.

* Improve the internationalization strings (and set at least 2 default
languages: English and French).

* And what if the post or page is private? Should it be added on a node? Maybe
a node with a white list that the admin can manage?

* Apparently if I disapprove or flag a comment as spam from the "edit comment"
page, it does not remove it from the node. Why? Is it a bug from Wordpress
which does not trigger the action in all cases? Or is there another Wordpress'
action for this specific case? Try this also with posts from the "edit post"
page.
Edit: it seems to be a known bug: http://trac.wordpress.org/ticket/5792
Is planned to be fixed for Wordpress 2.9 apparently.
=> this seems to be fixed now! Woohoo!

* Add more authentication mechanisms? (current: Digest-MD5, CramMD5, PLAIN,
ANONYMOUS. Only Digest-MD5 and PLAIN has been tested)

* SSLv23 added, but what about TLS?  (TLS does not work on Gmail. SSL and TLS works on ejabberd. So
I have decided to use SSL only for now)
Note that it looks like the PHP implementation of TLS encryption does not seem
like verifying the certificate...

* Add poster/commenter name in ATOM format.
<author><name>Poster's name</name></author>
http://tools.ietf.org/html/rfc4287

* Add support for button of publishing all posts in once...

* Propose max_items to change...

* Improve title naming (different for comments, posts, and even for comments
of each posts...)

* Isn't there a configuration option for getting notif of subnodes?.. Seem to
remind yes.

== Contacts ==

You can have some news about this plugin on [my freedom
haven](http://jehan.zemarmot.net "my public diary").
If you want to contact me by jabber, ask me first by email (which you can find
on my website. Yes I know, this is complicated: but I like my tranquility, so
I make a filter).

Have a nice life!

== Changelog ==

= 0.6 =

- XMPP connections now in non blocking mode for immediate 'apparent' execution
  of pages (post in particular). Technically I used the Wordpress HTTP API
  (typically I do a desynchronized http request in non blocking mode, meaning
  I don't wait for the answer). As the particular API and function I used
  dates from 2.7.0, I made a test on existence of the HTTP API, so that the 
  old blocking method for older Wordpress without this API is used in such
  case.
  This will definitely fix the slowness issues of ejabberd wen sending items
  with a big payload (typically any article I would write in my public diary
  :p), though I heard it should be improved a lot in the coming ejabberd
  version.

- In order not to connect several times unecessarily to the XMPP server, the
  plugin will be able to connect once for several close publications
  (comments, posts, deletions, etc.). For ensuring unicity of connections, it
  will use either the PHP semaphores API if PHP has been compiled with
  --enable-sysvsem, or will fall back to some unreliable system (but still ok
  in most case, and not too heavy). Yet the PHP semaphores are prefered.
  Note that the alternate system uses the transient API from Wordpress
  available only from Woprdress 2.8.0. Hence the plugin is only usable from
  Wordpress 2.8.0 with this method.

= 0.5 =

- Better SRV library gestion: the plugin can now "switch" between the NET_DNS library
  if installed, to the core PHP SRV functions (if using Linux or a Windows
  with a recent PHP) or nothing (no SRV gestion).
  Hence for Linux or Windows (with recent PHP), no additional library is
  anymore required.

- Algorithm for dealing with priority and weight of target in SRV records is
  now implemented, exactly as in RFC 2782. Therefore the only missing part for
  a full SRV records compliance is now a support of Time To Live, which could
  be interesting next implementation.

- New widget for displaying the XMPP feeds in the sidebars.
