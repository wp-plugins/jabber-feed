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

// This simplistic class creates a TCP/IPV4 socket in non-blocking mode.
// TODO: support ipv6?!

class my_socket // {{{
{
	public $server = '';
	public $port = '';

	public $last_error = '';

	private $socket = null;

	// All these functions return false when the operation did not succeed.

	function connect () // {{{
	{
		$address = gethostbyname ($this->server);
		$self_address = gethostbyname (parse_url (get_bloginfo ('url'), PHP_URL_HOST));
		// TODO: replace get_bloginfo by a portable function! This is a Wordpress' one.

		$_socket = socket_create (AF_INET, SOCK_STREAM, SOL_TCP);
		if ($_socket === false)
		{
			$this->last_error = __('The socket could not be created.') . '<br />';
			$this->last_error .= socket_strerror (socket_last_error ($_socket));
			socket_close ($_socket);
			return false;
		}

		// I am generating a random port number 10 times among the ephemereal ports.
		// I won't check the local port range (/proc/sys/net/ipv4/ip_local_port_range on Linux) for now (TODO).
		// I will basically consider the range being between 32768 and 61000.
		$unbound = true;
		for ($i = 1; $i <= 10; $i++)
		{
			// As of PHP >= 4.2.0, no need to run srand!
			$local_port = rand (32768, 61000);
			if (socket_bind ($_socket, $self_address, $local_port))
			{
				$unbound = false;
				break;
			}
		}

		if ($unbound)
		{
			$this->last_error = __('The socket could not be bound.') . '<br />';
			$this->last_error .= socket_strerror (socket_last_error ($_socket));
			socket_close ($_socket);
			return false;
		}

		socket_set_block ($_socket);
		if (! socket_connect ($_socket, $address, $this->port))
		{
			$this->last_error = __('The socket could not connect to remote server.') . '<br />';
			$this->last_error .= socket_strerror (socket_last_error ($_socket));
			socket_close ($_socket);
			return false;
		}

		// In non blocking mode, the connect returns anyway always false
		// with the error "Operation now in progress" [115].
		// So I set non block after the connect!
		// This mode enables to set a timeout.
		if (! socket_set_nonblock ($_socket))
		{
			$this->last_error = __('The socket could not be set in non-blocking mode.') . '<br />';
			$this->last_error .= socket_strerror (socket_last_error ($_socket));
			socket_close ($_socket);
			return false;
		}

		$this->socket = $_socket;
		return true;
	} // }}}

	function read () // {{{
	{
		if ($this->socket != null)
		{
			return socket_read ($this->socket, 100, PHP_BINARY_READ);
			//socket_recvfrom ($this->socket, $buf, 2000, MSG_DONTWAIT);
			//return $buf;
		}
		else
		{
			$this->last_error = __('Trying to read in a null socket.');
			return FALSE;
		}
	} // }}}

	function send ($data) // {{{
	{
		if ($this->socket == null)
		{
			$this->last_error = __('Trying to write in a null socket.');
			return FALSE;
		}

		$data_length = strlen ($data);
		$bytes_sent = 0;

		$timeout = 2;
		$last_update = time ();
		while ($bytes_sent < $data_length)
		{
			$new_bytes_sent = socket_write ($this->socket, $data);
			if ($new_bytes_sent === FALSE)
			{
				$this->last_error = __('Data could not be sent.') . '<br />';
				$this->last_error .= socket_strerror (socket_last_error ($this->socket));
				return FALSE;
			}
			elseif ($new_bytes_sent > 0)
				$last_update = time ();
			elseif ($time () - $last_update > $timeout)
			{
				$this->last_error = __('Timeout during a data transfer');
				return FALSE;
			}

			$bytes_sent += $new_bytes_sent;
		}
		return TRUE;
	} // }}}

	function close () // {{{
	{
		if ($this->socket == null)
			return FALSE;
		
		socket_shutdown ($this->socket, 2);
		socket_close ($this->socket);
		return true;
	} // }}}

} // }}}

?>
