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
    exit ();

require_once (dirname(__FILE__) . '/xmpp_stream.php');

$sem_jobs__key = ftok ("jabber_feed_single_jobs", 'j'); // PHP 4 > 4.2.0, PHP 5
$sem_jobs = sem_get ($semaphore_key, 1); 
if (! sem_acquire ($sem_jobs))
{
    sem_release ($semaphore);
    exit (); // Should never happen (the acquisition should block until the lock is got). But in any case, no job is lost (still in the queue). So exiting until next time should be fine. 
}

$jobs = get_option ('jabber_feed_single_jobs');

// I want to keep this specific semaphore locked the lesser time. So I empty it and release it immediately.
// If something goes wrong during the execution of this script, I will acquire it again and reinject the jobs.
// Of course there is one wrong case when something goes wrong, but the script does not end (so no possible reinjection).
// This special case could be taken care of, probably with another option (or a transient). But I am not sure it is worth it here.
update_option('jabber_feed_single_jobs', array ());
sem_release ($sem_jobs);

if (empty ($jobs))
{
	// even though this is the cleaner, in fact PHP will automatically release any semaphore at the end of the script.
    sem_release ($semaphore);
	exit ();
}

function do_publish () // {{{
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
			if ($feed['type'] == 'publish')
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
			else if ($feed['type'] == 'delete')
			{
				if (! ($xs->delete_item ($configuration['pubsub_server'],
						$configuration['pubsub_node'] . '/posts', $ID) //$history[$ID]['id'])
						&& $xs->delete_node ($configuration['pubsub_server'], $configuration['pubsub_node'] . '/comments/' . $ID)))
				{
					// I remove anyway the history as otherwise, it would be "lost" and never removed:
					// the ID does not exist anymore because the post is anyway removed.
					unset ($history[$ID]);
					jabber_feed_log ("Error on removing post: ". $xs->last_error);
				}
				else
					unset ($history[$ID]);
			}
		}
		$xs->quit ();
	}
    update_option('jabber_feed_post_history', $history);

    // So in the end, I send the job list. Hopefully, it should be empty by now. If it is not (XMPP error?), I append it to the current job list (some new jobs may have been added during this script).
    if (!empty ($jobs))
    {
        $sem_jobs__key = ftok ("jabber_feed_single_jobs", 'j'); // PHP 4 > 4.2.0, PHP 5
        $sem_jobs = sem_get ($semaphore_key, 1); 
        if (! sem_acquire ($sem_jobs))
            return; // Should never happen (the acquisition should block until the lock is got). If this happens anyway, we might (depending on a lot of "if" based on errors, so it is mainly not likely) lose some jobs.
        $current_jobs = get_option ('jabber_feed_single_jobs');

        // The order for the '+' operator's parameters is meaningful. $current_jobs is newer, so if any duplicate, I want its value to be used, not the one in $job.
        // Hence it must be the first parameter here.
        update_option('jabber_feed_single_jobs', $current_jobs + $jobs);
        sem_release ($sem_jobs);
    }
} // }}}

do_publish ();

sem_release ($semaphore);

exit ();

?>
