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
*/

require_once('Auth/SASL/DigestMD5.php');
require_once(dirname(__FILE__) . '/my_socket.php');

class xmpp_stream // {{{
{
	public $node = '';
	public $domain = '';
	public $password = '';
	public $resource = '';
	// real jid obtained after authentication.
	private $jid = '';

	public $server = '';
	public $port = '';

// If nothing happens on the stream after 5 seconds, I shutdown.
	private $timeout = 5;
	private $socket = null;
	public $last_error = '';

	// Known authentication mechanism.
	// The key is the mechanism name, and the value is the preference.
	// The more securized, the preferred mechanism...
	// For now will consider only the digest-md5 authentication.
	private $known_auth = array ('DIGEST-MD5' => 10);
		//, 'PLAIN' => 4);
	private $chosen_mechanism = '';

	private $must_close = false;
	private $current_cdata = '';
	private $features = array ();
	private $ids = array ();
	private $stack = array ();

	// FLAGS //
	private $flags = array ();

	function __construct ($node, $domain, $password, $resource = 'bot',
		$server = '', $port = 5222) // {{{
	{
		$this->node = $node;
		$this->domain = $domain;
		$this->password = $password;
		$this->resource = $resource;
		if ($server == '')
			$this->server = $domain;
		else
			$this->server = $server;
		$this->port = $port;
	} // }}}

	// For backwards compatibility in php4.
	function xmpp_stream ($node, $domain, $password, $resource,
		$server = '', $port = 5222) // {{{
	{
		$this->__construct ($node, $domain, $password, $server, $port);
		register_shutdown_function (array (&$this, "__destruct"));
	} // }}}

	function __destruct () // {{{
	{
		if ($this->flags['connected'])
			$this->quit ();
	} // }}}

	// All these functions return false when the operation did not succeed.

	function connect () // {{{
	{
		if (array_key_exists ('connected', $this->flags))
			return true;

		$this->socket = new my_socket ();
		//$_socket = $this->socket;
		$this->socket->server = $this->server;
		$this->socket->port = $this->port;

		if (! $this->socket->connect ())
		{
			$this->last_error = $this->socket->last_error;
			return false;
		}

		$this->flags['connected'] = true;
		return true;
	} // }}}
	
	function quit () // {{{
	{
		// through stack, close all tags!
		$this->socket->close ();
		unset ($this->flags);
		unset ($this->stack);
		return true;
	} // }}}

	function authenticate () // {{{
	{
		$stream_begin = "<stream:stream xmlns='jabber:client'
			xmlns:stream='http://etherx.jabber.org/streams'
			to='" . $this->domain .
			"' version='1.0'>";

		if (! $this->socket->send ($stream_begin))
		{
			$this->last_error = __('Stream initiate failure.') . '<br />';
			$this->last_error .= $this->socket->last_error;
			$this->quit ();
			return false;
		}

		return ($this->process_read ("authentication_start_handler",
			"authentication_end_handler", 'authenticated'));

	} // }}}

	function bind () // {{{
	{
		if (! array_key_exists ('connected', $this->flags))
		{
			$this->last_error = 'Bind try while not connected';
			return false;
		}
		elseif (array_key_exists ('bound', $this->flags))
			return true;

		$stream_begin = "<stream:stream xmlns='jabber:client'
			xmlns:stream='http://etherx.jabber.org/streams'
			to='" . $this->domain .
			"' version='1.0'>";

		//$_socket = $this->socket;
		if (! $this->socket->send ($stream_begin))
		{
			$this->last_error = __('Binding failure.') . '<br />';
			$this->last_error .= $this->socket->last_error;
			$this->quit ();
			return false;
		}
		//elseif (array_key_exists ('bind', $this->features))
			return ($this->process_read ("binding_start_handler",
				"binding_end_handler", 'bound'));
		//else
		{
			$this->last_error = 'Bind feature not available on the remote server.';
			return false;
		}
	} // }}}

	function session_establish () // {{{
	{
		if (! array_key_exists ('bound', $this->flags))
		{
			$this->last_error = 'Session establishment try while not bound.';
			return false;
		}
		if (array_key_exists ('session', $this->flags))
			return true;
		elseif (array_key_exists ('SESSION', $this->features))
		{
			$id = time ();
			$this->ids['session'] = 'session' . $id;
			$configuration = get_option ('jabber_feed_configuration');
			$message_session = "<iq to='" . $configuration['domain'] ."' type='set' id='session" . $id . "'>";
			$message_session .= "<session xmlns='urn:ietf:params:xml:ns:xmpp-session'/></iq>";
			$this->socket->send ($message_session);

			return ($this->process_read ("session_start_handler",
				"session_end_handler", 'session'));
		}
		else
		{
			$this->last_error = 'Session feature not available on the remote server.';
			return false;
		}
	} // }}}

	function notify ($server, $node, $id, $title, $link,
		$content = '', $excerpt = '') // {{{
	{
		if (version_compare (phpversion (), '5') == -1)
			$date = date ('Y-m-d\TH:i:s+Z'); // RFC3339 for PHP4 -> issue with positive timezone offset! TODO
		else
			$date = date ('c'); // in PHP5 only! ISO 8601 = RFC3339

		$iq_id = time ();
		$this->ids['publish'] = 'publish' . $iq_id;

		$message = "<iq type='set' from='" . $this->jid . "' ";
		$message .= "to='" . $server . "' id='publish" . $iq_id . "'>";
		$message .= "<pubsub xmlns='http://jabber.org/protocol/pubsub'>";
		$message .= "<publish node='" . $node;
		$message .= "'><item id='item" . $id . "'><entry xmlns='http://www.w3.org/2005/Atom'>";
		$message .= "<title>" . $title . "</title>";
		if ($excerpt !== '')
			$message .= "<summary>" . $excerpt . "</summary>";
		else
		{
			//$message .= '<content type="xhtml">' . 'n&oelig;uds de publication' . '</content>';
			//$message .= '<content type="xhtml">' . $content . '</content>';
			$message .= '<content type="xhtml"><div xmlns="http://www.w3.org/1999/xhtml"><![CDATA[' . $content;
			$message .= ']]></div></content>';
		}
        	$message .= '<link rel="alternate" type="text/html" href="';
		$message .= $link . '"/>';
	        $message .= "<id>" . $id . "</id>";
        	$message .= "<published>" . $date . "</published><updated>" . $date . "</updated>";
		$message .= "</entry></item></publish></pubsub></iq>";

		//echo htmlentities ($message); // for test
		//echo '<br/>';
		if (! $this->socket->send ($message))
		{
			$this->last_error = __('Notification failure.') . '<br />';
			$this->last_error .= $this->socket->last_error;
			$this->quit ();
			return FALSE;
		}


		return ($this->process_read ("notification_start_handler",
			"notification_end_handler", 'published'));
	} // }}}


// parse data from the socket according to given handlers until $flag is true.
	private function process_read ($start_element_handler,
		$end_element_handler, $flag) // {{{
	{
		$xml_parser = xml_parser_create();
		xml_set_element_handler ($xml_parser,
			array (&$this, $start_element_handler),
			array (&$this, $end_element_handler));
		xml_set_character_data_handler ($xml_parser, array (&$this, "cdata_handler"));

		$last_update = time ();
		while (true)
		{
			if (array_key_exists ($flag, $this->flags)) // success!
				break;
			elseif ($this->must_close) // semantic error
			{
				$this->last_error = __('Unexpected error.');
				break;
			}

			$data = $this->socket->read ();

			/*if ($data === FALSE)
			{
				$this->last_error = $_socket->last_error;
				break;
			}*/
			if (time () - $last_update > $this->timeout)
			{
				$this->last_error =  __('Timeout of ') . ' ';
				$this->last_error .= $this->timeout ;
				$this->last_error .= ' ' . __('seconds during authentication.');
				break;
			}
			elseif (strlen ($data) === 0)
				continue;
			elseif (!xml_parse($xml_parser, $data, FALSE))
			{
				$this->last_error = sprintf("XML parsing error: %s at line %d",
					xml_error_string(xml_get_error_code ($xml_parser)),
					xml_get_current_line_number ($xml_parser));
				break;
			}
			else // data read on the socket and processed!
			{
				$xmpp_last_update = time ();
				continue;
			}
		}

		xml_parser_free ($xml_parser);
		if (array_key_exists ($flag, $this->flags))
			return true;
		else
			return false;
	} // }}}

	///////////////////////
	// All the handlers! //
	///////////////////////

	private function cdata_handler ($parser, $data) // {{{
	{
		$this->current_cdata .= $data;
	} // }}}

	private function common_start_handler ($name) // {{{
	{
		//echo $name . '<br/>';
		$this->stack[] = $name; // useless currently. TODO
		$this->current_cdata = '';
	} // }}}

	private function common_end_handler () // {{{
	{
		array_pop ($this->stack);
		return;
	} // }}}

	private function authentication_start_handler ($parser, $name, $attrs) // {{{
	{
		$this->common_start_handler ($name);
	} // }}}

	private function authentication_end_handler ($parser, $name) // {{{
	{
		$this->common_end_handler ();
		if ($name == 'MECHANISM' && array_key_exists ($this->current_cdata, $this->known_auth))
		{
			if (empty ($this->chosen_mechanism) || $this->_known_auth[$this->current_cdata] > $this->known_auth[$this->chosen_mechanism])
				$this->chosen_mechanism = $this->current_cdata;
			return;
		}
		elseif ($name == 'CHALLENGE'
			&& ! array_key_exists ('challenged_once', $this->flags))
		{
			// I get the challenge from cdata.
			// I decode it (base64).
			$decoded_challenge = base64_decode ($this->current_cdata);
			$sasl = new Auth_SASL_DigestMD5 ();
			$uncoded = $sasl->getResponse ($this->node, $this->password, $decoded_challenge, $this->domain, 'xmpp');
			$coded = base64_encode ($uncoded);
			$response = '<response xmlns=\'urn:ietf:params:xml:ns:xmpp-sasl\'>' . $coded . '</response>';

			if (! $this->socket->send ($response))
			{
				$this->last_error = __('Authentication failure.') . '<br />';
				$this->last_error .= $_socket->last_error;
				$this->must_close = true;
				return;
			}
			
			$this->flags['challenged_once'] = true;
			return;
		}
		elseif ($name == 'CHALLENGE')
		{
			$response = '<response xmlns=\'urn:ietf:params:xml:ns:xmpp-sasl\'/>';
			if (! $this->socket->send ($response))
			{
				$this->last_error = __('Authentication failure.') . '<br />';
				$this->last_error .= $_socket->last_error;
				$this->must_close = true;
				return;
			}
		}
		elseif ($name == 'FAILURE' || $name == 'STREAM:STREAM')
		{
			$this->socket->send ('</stream:stream>');
			$this->must_close = true;;
			return;
		}
		elseif ($name == 'SUCCESS')
			$this->flags['authenticated'] = true;
		elseif ($name == 'STREAM:FEATURES')
		{
			if ($this->chosen_mechanism == '')
				$this->must_close = true;
			else
			{
				$mechanism = '<auth xmlns=\'urn:ietf:params:xml:ns:xmpp-sasl\'';
				$mechanism .= ' mechanism=\'' . $this->chosen_mechanism . '\' />';

				$this->socket->send ($mechanism);
			}
			return;
		}

	} // }}}

	private function binding_start_handler ($parser, $name, $attrs) // {{{
	{
		if ($name == 'STREAM:FEATURES')
			$this->flags['features'] = true;
		elseif ($name == 'IQ' && $attrs['TYPE'] == 'result' && $this->ids['bind'] == $attrs['ID'])
			$this->flags['resource'] = true;
		elseif ($name == 'IQ' && $attrs['TYPE'] == 'error' && $this->ids['bind'] == $attrs['ID'])
			$this->must_close = true;
			
		$this->common_start_handler ($name);
	} // }}}

	private function binding_end_handler ($parser, $name) // {{{
	{
		$this->common_end_handler ();
		if ($name == 'STREAM:FEATURES')
		{
			unset ($this->flags['features']);
			if (array_key_exists ('BIND', $this->features))
			{
				$id = time ();
				$message = "<iq type='set' id='" . $id . "'><bind xmlns='urn:ietf:params:xml:ns:xmpp-bind'>";
				$message .= "<resource>" . $this->resource . $id . "</resource></bind></iq>";
				$this->ids['bind'] = $id;
				$this->socket->send ($message);
			}
			else 
				$this->must_close = true;
		}
		elseif (array_key_exists ('features', $this->flags))
			$this->features[$name] = true;
		elseif ($name == 'JID' && array_key_exists ('resource', $this->flags))
			$this->jid = $this->current_cdata;
		elseif ($name == 'IQ' && array_key_exists ('resource', $this->flags))
		{
			unset ($this->flags['resource']);
			$this->flags['bound'] = true;
		}

	} // }}}

	private function session_start_handler ($parser, $name, $attrs) // {{{
	{
		if ($name == 'IQ' && $attrs['TYPE'] == 'result' && $this->ids['session'] == $attrs['ID'])
			$this->flags['session'] = true;
		elseif ($name == 'IQ' && $attrs['TYPE'] == 'error' && $this->ids['session'] == $attrs['ID'])
			$this->must_close = true;
			
		$this->common_start_handler ($name);
	} // }}}

	private function session_end_handler ($parser, $name) // {{{
	{
		$this->common_end_handler ();
	} // }}}

	private function notification_start_handler ($parser, $name, $attrs) // {{{
	{
		if ($name == 'IQ' && $attrs['TYPE'] == 'result' && $this->ids['publish'] == $attrs['ID'])
			$this->flags['published'] = true;
		elseif ($name == 'IQ' && $attrs['TYPE'] == 'error' && $this->ids['publish'] == $attrs['ID'])
			$this->must_close = true;
		$this->common_start_handler ($name);
	} // }}}
	
	private function notification_end_handler () // {{{
	{
		$this->common_end_handler ();
	} // }}}
} // }}}

?>
