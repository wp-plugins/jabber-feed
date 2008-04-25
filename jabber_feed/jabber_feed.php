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

require_once(dirname(__FILE__) . '/xmpp_stream.php');

///////////////////////
// Post Publication  //
///////////////////////


function xmpp_publish_post ($post_ID) // {{{
{
	$configuration = get_option ('jabber_feed_configuration');
	$blog_title = get_bloginfo ('name'); 

	$post = get_post ($post_ID, OBJECT);
	$post_title = $post->post_title;
	$post_author = get_userdata ($post->post_author)->display_name;
	$feed_title = '[' . $blog_title . "] " . $post_title . " (publisher: " . $post_author . ')';
	$feed_content = $post->post_content;
	$feed_excerpt = $post->post_excerpt;
	$link = $post->guid;
	$id = $post_ID . '_' . $post->post_name;

	$xs = new xmpp_stream ($configuration['node'],
		$configuration['domain'], $configuration['password'],
		'bot', $configuration['server'], $configuration['port']);
	
	$history = get_option('jabber_feed_history');

	if (! ($xs->connect () && $xs->authenticate () && $xs->bind ()
		&& $xs->session_establish ()
		&& $xs->notify ($configuration['pubsub_server'],
			$configuration['posts_node'], $id, $feed_title,
			$link, $feed_content, $feed_excerpt)
		&& $xs->quit ()))
	{
		echo '<div class="updated"><p>' . __('Jabber Feed error:') . '<br />';
		echo $xs->last_error . '</p></div>';
		$history[$post_ID] = FALSE;
	}
	else
	{
		$history[$post_ID] = array ('published' => date ('c'), 'updated' => date ('c'), 'id' => $id);
		update_option('jabber_feed_history', $history);
	}

	return $post_ID;
} // }}}

add_action ('publish_post', 'xmpp_publish_post');

///////////////////////////
// Comment Publication  //
//////////////////////////

function xmpp_publish_comment ($comment_ID, $status) // {{{
{
	if ($status == 1)
	{
		$configuration = get_option ('jabber_feed_configuration');
		$blog_title = get_bloginfo ('name'); 

		$comment = get_comment ($comment_ID, OBJECT);
		$post = get_post ($comment->comment_post_ID, OBJECT);
		$post_title = $post->post_title;
		$comment_author = $comment->comment_author;
		$feed_title = '[' . $blog_title . "]" . $post_title . " (commenter: " . $comment_author . ')';
		$feed_content = $comment->comment_content;
		$link = $post->guid;
		$id = $comment_ID;

		$xs = new xmpp_stream ($configuration['node'],
			$configuration['domain'], $configuration['password'],
			'bot', $configuration['server'], $configuration['port']);
		
		if (! ($xs->connect () && $xs->authenticate () && $xs->bind ()
			&& $xs->session_establish ()
			&& $xs->notify ($configuration['pubsub_server'],
				$configuration['comments_node'], $id, $feed_title,
				$link, $feed_content, '')
			&& $xs->quit ()))
		{
			echo '<div class="updated"><p>' . __('Jabber Feed error:') . '<br />';
			echo $xs->last_error . '</p></div>';
		}
			

	}

	return $comment_ID;
} // }}}

add_action ('comment_post', 'xmpp_publish_comment', 10, 2);

add_option('jabber_feed_configuration', array (), 'Configuration of the Jabber Feed plugin', 'yes');
add_option ('jabber_feed_history', array (), 'All information about fed posts, successes and failures', 'yes');

/**********************\
// Configuration Page \\
\**********************/
function jabber_feed_configuration_page () // {{{
{
	global $wpdb;
	if (isset($_POST['update_configuration']))
	{
		$configuration['node'] = strip_tags (trim($_POST['node']));
		$configuration['domain'] = strip_tags (trim ($_POST['domain']));
		$configuration['password'] = strip_tags (trim($_POST['password']));

		$posted_server = strip_tags (trim($_POST['server']));
		if ($posted_server == '')
			$configuration['server'] = $configuration['domain'];
		else
			$configuration['server'] = $posted_server;
		$posted_port = strip_tags (trim($_POST['port']));
		if ($posted_port == '' || ! is_numeric ($posted_port))
			$configuration['port'] = 5222;
		else
			$configuration['port'] = intval ($posted_port);

		$configuration['pubsub_server'] = strip_tags (trim($_POST['pubsub_server']));
		$configuration['posts_node'] = strip_tags (trim($_POST['posts_node']));
		$configuration['comments_node'] = strip_tags (trim($_POST['comments_node']));


		update_option('jabber_feed_configuration', $configuration);

		// Aknowledge the save.
		echo '<div class="updated"><p>' . __('Configuration saved') . '</p></div>';
	}
	else
	{
		// If we are just displaying the page we first load up the options array
		$configuration = get_option('jabber_feed_configuration');
	}
	//now we drop into html to display the option page form
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
				<p><em>These are advanced settings. If you don't understand them, they are probably useless and default values will be enough.</em></p>
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
				<legend><?php _e('PubSub Nodes') ?></legend>

				<p><label>
				<?php _e('Server') ?><br />
					<input name="pubsub_server"
						type="text"
						id="pubsub_server"
						value="<?php echo $configuration['pubsub_server']; ?>"
						size="60" />
				</label></p>

				<p><label>
				<?php _e('Node for Posts notification') ?><br />
					<input name="posts_node"
						type="text"
						id="posts_node"
						value="<?php echo $configuration['posts_node']; ?>"
						size="60" />
				</label></p>

				<p><label>
				<?php _e('Node for Comments notification') ?><br />
					<input name="comments_node"
						type="text"
						id="comments_node"
						value="<?php echo $configuration['comments_node']; ?>"
						size="60" />
				</label></p>
			</fieldset>

			<div class="submit">
				<input type="submit"
					name="update_configuration"
					value="<?php _e('Update') ?>"
					style="font-weight:bold;" />
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

/*******************\
// Management Page \\
\*******************/
// for each post in the list, you just add: date of publication OR X = failed publication OR '-' = never published, and a button to publish?
// what about changing also the bottom of posts?

function jabber_feed_admin_columns ($defaults) // {{{
{
    $defaults['jabber_feed'] = __('Jabber Feed');
    return $defaults;
} // }}}

function jabber_feed_custom_column ($column, $id) // {{{
{
	if ($column == 'jabber_feed')
	{
		$history = get_option('jabber_feed_history');
		if (array_key_exists ('$id', $history))
			if ($history[$id] === FALSE)
				echo 'X';
			else
				echo $history[$id];
		else
			echo '-';
	}
} // }}}

// Install the configuration and modify the manage page.
add_action ('admin_menu', 'jabber_feed_menu');
add_filter('manage_posts_columns', 'jabber_feed_admin_columns');
add_action ('manage_posts_custom_column', 'jabber_feed_custom_column', 10, 2);

?>
