<?php
/*
Plugin Name: Jabber Feed
Plugin URI: http://jehan.zemarmot.net/blog/jabber-feed/
Description: a Jabber publishing notification for articles and comments.
Version: 0.1
Author: Jehan Hysseo
Author URI: http://jehan.zemarmot.net
*/

/*  Copyright 2008 Jehan Hysseo  (email : jehan at zemarmot.net)

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation; either version 3 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program; if not, write to the Free Software
    Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/


// to display: jabber_feed_info
// to use: get_jabber_feed_info

// params: default is url of posts, param1 is what ('comment', 'post', 'page'), param2 is id (only for comments -> will be answer to posts and pages), param3 is text (only for a), param4 is tag ('a' or 'link').
/************************\
Url of posts publications
'text' is optional displayed text.
\************************/
function jabber_feed_posts_a ($text = '')
{
	$configuration = get_option ('jabber_feed_configuration');
	$url = "<a rel='alternate' href='xmpp:";
	$url .= $configuration['pubsub_server'] . "?action=subscribe;node=";
	$url .= $configuration['posts_node'] . "'>";
	if (empty ($text))
		$url .= "Entries (Jabber)";
	else
		$url .= htmlentities ($text);
	$url .= "</a>";
	echo $url;
	return;
}

/************************\
Url of posts publications
'text' is optional displayed text.
\************************/
function jabber_feed_posts_link ()
{
	$configuration = get_option ('jabber_feed_configuration');
	$url = "<link rel='alternate' href='xmpp:";
	$url .= $configuration['pubsub_server'] . "?action=subscribe;node=";
	$url .= $configuration['posts_node'] . "'/>";
	echo $url;
	return;
}

/***************************\
Url of comments publications
1/ 'text' is optional displayed text.
2/ 'post_id' is either:
- a number (node for a specific post);
- 'current' (node for the current post);
- 'all' by default (all comments).
\***************************/
function jabber_feed_comments ($text = '', $post_id = 'all')
{
	global $id;
	$configuration = get_option ('jabber_feed_configuration');
	$url = "<a rel='alternate' type='application/xmpp-notify+xml' href='xmpp:";
	$url .= $configuration['pubsub_server'] . "?pubsub;action=subscribe;node=";
	$url .= $configuration['comments_node'];

	if ($post_id == 'current')
		$url .= '/' . $id;
	elseif (is_int ($post_id))
		$url .= '/' . $post_id;
	
	$url .= "'>";
	if (empty ($text))
		$url .= "Comments (Jabber)";
	else
		$url .= htmlentities ($text);
	$url .= "</a>";
	echo $url;
	return;
}

?>
