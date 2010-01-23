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
	function widget ($args, $instance)
	{
		extract ($args);
		$title = apply_filters('widget_title', $instance['title'] );

		echo $before_widget;
		if ($title)
			echo $before_title . $title . $after_title;

		// TODO: use the checkcases on the config tool.
		echo '<ul><li>';
		jabber_feed_display ($node = 'posts', $what = 'a', $text = 'TODO: Posts');
		echo '</li><li>';
		jabber_feed_display ($node = 'comments', $what = 'a', $text = 'TODO: Comments');
		echo '</li>';
		if (is_singular ())
		{
			$id = get_the_ID ();
			if (comments_open ($id) || count (get_approved_comments ($id) > 0))
			{
				echo '<li>';
				jabber_feed_display ($node = 'current', $what = 'a', $text = 'TODO: Comments of current post');
				echo '</li>';
			}
		}
		echo '</ul>';

		echo $after_widget;
	}

	function update ($new_instance, $old_instance)
	{
		$instance = $old_instance;

		$instance['title'] = strip_tags ($new_instance['title']);

		return $instance;
	}

	function form ($instance)
	{
		$defaults = array ('title' => 'Jabber feeds');
		$instance = wp_parse_args ((array) $instance, $defaults); ?>
		<p>
			<label for="<?php echo $this->get_field_id ('title'); ?>">Title:</label>
			<input id="<?php echo $this->get_field_id ('title'); ?>" name="<?php echo $this->get_field_name ('title'); ?>" value="<?php echo $instance['title']; ?>" style="width:100%;" />
		</p>
<?php
	}

}

?>
