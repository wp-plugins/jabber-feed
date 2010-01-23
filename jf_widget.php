<?php
/************ Jabber Feed ***************\

Jabber Feed is a plugin for the Wordpress diary engine.

    Copyright 2008 Jehan Hysseo  (email : jehan at zemarmot.net)

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
\******************************************/

require_once(dirname(__FILE__) . '/templates.php');

class jabber_feed_widget extends WP_Widget
{
	function __construct () // {{{
	{
		/* Widget settings. */
		$widget_ops = array( 'classname' => 'jabber_feed_widget', 'description' => 'Widget displaying the Jabber feed subscription links.' );

		/* Widget control settings. */
		/*$control_ops = array( 'width' => 300, 'height' => 350, 'id_base' => 'example-jabber-feed_widget' ); */

		/* Create the widget. */
		parent::__construct ('jabber-feed_widget', 'Jabber Feed Widget', $widget_ops); //, $control_ops );
	} // }}}

	function jabber_feed_widget ()
	{
		$this->__construct ();
		register_shutdown_function (array (&$this, "__destruct"));
	}

	function __destruct () // {{{
	{
	} // }}}

	// This function deals with the actual display.
	function widget ($args, $instance) // {{{
	{
		extract ($args);
		$title = apply_filters('widget_title', $instance['title'] );

		echo $before_widget;
		if ($title)
			echo $before_title . $title . $after_title;

		$configuration = get_option ('jabber_feed_configuration');
		echo '<ul>';
		if (!empty ($configuration['publish_posts']))
		{
			$post_text = $instance['post_text'];
			echo '<li>';
			jabber_feed_display ($node = 'posts', $what = 'a', $text = $post_text);
			echo '</li>';
		}
		if (!empty ($configuration['publish_comments']))
		{
			$comment_text = $instance['comment_text'];
			echo '<li>';
			jabber_feed_display ($node = 'comments', $what = 'a', $text = $comment_text);
			echo '</li>';
			if (is_single ())
			{
				if (comments_open ($id) || count (get_approved_comments ($id) > 0))
				{
					$current_text = $instance['current_text'];
					$id = get_the_ID ();
					$current_post = get_post ($id);
					$post_title = $current_post->post_title;
					$current_text = strip_tags (str_ireplace ('[title]', $post_title, $current_text));
					echo '<li>';
					jabber_feed_display ($node = 'current', $what = 'a', $text = $current_text);
					echo '</li>';
				}
			}
		}
		echo '</ul>';

		echo $after_widget;
	} // }}}

	function update ($new_instance, $old_instance) // {{{
	{
		$instance = $old_instance;

		$instance['title'] = strip_tags ($new_instance['title']);
		$instance['post_text'] = strip_tags ($new_instance['post_text']);
		$instance['comment_text'] = strip_tags ($new_instance['comment_text']);
		$instance['current_text'] = strip_tags ($new_instance['current_text']);

		return $instance;
	} // }}}

	function form ($instance) // {{{
	{
		$configuration = get_option ('jabber_feed_configuration');
		$defaults = array ('title' => 'Jabber feeds', 'post_text' => 'Entries (XMPP feed)',
			'comment_text' => 'Comments (XMPP feed)',
			'current_text' => "Comments of \"[title]\" (XMPP feed)");
		$instance = wp_parse_args ((array) $instance, $defaults); ?>
		<p>
			<label for="<?php echo $this->get_field_id ('title'); ?>">Title:</label>
			<input id="<?php echo $this->get_field_id ('title'); ?>" name="<?php echo $this->get_field_name ('title'); ?>" value='<?php echo $instance['title']; ?>' style="width:100%;" />
		</p>
		<p>
			<label for="<?php echo $this->get_field_id ('post_text'); ?>">Text for the link of posts feed:</label>
			<input id="<?php echo $this->get_field_id ('post_text'); ?>" name="<?php echo $this->get_field_name ('post_text'); ?>" value='<?php echo $instance['post_text']; ?>' style="width:100%;" <?php
			if (empty ($configuration['publish_posts']))
				echo 'disabled="disabled"';
		?>/>
		</p>
		<p>
			<label for="<?php echo $this->get_field_id ('comment_text'); ?>">Text for the link of comments feed:</label>
			<input id="<?php echo $this->get_field_id ('comment_text'); ?>" name="<?php echo $this->get_field_name ('comment_text'); ?>" value='<?php echo $instance['comment_text']; ?>' style="width:100%;" <?php 
			if (empty ($configuration['publish_comments']))
				echo 'disabled="disabled"';
			?>/>
		</p>
		<p>
			<label for="<?php echo $this->get_field_id ('current_text'); ?>">Text for the link of comments of current post feed (<em>[title]</em> will be replaced by the title of the post):</label>
			<input id="<?php echo $this->get_field_id ('current_text'); ?>" name="<?php echo $this->get_field_name ('current_text'); ?>" value='<?php echo $instance['current_text']; ?>' style="width:100%;" <?php
			if (empty ($configuration['publish_comments']))
				echo 'disabled="disabled"';
			?>/>
		</p>
<?php
	} // }}}

}

?>
