<?php

/*  Copyright 2008 Jehan Hysseo  (email : jehan at zemarmot.net) {{{

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
    Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA }}}
*/

require_once (dirname(__FILE__) . '/xmpp_stream.php');

// Even when the user's browser disconnects, the xmpp job will continue its execution.
ignore_user_abort(true);

/** Setup WordPress environment */
if (!defined ('ABSPATH'))
	require_once ('../../../wp-load.php');

$jobs = get_option ('jabber_feed_jobs');

if (empty ($jobs))
	exit ();

function do_publish_posts ()
{
	global $jobs;
	
	$configuration = get_option ('jabber_feed_configuration');
	$publishxhtml = true;
	if (empty ($configuration['publish_xhtml']))
		$publishxhtml = false; 

	$count_posts = wp_count_posts ();
	$published_posts = $count_posts->publish;

	$xs = new xmpp_stream ($configuration['node'],
		$configuration['domain'], $configuration['password'],
		'bot', $configuration['server'], $configuration['port']);
	
	$history = get_option('jabber_feed_post_history');

	if ($xs->log ())
	{
		$xs->configure_node ($configuration['pubsub_server'],
					$configuration['pubsub_node'] . '/posts',
					min (20, $published_posts * 2));
		// I don't check for the result...
		foreach ($jobs as $post_ID => $feed)
		{
			if (! $xs->notify ($configuration['pubsub_server'],
				$configuration['pubsub_node'] . '/posts', $post_ID, $feed['title'],
				//$link, $feed_content, $feed_excerpt)
				$feed['link'], $feed['content'], $feed['excerpt'], $publishxhtml))
			{
				$history[$post_ID] = array ('error' => $xs->last_error);
			}
			else
			{
				if (array_key_exists ($post_ID, $history))
				{
					if (array_key_exists ('error', $history[$post_ID]))
					{
						unset ($history[$post_ID]['error']);
						$history[$post_ID] = array ('published' => date ('c'), 'updated' => date ('c'), 'id' => $post_ID);
					}
					else
						$history[$post_ID]['updated'] = date ('c');
				}
				else
					$history[$post_ID] = array ('published' => date ('c'), 'updated' => date ('c'), 'id' => $post_ID);
				// XXX: to check, but 'id' can be removed anyway, as it is $post_ID...
				unset ($jobs[$post_ID]);
			}
			$xs->create_leaf ($configuration['pubsub_server'], $configuration['pubsub_node'] . '/comments/' . $post_ID);
			// Not fatale if the comments leaf creation fails.
		}
		$xs->quit ();
	}

	update_option('jabber_feed_post_history', $history);
}

do_publish ();
update_option('jabber_feed_jobs', $jobs);

exit ();

?>
