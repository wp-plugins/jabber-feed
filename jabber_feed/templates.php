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
- 'what' can be the bare 'url', or included in a 'a' tag, or a 'link' tag;
- 'node' can be 'all', 'posts', 'pages', 'comments', 'current' (comments of the current page/post) or a number (comments of the numbered post/page);
- 'text' is optional displayed text (only when 'a' tag).
\************************/

function jabber_feed_get ($node = 'posts', $what = 'url', $text = '')
{
	global $id;
	$configuration = get_option ('jabber_feed_configuration');
	
	$url = "xmpp:" . $configuration['pubsub_server'] . "?action=subscribe;node=";
	$url .= $configuration['posts_node'];

	if ($node == 'comments')
		$url .= '/comments';
	elseif ($node == 'pages')
		$url .= '/pages';
	elseif ($node == 'current')
		$url .= '/comments/' . $id;
	elseif (is_int ($node))
		$url .= '/comments/' . $node;
	elseif ($node == 'posts' || $node != 'all')
		$url .= '/posts';

	if ($what == 'a')
	{
		$url = "<a rel='alternate' href='" . $url . "'>";
		if (empty ($text))
		{
			if ($node == 'comments')
				$url .= __('Comments') . " (Jabber)";
			elseif ($node == 'current')
				$url .= __('Comments of this entry') . " (Jabber)";
			elseif ($node == 'pages')
				$url .= __('Pages') . " (Jabber)";
			elseif (is_int ($node))
				$url .= __('Comments of entry ') . $id . " (Jabber)";
			elseif ($node == 'posts' || $node != 'all')
				$url .= __('Entries') . " (Jabber)";
			else
				$url .= __('Entries, pages and comments');
		}
		else
			$url .= htmlentities ($text);
		$url .= "</a>";
	}
	elseif ($what == 'link')
		$url = "<link rel='alternate' href='" . $url . "' />";

	return $url;
}


function jabber_feed_display ($node = 'posts', $what = 'url', $text = '')
{
	echo jabber_feed_get ($node, $what, $text);
}

?>
