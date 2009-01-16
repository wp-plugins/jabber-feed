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

		//$_socket = socket_create (AF_INET, SOCK_STREAM, SOL_TCP);
		$_socket = stream_socket_client ("tcp://" . $address . ":" . $this->port, $errno, $errstr, 2);
		if ($_socket === false)
		{
			$this->last_error = __('The socket could not be created.') . '<br />';
			//$this->last_error .= socket_strerror (socket_last_error ($_socket));
			$this->last_error .= "$errstr ($errno)";
			//socket_close ($_socket);
			fclose ($_socket);
			return false;
		}

		// I am generating a random port number 10 times among the ephemereal ports.
		// I won't check the local port range (/proc/sys/net/ipv4/ip_local_port_range on Linux) for now (TODO).
		// I will basically consider the range being between 32768 and 61000.
		/*$unbound = true;
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
		*/

		
		if (! stream_set_blocking ($_socket, 0))
		{
			$this->last_error = __('The socket could not be set in non-blocking mode.') . '<br />';
			$this->last_error .= "$errstr ($errno)";
			fclose ($_socket);
			return false;
		}

		$this->socket = $_socket;
		return true;
	} // }}}

	function read () // {{{
	{
		if ($this->socket != null)
		{
			//return socket_read ($this->socket, 100, PHP_BINARY_READ);
			//$received_data = fread ($this->socket, 100);
			$received_data = fread ($this->socket, 8192);
			// I read by block. Non-blocking mode does not seem to work in encrypted data...
			//$received_data = stream_get_contents ($this->socket, 1);
			if (strlen ($received_data) != 0)
				jabber_feed_log ("Received data: \n" . $received_data);
			return $received_data;
			//return fread ($this->socket, 100);
			//socket_recvfrom ($this->socket, $buf, 2000, MSG_DONTWAIT);
			//return $buf;
		}
		else
		{
			$this->last_error = __('Trying to read in a null socket.');
			jabber_feed_log ($this->last_error);
			return FALSE;
		}
	} // }}}

	function send ($data) // {{{
	{
		if ($this->socket == null)
		{
			$this->last_error = __('Trying to write in a null socket.');
			jabber_feed_log ($this->last_error);
			return FALSE;
		}

		$data_length = strlen ($data);
		$bytes_sent = 0;

		$timeout = 2;
		$last_update = time ();
		while ($bytes_sent < $data_length)
		{
			//$new_bytes_sent = socket_write ($this->socket, $data);
			$new_bytes_sent = fwrite ($this->socket, $data); //substr ($data, $bytes_sent));
			/* XXX: the sending over socket returns the number of *bytes*...
				But substr writes about start *character*. This is not an issue *currently* because "Before PHP 6, a character is the same as a byte".
				Yet in the future (PHP 6 so?), it can make an error if ever the $data is not fully sent in once, and it is stopped in the middle of a character (UTF-8 for instance, most common now). Of course, even in PHP6, this will be a rare case where we are pretty unlucky. Still it would be possible. */
			if ($new_bytes_sent === FALSE)
			{
				$this->last_error = __('Data could not be sent.') . '<br />';
				$this->last_error .= "$errstr ($errno)";
				//$this->last_error .= socket_strerror (socket_last_error ($this->socket));
				jabber_feed_log ("Error in sent stanza: \n" . $data);
				return FALSE;
			}
			elseif ($new_bytes_sent > 0)
			{
				//jabber_feed_log ("Sent " . $new_bytes_sent . " characters");
				$bytes_sent += $new_bytes_sent;
				$last_update = time ();
				continue;
			}
			elseif ($time () - $last_update > $timeout)
			{
				$this->last_error = __('Timeout during a data transfer');
				jabber_feed_log ("Timeout in sent stanza: \n" . $data);
				return FALSE;
			}
		}
		jabber_feed_log ("Sent stanza: \n" . $data);
		return TRUE;
	} // }}}

	function close () // {{{
	{
		if ($this->socket == null)
			return FALSE;
		
		//socket_shutdown ($this->socket, 2);
		//socket_close ($this->socket);
		fclose ($this->socket);
		return true;
	} // }}}

	function encrypt () // {{{
	{
		if ($this->socket == null)
		{
			$this->last_error = __('Trying to encrypt a null socket.');
			return FALSE;
		}

		stream_set_blocking ($this->socket, 1);
		/*if (stream_socket_enable_crypto ($this->socket, TRUE, STREAM_CRYPTO_METHOD_TLS_CLIENT))
		{
			stream_set_blocking ($this->socket, 0);
			return TRUE;
		} */
		if (stream_socket_enable_crypto ($this->socket, TRUE, STREAM_CRYPTO_METHOD_SSLv23_CLIENT))
		{
			// XXX: why does # STREAM_CRYPTO_METHOD_TLS_CLIENT does not work in Gmail?!
			// Probably feature not implemented.
			// So as a special workaround, I try SSL instead...
			// It seems to work on other servers (jabber.org works with both TLS and SSL).
			//stream_set_blocking ($this->socket, 0);
			if (! stream_set_blocking ($this->socket, 0))
			{
				$this->last_error = __('The socket could not be set in non-blocking mode after encryption.') . '<br />';
				$this->last_error .= "$errstr ($errno)";
				fclose ($this->socket);
				return FALSE;
			}
			return TRUE;
		}
		else
		{
			// If neither TLS not SSL worked...
			$this->last_error = __('TLS negotiation failed.') . '<br />';
			return FALSE;
		}
		/* There is 2 wrong returns: either FALSE, which means negotiation failed,
			or 0 if there isn't enough data and you should try again (only for non-blocking sockets).
			As this is a bot, and there is no human interaction, the second case is also wrong for us, so I don't distinguate them (but maybe would it be better for debugging?)...

			XXX: Note that I think that this implementation does not include the certificate validation (or else what happens if the validation fails, shouldn't it allow us to possibly validate manually the certificate by showing it to the user? This last point in particular is to check...
			*/
	} // }}}

} // }}}

?>
