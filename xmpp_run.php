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

// Even when the user's browser disconnects, the xmpp job will continue its execution.
ignore_user_abort(true);

/** Setup WordPress environment */
if (!defined ('ABSPATH'))
	require_once ('../../../wp-load.php');

if (function_exists (sem_get))
{
	$semaphore_key = ftok (__FILE__, 'j'); // PHP 4 > 4.2.0, PHP 5
	$semaphore = sem_get ($semaphore_key, 1); // no more than one process at once (so this sem is a mutex) will be able to run this script.

	if (! sem_acquire ($semaphore))
		exit ();
}
else
{
	// If PHP has not been compiled with the semaphore support, I will 'very roughly' fake them.
	// At this level, it is more difficult to implement as good mutual exclusion as at system or at least lower level. Here I use the Wordpress db, which is highly inefficient.
	// Hence I see a way to implement not too bad exclusion, but it would imply 2 read-passes and 1 or 2 write-pass on the db!
	// Therefore I will just make it simple, but with higher risk of letting two queries executed at the same time. It is not that "bad" here as there would be no data corruption (just some XMPP queries maybe sent twice, no big deal), but I prefer this than a very heavy exclusion check at each script run. The best is obviously to compile PHP with '--enable-sysvsem'.
	$semaphore = get_transient ('xmpp_run_sem');
	if (! $semaphore)
		set_transient ('xmpp_run_sem', TRUE, 300);
	// I set the life of this transient data for 5 min. The script would normally delete it before, but if there is any issue, at least it is cleaned (and 5 min is a reasonable amount of time. I doubt the script would last that long... or it has a problem).
	else
		exit ();
	// If there is already a semaphore, I don't wait, so there may be some query delay if the current process did not get the last data on time. But that's the problem of not having sreal semaphores!
}

require_once (dirname(__FILE__) . '/xmpp_stream.php');

$jobs = get_option ('jabber_feed_jobs');

if (empty ($jobs))
{
	// even though this is the cleaner, in fact PHP will automatically release any semaphore at the end of the script.
	if (function_exists (sem_get))
		sem_release ($semaphore);
	else
		delete_transient ('xmpp_run_sem');
	exit ();
}

function do_publish ()
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
				// Updating the options at each iteration is probably not the most efficient.
				// But imaging that there is a huge job list which times-out this php script in the middle...
				// Then it would end without notifying its successful queries, if any, then it may time out forever (instead of progressively update all, execution after execution).
				update_option('jabber_feed_jobs', $jobs);
				update_option('jabber_feed_post_history', $history);
			}
			$xs->create_leaf ($configuration['pubsub_server'], $configuration['pubsub_node'] . '/comments/' . $post_ID);
			// Not fatale if the comments leaf creation fails.
		}
		$xs->quit ();
		// in case we finish by an error, I need to save.
		update_option('jabber_feed_post_history', $history);
	}
}

do_publish ();

if (function_exists (sem_get))
	sem_release ($semaphore);
else
	delete_transient ('xmpp_run_sem');
exit ();

?>
