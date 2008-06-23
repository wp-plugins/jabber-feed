<?php
/************ Jabber Feed ***************\
{{{
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
}}}
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
	private $socket = null;

	// If nothing happens on the stream after 5 seconds, I shutdown.
	private $timeout = 5;
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
		if (array_key_exists ('connected', $this->flags))
			$this->socket->close ();
		unset ($this->flags);
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
		if (! array_key_exists ('connected', $this->flags)
			|| ! array_key_exists ('authenticated', $this->flags))
		{
			$this->last_error = 'Bind try while not connected or authenticated.';
			return false;
		}
		elseif (array_key_exists ('bound', $this->flags))
			return true;

		$stream_begin = "<stream:stream xmlns='jabber:client'
			xmlns:stream='http://etherx.jabber.org/streams'
			to='" . $this->domain .
			"' version='1.0'>";

		if (! $this->socket->send ($stream_begin))
		{
			$this->last_error = __('Binding failure.') . '<br />';
			$this->last_error .= $this->socket->last_error;
			$this->quit ();
			return false;
		}
		//elseif (array_key_exists ('bind', $this->features)) // TODO
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
			//$this->last_error = 'Session feature not available on the remote server.';
			// if the server does not support session, so we just continue without session establishment!
			return true;
	} // }}}

	function notify ($server, $node, $id, $title, $link,
		$content = '', $excerpt = '') // {{{
	{
		if (! create_leaf ($server, $node))
			return false;
			
		if (version_compare (phpversion (), '5') == -1)
		{
			if (intval (date ('Z')) < 0)
				$date = date ('Y-m-d\TH:i:sZ'); // RFC3339 for PHP4 
			else
				$date = date ('Y-m-d\TH:i:s+Z'); 
		}
		else
			$date = date ('c'); // in PHP5 only! ISO 8601 = RFC3339

		$iq_id = time () . rand (); // Is it random enough? Probably for such use...
		$this->ids['publish'] = 'publish' . $iq_id;

		$message = "<iq type='set' from='" . $this->jid . "' ";
		$message .= "to='" . $server . "' id='publish" . $iq_id . "'>";
		$message .= "<pubsub xmlns='http://jabber.org/protocol/pubsub'>";
		$message .= "<publish node='" . $node;
		$message .= "'><item id='" . $id . "'><entry xmlns='http://www.w3.org/2005/Atom'>";
		$message .= "<title>" . $title . "</title>";
		if ($excerpt !== '')
			$message .= "<summary>" . $excerpt . "</summary>";
		else
		{
			// I use CDATA because '&' are illegal in XML, like in the character entity "&oelig;".
			// Isn't it a bug to report to ejabberd?
			//$message .= '<content type="xhtml">' . 'n&oelig;uds de publication' . '</content>';
			// TODO: in fact, only &amp; is possible! Other must be utf8.
			$message .= '<content type="xhtml"><div xmlns="http://www.w3.org/1999/xhtml"><![CDATA[' . $content;
			$message .= ']]></div></content>';
		}

      $message .= '<link rel="alternate" type="text/html" href="';
		$message .= $link . '"/>';
		$message .= "<id>" . $id . "</id>";
		$message .= "<published>" . $date . "</published><updated>" . $date . "</updated>";
		// TODO: what about modified items for 'published' field??
		$message .= "</entry></item></publish></pubsub></iq>";

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

	function delete_item ($server, $node, $id) // {{{
	{
		$iq_id = time ();
		$this->ids['delete'] = 'retract' . $iq_id;

		$message = "<iq type='set' from='" . $this->jid . "' ";
		$message .= "to='" . $server . "' id='retract" . $iq_id . "'>";
		$message .= "<pubsub xmlns='http://jabber.org/protocol/pubsub'>";
		$message .= "<retract node='" . $node . "'><item id='";
		$message .= $id . "' /></retract></pubsub></iq>";

		if (! $this->socket->send ($message))
		{
			$this->last_error = __('Item deletion failure.') . '<br />';
			$this->last_error .= $this->socket->last_error;
			$this->quit ();
			return FALSE;
		}

		return ($this->process_read ("item_deletion_start_handler",
			"item_deletion_end_handler", 'item_deleted'));
	} // }}}

	/*function delete_container ($server, $node, $id) // {{{
	{
		$iq_id = time ();
		$this->ids['delete'] = 'delete' . $iq_id;

		$message = "<iq type='set' from='" . $this->jid . "' ";
		$message .= "to='" . $server . "' id='delete" . $iq_id . "'>";
		$message .= "<pubsub xmlns='http://jabber.org/protocol/pubsub#owner'>";
		$message .= "<delete node='" . $node . "' />";
		$message .= "</pubsub></iq>";

		if (! $this->socket->send ($message))
		{
			$this->last_error = __('Notification failure.') . '<br />';
			$this->last_error .= $this->socket->last_error;
			$this->quit ();
			return FALSE;
		}

		return ($this->process_read ("notification_start_handler",
			"notification_end_handler", 'published'));
	} // }}} */

	function create_leaf ($server, $node) // {{{
	{
		if ($node == '')
		{
			$this->last_error = __('Empty node. No instant node supported.') . $node . '".';
			return false;
		}

		$node_type = $this->node_type ($server, $node);

		if ($node_type == 'leaf')
			return true;
		elseif ($node_type == 'collection')
		{
			$this->last_error = __('This node already exists but is a collection node: "') . $node . '".';
			return false;
		}

		$subnode = $this->subnode ($node);
		if ($subnode != false && $this->create_collection ($server, $subnode))
		{
			unset ($this->flags['collection_created']);
			$iq_id = time ();
			$this->ids['leaf'] = 'create' . $iq_id;

			$message = "<iq type='set' from='" . $this->jid . "' ";
			$message .= "to='" . $server . "' id='create" . $iq_id . "'>";
			$message .= "<pubsub xmlns='http://jabber.org/protocol/pubsub'>";
			$message .= "<create node='" . $node . "'/><configure/>";
			$message .= "</pubsub></iq>";

			if (! $this->socket->send ($message))
			{
				$this->last_error = __('Leaf creation failure:') . '<br />';
				$this->last_error .= $this->socket->last_error;
				$this->quit ();
				return FALSE;
			}

			return ($this->process_read ("leaf_creation_start_handler",
				"leaf_creation_end_handler", 'leaf_created'));
		}
		else
			return false;
	} // }}}

	function create_collection ($server, $node) // {{{
	{
		if ($node == '')
		{
			$this->last_error = __('Empty node. No instant node supported.') . $node . '".';
			return false;
		}

		$node_type = $this->node_type ($server, $node);

		if ($node_type == 'collection')
			return true;
		elseif ($node_type == 'leaf')
		{
			$this->last_error = __('This node already exists but is a leaf node: "') . $node . '".';
			return false;
		}

		$subnode = $this->subnode ($node);
		if ($subnode != false && $this->create_collection ($server, $subnode))
		{
			unset ($this->flags['collection_created']);
			$iq_id = time () . rand ();
			$this->ids['collection'] = 'create' . $iq_id;

			$message = "<iq type='set' from='" . $this->jid . "' ";
			$message .= "to='" . $server . "' id='create" . $iq_id . "'>";
			$message .= "<pubsub xmlns='http://jabber.org/protocol/pubsub'>";
			$message .= "<create node='" . $node . "'/><configure><x type='submit' xmlns='jabber:x:data'>";
			$message .= "<field var='FORM_TYPE' type='hidden'><value>http://jabber.org/protocol/pubsub#node_config</value></field>";
			$message .= "<field var='pubsub#node_type'><value>collection</value></field>";
			$message .= "</x></configure></pubsub></iq>";

			if (! $this->socket->send ($message))
			{
				$this->last_error = __('Collection node creation failure:') . '<br />';
				$this->last_error .= $this->socket->last_error;
				$this->quit ();
				return FALSE;
			}

			return ($this->process_read ("collection_creation_start_handler",
				"collection_creation_end_handler", 'collection_created'));
		}
		else
			return false;
	} // }}}

	function node_type ($server, $node) // return false if not existing, "leaf" and "collection" otherwise! // {{{
	{
		$iq_id = time () . rand ();
		$this->ids['node_info'] = 'info' . $iq_id;

		$query_info = "<iq type='get' from='" . $jid . "' to='" . $server . "' id='info" . $iq_id;
		$query_info .= "'><query xmlns='http://jabber.org/protocol/disco#info' node='";
		$query_info .= $node . "'/></iq>";

		if (! $this->socket->send ($query_info))
		{
			$this->last_error = __('Node information discovery failure:') . '<br />';
			$this->last_error .= $this->socket->last_error;
			$this->quit ();
			return FALSE;
		}

		$this->process_read ("node_info_start_handler",
			"node_info_end_handler", 'node_type');

		return $this->flags['node_type'];
	} // }}}

// this function returns "root1/root2" if you give it "root1/root2/node" and return false if you give ''
	private function subnode ($node) // {{{
	{
		$pattern_root = '/^\/*$/';
		if (preg_match ($pattern_root, $node) == 0)
			return false;

		$pattern_root = '/^\/*[^\/]+\/*$/';
		if (preg_match ($pattern_root, $node) == 0)
			return '/';

		$pattern = '/^(.+[^\/])(\/+[^\/]+\/*)$/';
		return (preg_replace ($pattern, '${1}', $node, 1));
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
				$this->last_error = __('Unexpected error: ') . $this->last_error;
				break;
			}

			$data = $this->socket->read ();

			if (time () - $last_update > $this->timeout)
			{
				$this->last_error =  __('Timeout of ') . ' ';
				$this->last_error .= $this->timeout ;
				$this->last_error .= ' ' . __('seconds.');
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
			else // data read on the socket and processed in the handlers if needed!
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
		$this->current_cdata = '';
	} // }}}

	private function common_end_handler () // {{{
	{
		return;
	} // }}}

// Authentication //

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
			unset ($this->flags['challenged_once']);
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
			$this->last_error = __('Authentication failure: wrong username or password.') . '<br />';
			$this->must_close = true;;
			return;
		}
		elseif ($name == 'SUCCESS')
			$this->flags['authenticated'] = true;
		elseif ($name == 'STREAM:FEATURES')
		{
			if ($this->chosen_mechanism == '')
			{
				$this->last_error = __('No compatible authentication mechanism');
				$this->must_close = true;
			}
			else
			{
				$mechanism = '<auth xmlns=\'urn:ietf:params:xml:ns:xmpp-sasl\'';
				$mechanism .= ' mechanism=\'' . $this->chosen_mechanism . '\' />';

				$this->socket->send ($mechanism);
			}
			return;
		}

	} // }}}

// Binding Resource //

	private function binding_start_handler ($parser, $name, $attrs) // {{{
	{
		if ($name == 'STREAM:FEATURES')
			$this->flags['features'] = true;
		elseif ($name == 'IQ' && $attrs['TYPE'] == 'result' && $this->ids['bind'] == $attrs['ID'])
		{
			unset ($this->ids['bind']);
			$this->flags['resource'] = true;
		}
		elseif ($name == 'IQ' && $attrs['TYPE'] == 'error' && $this->ids['bind'] == $attrs['ID'])
		{
			unset ($this->ids['bind']);
			$this->flags['resource_error'] = true;
		}
		elseif ($name == 'ERROR' && array_key_exists ('resource_error', $this->flags))
		{
			unset ($this->flags['resource_error']);
			$this->last_error = __('Resource binding returned an error of type "') . $attrs['TYPE'] . '".';
			$this->must_close = true;
		}
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
				if (! $this->socket->send ($message))
				{
					$this->last_error = __('Failure during binding.') . '<br />';
					$this->last_error .= $this->socket->last_error;
					$this->must_close = true;
				}
			}
			else 
			{
				$this->last_error = __('Bind feature not available.');
				$this->must_close = true;
			}
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

// Session //

	private function session_start_handler ($parser, $name, $attrs) // {{{
	{
		if ($name == 'IQ' && $attrs['TYPE'] == 'result' && $this->ids['session'] == $attrs['ID'])
		{
			unset ($this->ids['session']);
			$this->flags['session'] = true;
		}
		elseif ($name == 'IQ' && $attrs['TYPE'] == 'error' && $this->ids['session'] == $attrs['ID'])
		{
			unset ($this->ids['session']);
			$this->flags['session_error'] = true;
		}
		elseif ($name == 'ERROR' && array_key_exists ('session_error', $this->flags))
		{
			unset ($this->flags['session_error']);
			$this->last_error = __('Session establishment returned an error of type "') . $attrs['TYPE'] . '".';
			$this->must_close = true;
		}
			
		$this->common_start_handler ($name);
	} // }}}

	private function session_end_handler ($parser, $name) // {{{
	{
		$this->common_end_handler ();
	} // }}}

// Pubsub Notification //

	private function notification_start_handler ($parser, $name, $attrs) // {{{
	{
		if ($name == 'IQ' && $attrs['TYPE'] == 'result' && $this->ids['publish'] == $attrs['ID'])
		{
			unset ($this->ids['publish']);
			$this->flags['published'] = true;
		}
		elseif ($name == 'IQ' && $attrs['TYPE'] == 'error' && $this->ids['publish'] == $attrs['ID'])
		{
			unset ($this->ids['publish']);
			$this->flags['publish_error'] = true;
		}
		elseif ($name == 'ERROR' && array_key_exists ('publish_error', $this->flags))
		{
			unset ($this->flags['publish_error']);
			$this->last_error = __('Publication returned an error of type "') . $attrs['TYPE'] . '".';
			$this->must_close = true;
		}

		$this->common_start_handler ($name);
	} // }}}
	
	private function notification_end_handler () // {{{
	{
		$this->common_end_handler ();
	} // }}}

// Item deletion //

	private function item_deletion_start_handler ($parser, $name, $attrs) // {{{
	{
		if ($name == 'IQ' && $attrs['TYPE'] == 'result' && $this->ids['delete'] == $attrs['ID'])
		{
			unset ($this->ids['delete']);
			$this->flags['item_deleted'] = true;
		}
		elseif ($name == 'IQ' && $attrs['TYPE'] == 'error' && $this->ids['delete'] == $attrs['ID'])
		{
			unset ($this->ids['delete']);
			$this->flags['item_deletion_error'] = true;
		}
		elseif ($name == 'ERROR' && array_key_exists ('item_deletion_error', $this->flags))
		{
			unset ($this->flags['item_deletion_error']);
			$this->last_error = __('Item deletion returned an error of type "') . $attrs['TYPE'] . '".';
			$this->must_close = true;
		}

		$this->common_start_handler ($name);
	} // }}}
	
	private function item_deletion_end_handler () // {{{
	{
		$this->common_end_handler ();
	} // }}}

// Leaf node creation //

	private function leaf_creation_start_handler ($parser, $name, $attrs) // {{{
	{
		if ($name == 'IQ' && $attrs['TYPE'] == 'result' && $this->ids['leaf'] == $attrs['ID'])
		{
			unset ($this->ids['leaf']);
			$this->flags['leaf_created'] = true;
		}
		elseif ($name == 'IQ' && $attrs['TYPE'] == 'error' && $this->ids['leaf'] == $attrs['ID'])
		{
			unset ($this->ids['leaf']);
			$this->flags['leaf_creation_error'] = true;
		}
		elseif ($name == 'ERROR' && array_key_exists ('leaf_creation_error', $this->flags))
		{
			unset ($this->flags['leaf_creation_error']);
			$this->last_error = __('Leaf node creation returned an error of type "') . $attrs['TYPE'] . '".';
			$this->must_close = true;
		}

		$this->common_start_handler ($name);
	} // }}}
	
	private function leaf_creation_end_handler () // {{{
	{
		$this->common_end_handler ();
	} // }}}

// Collection node creation //

	private function collection_creation_start_handler ($parser, $name, $attrs) // {{{
	{
		if ($name == 'IQ' && $attrs['TYPE'] == 'result' && $this->ids['collection'] == $attrs['ID'])
		{
			unset ($this->ids['collection']);
			$this->flags['collection_created'] = true;
		}
		elseif ($name == 'IQ' && $attrs['TYPE'] == 'error' && $this->ids['collection'] == $attrs['ID'])
		{
			unset ($this->ids['collection']);
			$this->flags['collection_creation_error'] = true;
		}
		elseif ($name == 'ERROR' && array_key_exists ('collection_creation_error', $this->flags))
		{
			unset ($this->flags['collection_creation_error']);
			$this->last_error = __('Collection node creation returned an error of type "') . $attrs['TYPE'] . '".';
			$this->must_close = true;
		}

		$this->common_start_handler ($name);
	} // }}}
	
	private function collection_creation_end_handler () // {{{
	{
		$this->common_end_handler ();
	} // }}}

// Node information discovery //

	private function node_info_start_handler ($parser, $name, $attrs) // {{{
	{
		if ($name == 'IQ' && $attrs['TYPE'] == 'result' && $this->ids['node_info'] == $attrs['ID'])
		{
			unset ($this->ids['node_info']);
			$this->flags['node_info_success'] = true;
		}
		elseif ($name == 'IDENTITY' && array_key_exists ('node_info_success', $this->flags))
			$this->flags['node_identity'] = $attrs['TYPE'];
		elseif ($name == 'IQ' && $attrs['TYPE'] == 'error' && $this->ids['node_info'] == $attrs['ID'])
		{
			unset ($this->ids['node_info']);
			$this->flags['node_info_error'] = true;
		}
		elseif ($name == 'ERROR' && array_key_exists ('node_info_error', $this->flags))
			$this->last_error = __('Node information discovery returned an error of type "') . $attrs['TYPE'] . '".';

		$this->common_start_handler ($name);
	} // }}}
	
	private function node_info_end_handler () // {{{
	{
		if ($name == 'IQ' && array_key_exists ('node_info_error', $this->flags))
		{
			unset ($this->flags['node_info_error']);
			$this->must_close = true;
		}
		elseif ($name == 'IQ' && array_key_exists ('node_info_success', $this->flags))
		{
			unset ($this->flags['node_info_success']);
			if (array_key_exists ('node_identity', $this->flags))
			{
				$this->flags['node_type'] = $this->flags['node_identity'];
				unset ($this->flags['node_identity']);
			}
			else
				$this->flags['node_type'] = false;
		}

		$this->common_end_handler ();
	} // }}}
} // }}}

?>
