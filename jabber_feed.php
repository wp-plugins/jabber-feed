<?php
/*
Plugin Name: Jabber Feed
Plugin URI: http://jehan.zemarmot.net/blog/jabber-feed/
Description: a Jabber publishing notification for articles and comments.
Version: 0.5
Author: Jehan Hysseo
Author URI: http://jehan.zemarmot.net
*/

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

require_once(dirname(__FILE__) . '/xmpp_stream.php');
require_once(dirname(__FILE__) . '/xmpp_utils.php');
require_once(dirname(__FILE__) . '/templates.php');
require_once(dirname(__FILE__) . '/jf_widget.php');

///////////////////////
// Post Publication  //
///////////////////////

function xmpp_publish_post ($post_ID) // {{{
{
	$feed = array ();

	$configuration = get_option ('jabber_feed_configuration');

	if (empty ($configuration['publish_posts']))
		return $post_ID;

	$blog_title = get_bloginfo ('name'); 

	$post = get_post ($post_ID, OBJECT);
	$post_title = $post->post_title;
	$post_author = get_userdata ($post->post_author)->display_name;
	$feed['title'] = '[' . $blog_title . "] " . $post_title . " (publisher: " . $post_author . ')';

	$post_content = $post->post_content;
	$post_excerpt = $post->post_excerpt;
	$feed['content'] = '';
	$feed['excerpt'] = '';
	$feed['link'] = $post->guid;

	if (empty ($configuration['publish_extract']))
		$feed['content'] = $post_content;
	elseif (! empty ($post_excerpt))
	{
		$feed['excerpt'] = $post_excerpt;
		$feed['excerpt'] .= '<br /><a href="' . $feed['link'] . '">' . __("Read the rest of this entry on the website.") . '</a>';
	}
	else
	{
		$pattern = '/<!--\s*more(.|\n)*$/';
		$replacement = '<br /><a href="' . $link . '">' . __("Read the rest of this entry on the website.") . '</a>';
		$feed['excerpt'] = preg_replace ($pattern, $replacement, $post_content, 1);
	}

    $no_concurrency_run = false;

	if (function_exists ('wp_remote_request') && function_exists (sem_get))
	{
		$feed['type'] = 'publish';
        $semaphore_key = ftok ("jabber_feed_single_jobs", 'j'); // PHP 4 > 4.2.0, PHP 5
        $semaphore = sem_get ($semaphore_key, 1); 
        if (! sem_acquire ($semaphore))
            $no_concurrency_run = true; // This should not happen, but if ever the lock cannot be got for some reason, at least I publish the old way.
		$jobs = get_option ('jabber_feed_single_jobs');
		$jobs[$post_ID] = $feed;
		update_option('jabber_feed_single_jobs', $jobs);
        sem_release ($semaphore);

		//$xmpp_run_url = get_bloginfo ('wpurl') . rtrim (dirname (__FILE__)) . '/xmpp_run.php';
		$xmpp_run_url = WP_PLUGIN_URL . '/' . str_replace (basename (__FILE__), "", plugin_basename (__FILE__)) . 'xmpp_run.php';
		wp_remote_request ($xmpp_run_url, array ('blocking' => false));
    }
    else
        $no_concurrency_run = true;

	if ($no_concurrency_run) // For retrocompatibility with Wordpress < 2.7.0 which does not have the HTTP API, or PHP compiled without semaphore support.
	{
		$count_posts = wp_count_posts ();
		$published_posts = $count_posts->publish;

		if (empty ($configuration['publish_xhtml']))
			$publishxhtml = false; 
		else
			$publishxhtml = true;

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
			}
			$xs->create_leaf ($configuration['pubsub_server'], $configuration['pubsub_node'] . '/comments/' . $post_ID);
			// Not fatale if the comments leaf creation fails.
			$xs->quit ();
		}
		else
			$history[$post_ID] = array ('error' => $xs->last_error);

		update_option('jabber_feed_post_history', $history);
	}
	return $post_ID;
} // }}}

function xmpp_delete_post_page ($ID) // {{{
{
	$configuration = get_option ('jabber_feed_configuration');
	$history = get_option('jabber_feed_post_history');

	if (empty ($configuration['publish_posts']) || ! array_key_exists ($ID, $history) || array_key_exists ('error', $history[$ID]))
		return $ID;

    $no_concurrency_run = false;

	if (function_exists ('wp_remote_request') && function_exists (sem_get))
	{
		$query = array ();
		$query['type'] = 'delete';

        $semaphore_key = ftok ("jabber_feed_single_jobs", 'j'); // PHP 4 > 4.2.0, PHP 5
        $semaphore = sem_get ($semaphore_key, 1); 
        if (! sem_acquire ($semaphore))
            $no_concurrency_run = true; // This should not happen, but if ever the lock cannot be got for some reason, at least I publish the old way.
		$jobs = get_option ('jabber_feed_single_jobs');
		$jobs[$ID] = $query;
		update_option('jabber_feed_single_jobs', $jobs);
        sem_release ($semaphore);

		$xmpp_run_url = WP_PLUGIN_URL . '/' . str_replace (basename (__FILE__), "", plugin_basename (__FILE__)) . 'xmpp_run.php';
		wp_remote_request ($xmpp_run_url, array ('blocking' => false));
	}
    else
        $no_concurrency_run = true;

	if ($no_concurrency_run) // For retrocompatibility with Wordpress < 2.7.0 which does not have the HTTP API or PHP compiled without semaphore support.
	{
		$xs = new xmpp_stream ($configuration['node'],
			$configuration['domain'], $configuration['password'],
			'bot', $configuration['server'], $configuration['port']);

		if (! ($xs->log ()
			&& $xs->delete_item ($configuration['pubsub_server'],
				$configuration['pubsub_node'] . '/posts', $ID) //$history[$ID]['id'])
				&& $xs->delete_node ($configuration['pubsub_server'], $configuration['pubsub_node'] . '/comments/' . $ID)
				&& $xs->quit ()))
		{
			// I remove anyway the history as otherwise, it would be "lost" and never removed:
			// the ID does not exist anymore because the post is anyway removed.
			unset ($history[$ID]);
			jabber_feed_log ("Error on removing post: ". $xs->last_error);
		}
		else
		{
			unset ($history[$ID]);
		}
		update_option('jabber_feed_post_history', $history);
	}

	return $ID;
} // }}}

add_action ('publish_post', 'xmpp_publish_post');
add_action ('delete_post', 'xmpp_delete_post_page');

///////////////////////////
// Comment Publication  //
//////////////////////////

function xmpp_publish_comment ($comment_ID, $status) // {{{
{
	$configuration = get_option ('jabber_feed_configuration');
	if (empty ($configuration['publish_comments']))
		return $comment_ID;

	if ($status == 1)
	{
		$blog_title = get_bloginfo ('name'); 

		$comment = get_comment ($comment_ID, OBJECT);
		$post = get_post ($comment->comment_post_ID, OBJECT);
		$post_title = $post->post_title;
		if ($comment->user_ID == 0)
			$comment_author = $comment->comment_author;
		else
		{
			$user_info = get_userdata ($comment->user_ID);
			$comment_author = $user_info->display_name; // user_nicename?
		}
		$feed_title = '[' . $blog_title . "] " . $post_title . " (commenter: " . $comment_author . ')';
		$feed_content = $comment->comment_content;
		$link = $post->guid;
		$id = $comment_ID;

		$count_comments = wp_count_comments ($comment->comment_post_ID);
		$published_comments = $count_comments->approved;

		$xs = new xmpp_stream ($configuration['node'],
			$configuration['domain'], $configuration['password'],
			'bot', $configuration['server'], $configuration['port']);
		
		// Must check whether container already exists and create it otherwise!

		$history = get_option('jabber_feed_comment_history');

		if ($xs->log ())
		{
			$xs->configure_node ($configuration['pubsub_server'],
					$configuration['pubsub_node'] . '/comments/' . $post->ID,
					min (10, $published_comments * 2));

			if (! $xs->notify ($configuration['pubsub_server'],
						$configuration['pubsub_node'] . '/comments/' . $post->ID, $id, $feed_title,
						$link, $feed_content))
				$history[$id] = array ('error' => $xs->last_error);
			else
			{
				if (array_key_exists ($id, $history))
				{
					if (array_key_exists ('error', $history[$comment_ID]))
					{
						unset ($history['$comment_ID']['error']);
						$history[$id] = array ('published' => date ('c'), 'updated' => date ('c'), 'id' => $id);
					}
					else
						$history[$id]['updated'] = date ('c');
				}
				else
					$history[$id] = array ('published' => date ('c'), 'updated' => date ('c'), 'id' => $id);
			}
			$xs->quit ();
		}
		update_option('jabber_feed_comment_history', $history);
	}

	return $comment_ID;
} // }}}

// TODO: history for comments

function xmpp_delete_comment ($comment_ID) // {{{
{
	$configuration = get_option ('jabber_feed_configuration');

	if (empty ($configuration['publish_comments']))
		return $comment_ID;

	$history = get_option('jabber_feed_comment_history');
	$comment = get_comment ($comment_ID, OBJECT);

	$xs = new xmpp_stream ($configuration['node'],
		$configuration['domain'], $configuration['password'],
		'bot', $configuration['server'], $configuration['port']);
	
	if (! ($xs->log ()
		&& $xs->delete_item ($configuration['pubsub_server'],
			$configuration['pubsub_node'] . '/comments/' . $comment->comment_post_ID, $comment_ID)
		&& $xs->quit ()))
	{
		unset ($history[$comment_ID]);
		jabber_feed_log ("Error on removing post: ". $xs->last_error);
	}
	else
			unset ($history[$comment_ID]);

	update_option('jabber_feed_comment_history', $history);
	return $comment_ID;
} // }}}

function xmpp_comment_status ($comment_ID, $status) // {{{
{
	jabber_feed_log ('Change comment ' . $comment_ID . ' to ' . $status);
	$configuration = get_option ('jabber_feed_configuration');
	if (empty ($configuration['publish_comments']))
		return $comment_ID;

	if ($status == 'approve')
		xmpp_publish_comment ($comment_ID, 1);
	else
		xmpp_delete_comment ($comment_ID);

	return $comment_ID;
} // }}}

function xmpp_status_change ($new, $old, $comment) // {{{
{
	jabber_feed_log ('Change from ' . $old . ' to ' . $new . ":\n"); // . $comment);
} // }}}

add_action ('comment_post', 'xmpp_publish_comment', 10, 2);
add_action ('delete_comment', 'xmpp_delete_comment');
add_action ('wp_set_comment_status', 'xmpp_comment_status', 10, 2);
//add_action ('transition_comment_status', 'xmpp_status_change', 10, 3);

/**********************\
// Configuration Page \\
\**********************/

add_option('jabber_feed_configuration', array (), 'Configuration of the Jabber Feed plugin', 'yes');
add_option ('jabber_feed_post_history', array (), 'All information about fed posts and pages, successes and failures', 'yes');
add_option ('jabber_feed_comment_history', array (), 'All information about fed comments, successes and failures', 'yes');

function jabber_feed_configuration_page () // {{{
{
	global $wpdb;
	if (isset($_POST['update_configuration']))
	{
		$configuration['node'] = strip_tags (trim($_POST['node']));
		$configuration['domain'] = strip_tags (trim ($_POST['domain']));
		$configuration['password'] = strip_tags (trim($_POST['password']));

		$posted_server = strip_tags (trim($_POST['server']));

		/*if ($posted_server == '')
			$configuration['server'] = $configuration['domain'];
		else*/
		$configuration['server'] = $posted_server;

		$posted_port = strip_tags (trim($_POST['port']));
	/*	if ($posted_port == '' || ! is_numeric ($posted_port))
			$configuration['port'] = 5222;
		else*/
		if (is_numeric ($posted_port))
			$configuration['port'] = intval ($posted_port);
		else
			$configuration['port'] = '';

		$configuration['pubsub_server'] = strip_tags (trim($_POST['pubsub_server']));
		$configuration['pubsub_node'] = strip_tags (trim($_POST['pubsub_node']));

		$configuration['publish_posts'] = strip_tags (trim($_POST['publish_posts']));
		$configuration['publish_comments'] = strip_tags (trim($_POST['publish_comments']));
		//$configuration['publish_pages'] = strip_tags (trim($_POST['publish_pages']));

		$configuration['publish_extract'] = strip_tags (trim($_POST['publish_extract']));
		//$configuration['publish_xhtmlim'] = strip_tags (trim($_POST['publish_xhtmlim']));
		$configuration['publish_xhtml'] = strip_tags (trim($_POST['publish_xhtml']));

		$xs = new xmpp_stream ($configuration['node'],
			$configuration['domain'], $configuration['password'],
			'bot', $configuration['server'], $configuration['port']);
		
		if ($xs->log ()
			&& $xs->create_leaf ($configuration['pubsub_server'],
				$configuration['pubsub_node'] . '/posts')
			&& $xs->create_collection ($configuration['pubsub_server'],
				$configuration['pubsub_node'] . '/comments')
			&& $xs->create_leaf ($configuration['pubsub_server'],
				$configuration['pubsub_node'] . '/pages')
			&& $xs->quit ())
		{
			update_option('jabber_feed_configuration', $configuration);
			echo '<div class="updated"><p>' . __('Configuration saved') . '</p></div>';
		}
		else
			echo '<div class="updated"><p>' . __('Configuration not saved. The following error occured:<br />') . $xs->last_error . '</p></div>';
			
	}
	else
		// If we are just displaying the page or if we reset to last saved config, we first load up the options array.
		$configuration = get_option('jabber_feed_configuration');

	//now we drop into html to display the option page form.
	?>
	<div class="wrap">
		<h2><?php echo _e('Jabber Feed configuration') ?></h2>
		<form method="post" action="">
			<fieldset class="options">
				<legend><?php _e('Publishing Account') ?></legend>
				<p><label>
				<?php _e('Login (full jid)') ?><br />
					<input name="node"
						type="text"
						id="node"
						value="<?php echo $configuration['node']; ?>"
						size="29" /></label>
				
				<label>@
					<input name="domain"
						type="text"
						id="domain"
						value="<?php echo $configuration['domain']; ?>"
						size="28" />
				</label></p>

				<p><label>
				<?php _e('Password') ?><br />
					<input name="password"
						type="password"
						id="password"
						value="<?php echo $configuration['password']; ?>"
						size="60" />
				</label></p>
			</fieldset>

			<fieldset class="options">
				<legend><?php _e('Connection Parameters') ?></legend>
				<p><em><?php _e("These are advanced settings. If you don't understand them, they are probably useless and default values will be enough.") ?> </em></p>
				<?php 
				if (class_exists ("NET_DNS_Resolver") || function_exists ("dns_get_record"))
				{
					?>
				<p><em><?php _e('Note that SRV Records are used by default.') ?></em></p>
				<?php
				}
				else
				{
					?>
					<p><em><?php _e('SRV Records discovery option is not enabled because the PEAR module NET_DNS is not installed on this server.') ?></em></p>
					<?php
				}
		?>

				<p><label>
				<?php _e('Jabber Server') ?><br />
					<input name="server"
						type="text"
						id="server"
						value="<?php echo $configuration['server']; ?>"
						size="60" />
				</label></p>

				<p><label>
				<?php _e('Port') ?><br />
					<input name="port"
						type="text"
						id="port"
						value="<?php echo $configuration['port']; ?>"
						size="60" />
				</label></p>

			</fieldset>
				
			<fieldset class="options">
				<legend><?php _e('PubSub configuration') ?></legend>

				<p><label>
				<?php _e('Server') ?><br />
					<input name="pubsub_server"
						type="text"
						id="pubsub_server"
						value="<?php echo $configuration['pubsub_server']; ?>"
						size="60" />
				</label></p>

				<p><label>
				<?php _e('Publication Node') ?><br />
					<input name="pubsub_node"
						type="text"
						id="pubsub_node"
						value="<?php echo $configuration['pubsub_node']; ?>"
						size="60" />
				</label></p>

				<p>
					<strong><?php _e('Publication Contents:') ?></strong><br />
					<input name="publish_posts"
						type="checkbox"
						id="publish_posts"
						<?php
						if (! empty ($configuration['publish_posts']))
						{
						?>
						checked="checked"
						<?php } ?>
					/>
					<label for="publish_posts"><?php _e('Publish posts') ?></label><br />

					<input name="publish_comments"
						type="checkbox"
						id="publish_comments"
						<?php
						if (! empty ($configuration['publish_comments']))
						{
						?>
						checked="checked"
						<?php } ?>
					/>
					<label for="publish_comments"><?php _e('Publish comments') ?></label><br />

					<?php /*
					<input name="publish_pages"
						type="checkbox"
						id="publish_pages"
						<?php
						if (! empty ($configuration['publish_pages']))
						{
						?>
						checked="checked"
						<?php } ?>
						disabled="disabled"
					/>
					<label for="publish_pages"><?php _e('Publish pages (feature not yet implemented)') ?></label><br />
					*/ ?>
			
				</p>
			</fieldset>

			<fieldset class="options">
				<legend><?php _e('Notification Options') ?></legend>
					<input name="publish_extract"
						type="checkbox"
						id="publish_extract"
						<?php
						if (! empty ($configuration['publish_extract']))
						{
						?>
						checked="checked"
						<?php } ?>
					/>
					<label for="publish_extract"><?php _e('Publish extract only <em>(when available)</em>') ?></label><br />

					<input name="publish_xhtml"
						type="checkbox"
						id="publish_xhtml"
						<?php
						if (class_exists (tidy))
						{
							if (! empty ($configuration['publish_xhtml']))
							{
						?>
						checked="checked"
						<?php
							}
						}
						else
						{
						?>
						disabled="disabled"
						<?php } ?>
					/>
					<?php /*
					<label for="publish_xhtmlim"><?php _e('Format message in XHTML-IM <em>(the textual version is also sent)</em>.');
					if (! class_exists (tidy))
					{ ?>
					<br /> <em>
					<?php	_e ('This feature is disabled because the "tidy" library is missing on this system (read the prerequisites for more information).') ?>
					</em>
					<?php } ?>
					</label><br />
					*/ ?>
					<label for="publish_xhtml"><?php _e('Format message in XHTML <em>(the textual version is also sent)</em>.');
					if (! class_exists (tidy))
					{ ?>
					<br /> <em>
					<?php	_e ('This feature is disabled because the "tidy" library is missing on this system (read the prerequisites for more information).') ?>
					</em>
					<?php } ?>
					</label><br />

			</fieldset>


			<div class="submit">
				<input type="submit"
					name="update_configuration"
					value="<?php _e('Update') ?>"
					style="font-weight:bold;" />
				<input type="submit"
					name="reset_configuration"
					value="<?php _e('Reset') ?>"
					style="font-weight:bold;font-style:italic" />
			</div>
		</form>    		
	</div>
	<?php	
} // }}}

function jabber_feed_menu () // {{{
{
	if (function_exists('current_user_can'))
	{
		if (!current_user_can('manage_options'))
			return;
	}
	else
	{
		global $user_level;
		get_currentuserinfo ();
		if ($user_level < 8)
			return;
	}
	if (function_exists ('add_submenu_page'))
		add_submenu_page('plugins.php', __('Jabber Feed'), __('Jabber Feed configuration'), 1, __FILE__, 'jabber_feed_configuration_page');
} // }}}

// Install the configuration page.
add_action ('admin_menu', 'jabber_feed_menu');

/*******************\
// Management Page \\
\*******************/

function jabber_feed_admin_columns ($defaults) // {{{
{
    $defaults['jabber_feed'] = __('Jabber Feed');
    return $defaults;
} // }}}

function jabber_feed_custom_column ($column, $id) // {{{
{
	if ($column == 'jabber_feed')
	{
		$history = get_option ('jabber_feed_post_history');
		if (array_key_exists ($id, $history))
		{
			if (array_key_exists ('error', $history[$id]))
			{
				echo '<abbr title="' . $history[$id]['error'] . '">';
				echo '<em>Error on publication</em></abbr>';
			}
			else
			{
				echo '<abbr title="last update: ' . $history[$id]['updated'] . '">';
				echo $history[$id]['published'] . '</abbr>';
			}
		}
		else
			echo '<em>Not Published</em>';
	}
} // }}}

function jabber_feed_publish_button () // {{{
{
	?>
	<input type="submit" value="Publish on Jabber Node" name="publishjabber" class="button-secondary" />
	<?php
} // }}}

// Modify the Manage page.
add_filter('manage_posts_columns', 'jabber_feed_admin_columns');
add_action ('manage_posts_custom_column', 'jabber_feed_custom_column', 10, 2);
// TODO: implement action related to this button.
// add_action ('restrict_manage_posts', 'jabber_feed_publish_button');

/**********************\
// Autodiscovery link \\
\**********************/

// Runs when the template calls the wp_head function.
// Themes usually call this function. If not, you can do it manually with the Jabber Feed's templates.

// TODO: the autodiscovery may change when on a specific post? (link for the comments of given post only)...

function jabber_feed_header () // {{{
{
	if (is_single ())
		jabber_feed_display ('current', 'link');
	elseif (is_page ())
		jabber_feed_display ('pages', 'link');
	else
		jabber_feed_display ('posts', 'link');
} // }}}

add_action ('wp_head', 'jabber_feed_header');


/**********\
// Widget \\
\**********/

function jabber_feed_load_widget ()
{
	return register_widget ('jabber_feed_widget');
}

add_action ('widgets_init', 'jabber_feed_load_widget');

?>
