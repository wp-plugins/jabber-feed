<?php
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


/************************\
Url of posts publications
- 'node' can be 'all', 'posts', 'pages', 'comments', 'current' (comments of the current page/post) or a number (comments of the numbered post/page);
- 'what' can be the bare 'url', or included in a 'a' tag, or a 'link' tag;
- 'text' is optional displayed text (only when 'a' tag).
\************************/

function jabber_feed_get ($node = 'posts', $what = 'url', $text = '')
{
	global $post;
	$configuration = get_option ('jabber_feed_configuration');
	
	$url = "xmpp:" . $configuration['pubsub_server'] . "?action=subscribe;node=";
	$url .= $configuration['pubsub_node'];

	if ($node == 'all')
		$url .= ';subscription_type=items;subscription_depth=1';
	elseif ($node == 'comments')
		$url .= '/comments;subscription_type=items;subscription_depth=1';
	elseif ($node == 'pages')
		$url .= '/pages';
	elseif ($node == 'current')
		$url .= '/comments/' . $post->ID;
	elseif (is_int ($node))
		$url .= '/comments/' . $node;
	else //($node == 'posts')
		$url .= '/posts';

	if (empty ($text))
	{
		if ($node == 'all')
			$text = __('Entries, pages and comments') . " (Jabber)";
		elseif ($node == 'comments')
			$text = __('Comments') . " (Jabber)";
		elseif ($node == 'current')
			$text = __('Comments of the current entry') . " (Jabber)";
		elseif ($node == 'pages')
			$text = __('Pages') . " (Jabber)";
		elseif (is_int ($node))
			$text = __('Comments of entry "') . get_post($id)->post_title . '" (Jabber)';
		else // ($node == 'posts')
			$text = __('Entries') . " (Jabber)";
	}
	else
		$text = htmlentities ($text);

	if ($what == 'a')
		$url = "<a rel='alternate' href='" . $url . "'>" . $text . "</a>";
	elseif ($what == 'link')
		$url = '<link rel="alternate" title="' . $text . '" href="' . $url . '" />';

	return $url;
}


function jabber_feed_display ($node = 'posts', $what = 'url', $text = '')
{
	echo jabber_feed_get ($node, $what, $text);
}

?>
