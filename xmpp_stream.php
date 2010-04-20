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
require_once('Auth/SASL/Plain.php');
require_once('Auth/SASL/CramMD5.php');
//require_once('Auth/SASL/Anonymous.php');
$old_error_level = error_reporting(0);
include_once "Net/DNS.php"; // For SRV Records. // Optional.
error_reporting($old_error_level);
/* Note: for some future, probably dns_get_record may be better as it implies no external extension.
But currently it is not very portable (nor working under BSD, MAC, or Windows... For me Linux enough is OK, but maybe not for other people).
*/
require_once(dirname(__FILE__) . '/my_socket.php');
require_once(dirname(__FILE__) . '/xmpp_utils.php');

class xmpp_stream // {{{
{
	public $node = '';
	public $domain = '';
	public $password = '';
	public $resource = '';
	// real jid obtained after authentication.
	private $jid = '';

	public $server = array ();
	public $port = array ();
	private $socket = null;

	private $logged = false;

	// If nothing happens on the stream after 5 seconds, I shutdown.
	private $timeout = 20;
	public $last_error = '';

	// Known authentication mechanism.
	// The key is the mechanism name, and the value is the preference.
	// The more securized, the preferred mechanism...
	// For now will consider only the digest-md5 authentication.
	private $known_auth = array ('DIGEST-MD5' => 10, 'CRAM-MD5' =>7, 'PLAIN' => 4); //, 'ANONYMOUS' => 0);
	private $chosen_mechanism = '';
	private $use_tls = false;

	private $current_cdata = '';
	private $features = array ();
	private $ids = array ();

	// When the configuration form must be changed, we fill this array.
	private $conf = array ();
	// Keep temp values when I configure a node, for passing through functions...
	private $temp_node = '';
	private $temp_server = '';

	// FLAGS //
	private $flags = array ();

	private function srv_sort ($records)
	{
		ksort ($records);
		foreach ($records as $rrs)
		{
			if (count ($rrs) == 1)
			{
				$this->port[] = $rrs[0]['port'];
				$this->server[] = $rrs[0]['target'];
			}
			else
			{
				function nul_first ($a, $b)
				{
					if ($a['weight'] == 0 && $b['weight'] == 0)
						return 0;
					if ($a['weight'] == 0)
						return -1; // nul weighted records are first.
					if ($b['weight'] == 0)
						return 1; // nul weighted records are first.
					else
						return 0; // other than this, I don't care.
				}

				usort ($rrs, nul_first);
				$w_sum = 0;
				while (count ($rrs) > 0)
				{
					foreach ($rrs as $rr)
					{
						$w_sum += $rr['weight'];
						$rr['w_sum'] = $w_sum;
					}
					$rand_num = rand (0, $w_sum);
					foreach ($rrs as $k => $rr)
					{
						if ($rr['w_sum'] >= $rand_num)
						{
							$this->port[] = $rr['port'];
							$this->server[] = $rr['server'];
							unset ($rrs[$k]);
							break;
						}
					}
				}
			}
		}
	}

	function __construct ($node, $domain, $password, $resource = 'bot',
		$server = '', $port = '') // {{{
	{
		$this->node = $node;
		$this->domain = $domain;
		$this->password = $password;
		$this->resource = $resource;

		if ($port == '' && $server == '')
		{
			if (class_exists ("NET_DNS_Resolver"))
			{
				$resolver = new Net_DNS_Resolver();
				$response = $resolver->query('_xmpp-client._tcp.' . $this->domain, 'SRV');
				if ($response)
				{
					$recs = array ();
					foreach ($response->answer as $rr)
					{
						$rec = array ();
						$rec['target'] = $rr->target;
						$rec['port'] = $rr->port;
						$rec['weight'] = $rr->weight;
						$recs[$rr->preference][] = $rec;
						// for some unknown reason, in NET_DNS, priority seems called preference.

						/*$this->server[] = $rr->target;
						$this->port[] = $rr->port;
						$this->priority[] = $rr->preference; // for some unknown reason, in NET_DNS, priority seems called preference.
						$this->weight[] = $rr->weight;*/
					}
					$this->srv_sort ($recs);
				}
				else
				{
					$this->port[] = 5222;
					$this->server[] = $domain;
				}
			}
			elseif (function_exists ("dns_get_record"))
			{
				$response = dns_get_record ('_xmpp-client._tcp.' . $this->domain, DNS_SRV);
				if ($response)
				{
					$recs = array ();
					foreach ($response as $rr)
					{
						$rec = array ();
						$rec['target'] = $rr['target'];
						$rec['port'] = $rr['port'];
						$rec['weight'] = $rr['weight'];
						$recs[$rr['pri']][] = $rec;

						/*$this->server[] = $rr['target'];
						$this->port[] = $rr['port'];
						$this->priority[] = $rr['pri'];
						$this->weight[] = $rr['weight'];*/
					}
					$this->srv_sort ($recs);
				}
				else
				{
					$this->port[] = 5222;
					$this->server[] = $domain;
				}
			}
			else
			{
				$this->port[] = 5222;
				$this->server[] = $domain;
			}
		}
		else
		{
			if ($server == '')
				$this->server[] = $domain;
			else
				$this->server[] = $server;

			if ($port == '')
				$this->port[] = 5222;
			else
				$this->port[] = $port;
		}
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

	function log () // {{{
	{
		if (!$this->logged && $this->connect () && $this->authenticate () && $this->bind () && $this->session_establish ())
			$this->logged = true;
		
		return $this->logged;
	} // }}}

	function quit () // {{{
	{
		if ($this->logged)
			$this->socket->close ();
		unset ($this->flags);
		return true;
	} // }}}

	private function connect () // {{{
	{
		$this->socket = new my_socket ();
		foreach ($this->server as $key => $server)
		{
			$this->socket->server = $server;
			$this->socket->port = $this->port[$key];

			if (! $this->socket->connect ())
			{
				$this->last_error = __('Error during connection: ') . $this->socket->last_error;
				continue;
			}

			return true;
		}

		return false;
	} // }}}
	
	private function authenticate () // {{{
	{
		$stream_begin = "<stream:stream xmlns='jabber:client'
			xmlns:stream='http://etherx.jabber.org/streams'
			to='" . $this->domain .
			"' version='1.0'>";

		if (! $this->socket->send ($stream_begin))
		{
			$this->last_error = __('Stream initiate failure: ');
			$this->last_error .= $this->socket->last_error;
			$this->quit ();
			return false;
		}

		return ($this->process_read ("authentication_start_handler",
			"authentication_end_handler", 'authenticated'));

		/*if (array_key_exists ('starttls', $this->flags))
			return ($this->process_read ("authentication_start_handler",
			"authentication_end_handler", 'authenticated'));*/
	} // }}}

	private function bind () // {{{
	{
		$stream_begin = "<stream:stream xmlns='jabber:client'
			xmlns:stream='http://etherx.jabber.org/streams'
			to='" . $this->domain .
			"' version='1.0'>";

		if (! $this->socket->send ($stream_begin))
		{
			$this->last_error = __('Binding failure: ');
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

	private function session_establish () // {{{
	{
		if (array_key_exists ('SESSION', $this->features))
		{
			$id = time () . rand ();
			$this->ids['session'] = 'session' . $id;
			$configuration = get_option ('jabber_feed_configuration');
			$message_session = "<iq to='" . $configuration['domain'] ."' type='set' id='session" . $id . "'>";
			$message_session .= "<session xmlns='urn:ietf:params:xml:ns:xmpp-session'/></iq>";
			$this->socket->send ($message_session);

			return ($this->process_read ("session_start_handler",
				"session_end_handler", 'session'));
		}
		else
			// if the server does not support session, so we just continue without session establishment!
			return true;
	} // }}}

	function notify ($server, $node, $id, $title, $link,
		$content = '', $excerpt = '', $xhtml = true) // {{{
	{
		if (! $this->create_leaf ($server, $node))
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
		$message .= "<title>" . xhtml2bare ($title) . "</title>";
		if ($excerpt !== '') // I don't know if it is possible to have xhtml excerpt in Wordpress. But let's say the plugin always send only text version.
			$message .= "<summary>" . xhtml2bare ($excerpt) . "</summary>";

		if ($content !== '')
		{
			if ($xhtml)
				$message .= '<content type="xhtml"><html xmlns="http://www.w3.org/1999/xhtml">' . fixxhtml ($content) . '</html></content>';
				
			
			$message .= '<content>' . xhtml2bare ($content) . "</content>";
		}

      $message .= '<link rel="alternate" type="text/html" href="';
		$message .= $link . '"/>';
		$message .= "<id>" . $id . "</id>";
		$message .= "<published>" . $date . "</published><updated>" . $date . "</updated>";
		// TODO: what about modified items for 'published' field??
		$message .= "</entry></item></publish></pubsub></iq>";

		if (! $this->socket->send ($message))
		{
			$this->last_error = __('Notification failure: ');
			$this->last_error .= $this->socket->last_error;
			$this->quit ();
			return FALSE;
		}

		return ($this->process_read ("notification_start_handler",
			"notification_end_handler", 'published'));
	} // }}}

	function delete_item ($server, $node, $id) // {{{
	{
		$iq_id = time () . rand ();
		$this->ids['delete'] = 'retract' . $iq_id;

		$message = "<iq type='set' from='" . $this->jid . "' ";
		$message .= "to='" . $server . "' id='retract" . $iq_id . "'>";
		$message .= "<pubsub xmlns='http://jabber.org/protocol/pubsub'>";
		$message .= "<retract node='" . $node . "'><item id='";
		$message .= $id . "' /></retract></pubsub></iq>";

		if (! $this->socket->send ($message))
		{
			$this->last_error = __('Item deletion failure: ');
			$this->last_error .= $this->socket->last_error;
			$this->quit ();
			return FALSE;
		}

		return ($this->process_read ("item_deletion_start_handler",
			"item_deletion_end_handler", 'item_deleted'));
	} // }}}

	function delete_node ($server, $node) // {{{
	{
		$iq_id = time () . rand ();
		$this->ids['delete'] = 'delete' . $iq_id;

		$message = "<iq type='set' from='" . $this->jid . "' ";
		$message .= "to='" . $server . "' id='delete" . $iq_id . "'>";
		$message .= "<pubsub xmlns='http://jabber.org/protocol/pubsub#owner'>";
		$message .= "<delete node='" . $node . "' /></pubsub></iq>";

		if (! $this->socket->send ($message))
		{
			$this->last_error = __('Node deletion failure: ');
			$this->last_error .= $this->socket->last_error;
			$this->quit ();
			return FALSE;
		}

		return ($this->process_read ("node_deletion_start_handler",
			"node_deletion_end_handler", 'node_deleted'));
	} // }}}

	function create_leaf ($server, $node) // {{{
	{
		if ($node == '')
		{
			$this->last_error = __('Empty node. No instant node supported.');
			return false;
		}

		$node_type = $this->node_type ($server, $node);

		// Workaround bug EJAB-672 of ejabberd.
		// This is the right behaviour but not working because of ejabberd bug.
		/*if ($node_type == 'leaf')
		{
			$this->last_error .= 'plouf!';
			return true;
		}
		elseif ($node_type == 'collection')
		{
			$this->last_error = __('This node already exists but is a collection node: "') . $node . '".';
			return false;
		} */ 
		// This is a flawed behaviour, due to the fact that all nodes are leaf node in ejabberd 2.0.1.
		if ($node_type != false)
			return true;
		// End of workaround.

		$subnode = $this->subnode ($node);

		if ($subnode == false || $this->create_collection ($server, $subnode))
		// XXX: there is no more semantics in node name,
		// so we should not use directory semantics in node name
		// (cf. section 12.13 of XEP-0060)
		// But for now, at least the ejabberd implementation requires this.
		{
			$iq_id = time () . rand ();
			$this->ids['leaf'] = 'create' . $iq_id;

			$message = "<iq type='set' from='" . $this->jid . "' ";
			$message .= "to='" . $server . "' id='create" . $iq_id . "'>";
			$message .= "<pubsub xmlns='http://jabber.org/protocol/pubsub'>";
			$message .= "<create node='" . $node . "'/><configure/>";
			$message .= "</pubsub></iq>";

			if (! $this->socket->send ($message))
			{
				$this->last_error = __('Leaf creation failure: ');
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
			$this->last_error = __('Empty node. No instant node supported.');
			return false;
		}

		$node_type = $this->node_type ($server, $node);

		// Workaround bug EJAB-672 of ejabberd.
		/*if ($node_type == 'collection') // || 'service' -> root!
			return true;
		elseif ($node_type == 'leaf')
		{
			$this->last_error = __('This node already exists but is a leaf node: "') . $node . '".';
			return false;
		}*/
		// This is a flawed behaviour, due to the fact that all nodes are leaf node in ejabberd 2.0.1.
		if ($node_type != false)
			return true;
		// End of workaround.

		$subnode = $this->subnode ($node);
		if ($subnode == false || $this->create_collection ($server, $subnode))
		// XXX: there is no more semantics in node name, so don't use directory semantics in node name
		// (cf. section 12.13 of XEP-0060)
		// But for now, at least the ejabberd implementation requires this.
		{
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
				$this->last_error = __('Collection node creation failure: ');
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

/*
This function set a node as persistent, with at least $size as max_items.
*/
	function configure_node ($server, $node, $size) // {{{
	{
		$this->conf['pubsub#max_items'] = $size; 
		$this->temp_node = $node;
		$this->temp_server = $server;

		$iq_id = time () . rand ();
		$this->ids['configure'] = 'configure' . $iq_id;

		$message = "<iq type='get' from='" . $this->jid . "' ";
		$message .= "to='" . $server . "' id='configure" . $iq_id . "'>";
		$message .= "<pubsub xmlns='http://jabber.org/protocol/pubsub#owner'>";
		$message .= "<configure node='" . $node . "' />";
		$message .= "</pubsub></iq>";

		if (! $this->socket->send ($message))
		{
			$this->last_error = __('Node configuration failure: ');
			$this->last_error .= $this->socket->last_error;
			$this->quit ();
			return FALSE;
		}

		return ($this->process_read ("configure_node_start_handler",
					"configure_node_end_handler", 'configured'));
	} // }}}

	function node_type ($server, $node) // return false if not existing, "leaf" and "collection" otherwise! // {{{
	{
		$iq_id = time () . rand ();
		$this->ids['node_info'] = 'info' . $iq_id;

		$query_info = "<iq type='get' from='" . $this->jid . "' to='" . $server . "' id='info" . $iq_id;
		$query_info .= "'><query xmlns='http://jabber.org/protocol/disco#info' node='";
		$query_info .= $node . "'/></iq>";

		if (! $this->socket->send ($query_info))
		{
			$this->last_error = __('Node information discovery failure: ');
			$this->last_error .= $this->socket->last_error;
			$this->quit ();
			return FALSE;
		}

		return ($this->process_read ("node_info_start_handler",
			"node_info_end_handler", 'node_type'));
	} // }}}

// this function returns "root1/root2" if you give it "root1/root2/node" and returns false if you give ''
	private function subnode ($node) // {{{
	{
		$pattern_root = '/^\/*$/';
		if (preg_match ($pattern_root, $node) == 1)
			return false;

		$pattern_first_level = '/^\/*[^\/]+\/*$/';
		if (preg_match ($pattern_first_level, $node) == 1)
			return false;

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
			if (array_key_exists ($flag, $this->flags))
				break;

			$data = $this->socket->read ();
			/*if (strlen ($data) != 0)
				jabber_feed_log ('Incoming XML: ' . $data);*/

			if ($data === FALSE)
			{
				$this->last_error = __('Error while reading in the socket.');
				jabber_feed_log ($this->last_error);
				break;
			}
			elseif (strlen ($data) === 0)
			{
				if (time () - $last_update > $this->timeout)
				{
					$this->last_error =  __('Timeout of ') . ' ';
					$this->last_error .= $this->timeout ;
					$this->last_error .= ' ' . __('seconds.');
					jabber_feed_log ($this->last_error);
					break;
				}
				continue;
			}
			//elseif (!xml_parse($xml_parser, $data, FALSE))
			elseif (xml_parse($xml_parser, $data, FALSE) == XML_STATUS_ERROR)
			{
				jabber_feed_log ('Incoming XML failed to parse: ' . $data);
				$this->last_error = sprintf("XML parsing error %d %d: %s at line %d (\"%s\").",
					xml_get_error_code ($xml_parser),
					XML_ERROR_INVALID_TOKEN,
					xml_error_string(xml_get_error_code ($xml_parser)),
					xml_get_current_line_number ($xml_parser),
					htmlentities ($data));
				jabber_feed_log ($this->last_error);
				break;
			}
			else // data read on the socket and processed in the handlers if needed!
			{
				//jabber_feed_log ('Incoming XML parsed: ' . $data);
				$xmpp_last_update = time ();
				continue;
			}
		}

		xml_parser_free ($xml_parser);
		if (array_key_exists ($flag, $this->flags))
		{
			$return_value = $this->flags[$flag];
			unset ($this->flags[$flag]);
			return $return_value;
		}
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
		if ($name == 'STARTTLS')
		{
			$this->use_tls = true;
			$this->flags['starttls'] = true;
			return;
		}
		elseif ($name == 'MECHANISM' && array_key_exists (strtoupper ($this->current_cdata), $this->known_auth))
		{
			$this->current_cdata = strtoupper ($this->current_cdata);
			if (empty ($this->chosen_mechanism) || $this->_known_auth[$this->current_cdata] > $this->known_auth[$this->chosen_mechanism])
				$this->chosen_mechanism = $this->current_cdata;
			return;
		}
		elseif ($name == 'CHALLENGE'
			&& ! array_key_exists ('challenged_once', $this->flags))
		{
			// I get the challenge from cdata and decode it (base64).
			$decoded_challenge = base64_decode ($this->current_cdata);
			if ($this->chosen_mechanism == "DIGEST-MD5")
			{
				$sasl = new Auth_SASL_DigestMD5 ();
				$uncoded = $sasl->getResponse ($this->node, $this->password, $decoded_challenge, $this->domain, 'xmpp');

				$coded = base64_encode ($uncoded);
				$response = '<response xmlns=\'urn:ietf:params:xml:ns:xmpp-sasl\'>' . $coded . '</response>';

				if (! $this->socket->send ($response))
				{
					$this->last_error = __('Authentication failure: ');
					$this->last_error .= $_socket->last_error;
					$this->flags['authenticated'] = false;
					return;
				}

			}
			elseif ($this->chosen_mechanism == "CRAM-MD5")
			{
				$sasl = new Auth_SASL_CramMD5 ();
				$uncoded = $sasl->getResponse ($this->node, $this->password, $decoded_challenge);
				// To be tested. Should the first argument be full jid or just username?

				$coded = base64_encode ($uncoded);
				$response = '<response xmlns=\'urn:ietf:params:xml:ns:xmpp-sasl\'>' . $coded . '</response>';

				if (! $this->socket->send ($response))
				{
					$this->last_error = __('Authentication failure: ');
					$this->last_error .= $_socket->last_error;
					$this->flags['authenticated'] = false;
					return;
				}

			}
			/*elseif ($this->chosen_mechanism == "ANONYMOUS")
			{
				$sasl = new Auth_SASL_Anonymous ();
				$uncoded = $sasl->getResponse ();
            }*/

			$this->flags['challenged_once'] = true;
			return;
		}
		elseif ($name == 'CHALLENGE')
		{
			unset ($this->flags['challenged_once']);
			$response = '<response xmlns=\'urn:ietf:params:xml:ns:xmpp-sasl\'/>';
			if (! $this->socket->send ($response))
			{
				$this->last_error = __('Authentication failure: ');
				$this->last_error .= $_socket->last_error;
				$this->flags['authenticated'] = false;
				return;
			}
		}
		elseif ($name == 'FAILURE' || $name == 'STREAM:STREAM')
		{
			$this->socket->send ('</stream:stream>');
			$this->last_error = __('Authentication failure: wrong username or password.');
			$this->flags['authenticated'] = false;
			return;
		}
		elseif ($name == 'SUCCESS')
		{
			$this->flags['authenticated'] = true;
			return;
		}
		elseif ($name == 'STREAM:FEATURES'
				&& array_key_exists ('starttls', $this->flags))
		{
			// I must discard any information got before TLS negotiation.
			$this->chosen_mechanism = '';

			$tls_query = '<starttls xmlns="urn:ietf:params:xml:ns:xmpp-tls"/>';
			if (! $this->socket->send ($tls_query))
			{
				$this->last_error = __('Authentication failure: ');
				$this->last_error .= $this->socket->last_error;
				$this->flags['authenticated'] = false;
				return;
			}
			return;
		}
		elseif ($name == 'PROCEED'
				&& array_key_exists ('starttls', $this->flags))
		{
			unset ($this->flags['starttls']);

			if (!$this->socket->encrypt ())
			{
				$this->last_error = __('Authentication failure: ');
				$this->last_error .= $this->socket->last_error;
				$this->flags['authenticated'] = false;
				return;
			}

			jabber_feed_log ("Encrypted connection.");
			
			$stream_begin2 = "<stream:stream xmlns='jabber:client'
				xmlns:stream='http://etherx.jabber.org/streams'
				to='" . $this->domain .
				"' version='1.0'>";

			if (! $this->socket->send ($stream_begin2))
			{
				$this->last_error = __('Stream initiate failure after TLS successful: ');
				$this->last_error .= $this->socket->last_error;
				$this->quit ();
				$this->flags['authenticated'] = false;
				return;
			}

			if ($this->process_read ("authentication_tls_start_handler",
						"authentication_tls_end_handler", 'authenticated_tls'))
			{
				$this->flags['authenticated'] = true;
				return;
			}
			else
			{
				$this->flags['authenticated'] = false;
				return;
			}
		}
		elseif ($name == 'STREAM:FEATURES')
		{
			jabber_feed_log ("Chosen authentication mechanism: " . $this->chosen_mechanism);
			if ($this->chosen_mechanism == '')
			{
				$this->last_error = __('No compatible authentication mechanism.');
				jabber_feed_log ($this->last_error);
				$this->flags['authenticated'] = false;
			}
			else
			{
				if ($this->chosen_mechanism == "PLAIN")
				{
					$sasl = new Auth_SASL_Plain ();
					$uncoded = $sasl->getResponse ($this->node . '@' . $this->domain, $this->password);

					$coded = base64_encode ($uncoded);
					//$response = '<response xmlns=\'urn:ietf:params:xml:ns:xmpp-sasl\'>' . $coded . '</response>';

					/*if (! $this->socket->send ($response))
					{
						$this->last_error = __('Authentication failure: ');
						$this->last_error .= $_socket->last_error;
						$this->flags['authenticated'] = false;
						return;
					}*/

					$mechanism = "<auth xmlns='urn:ietf:params:xml:ns:xmpp-sasl' mechanism='PLAIN'>";
					$mechanism .= $coded . "</auth>";
				}
				else
				{
					$mechanism = "<auth xmlns='urn:ietf:params:xml:ns:xmpp-sasl'";
					$mechanism .= " mechanism='" . $this->chosen_mechanism . "' />";
				}

				$this->socket->send ($mechanism);
			}
			return;
		}

	} // }}}

	private function authentication_tls_start_handler ($parser, $name, $attrs) // {{{
	{
		$this->common_start_handler ($name);
	} // }}}

	private function authentication_tls_end_handler ($parser, $name) // {{{
	{
		$this->common_end_handler ();

		if ($name == 'MECHANISM' && array_key_exists (strtoupper ($this->current_cdata), $this->known_auth))
		{
			$this->current_cdata = strtoupper ($this->current_cdata);
			if (empty ($this->chosen_mechanism) || $this->_known_auth[$this->current_cdata] > $this->known_auth[$this->chosen_mechanism])
				$this->chosen_mechanism = $this->current_cdata;
			return;
		}
		elseif ($name == 'CHALLENGE'
			&& ! array_key_exists ('challenged_once', $this->flags))
		{
			// I get the challenge from cdata and decode it (base64).
			$decoded_challenge = base64_decode ($this->current_cdata);
			if ($this->chosen_mechanism == "DIGEST-MD5")
			{
				$sasl = new Auth_SASL_DigestMD5 ();
				$uncoded = $sasl->getResponse ($this->node, $this->password, $decoded_challenge, $this->domain, 'xmpp');
			}
			elseif ($this->chosen_mechanism == "CRAM-MD5")
			{
				$sasl = new Auth_SASL_CramMD5 ();
				$uncoded = $sasl->getResponse ($this->node, $this->password, $decoded_challenge);
				// To be tested. Should the first argument be full jid or just username?
			}
			/*elseif ($this->chosen_mechanism == "ANONYMOUS")
			{
				$sasl = new Auth_SASL_Anonymous ();
				$uncoded = $sasl->getResponse ();
            }*/
			else
			{
				$this->last_error = __('Authentication failure: ');
				$this->last_error .= __('this case should never happen. Contact the developper.');
				$this->flags['authenticated_tls'] = false;
				return;
			}
			/*elseif ($this->chosen_mechanism == "PLAIN")
			{
				$sasl = new Auth_SASL_Plain ();
				$uncoded = $sasl->getResponse ($this->node, $this->password);
				// To be tested. Should the first argument be full jid or just username?
			}*/

			$coded = base64_encode ($uncoded);
			$response = '<response xmlns=\'urn:ietf:params:xml:ns:xmpp-sasl\'>' . $coded . '</response>';

			if (! $this->socket->send ($response))
			{
				$this->last_error = __('Authentication failure: ');
				$this->last_error .= $_socket->last_error;
				$this->flags['authenticated_tls'] = false;
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
				$this->last_error = __('Authentication failure: ');
				$this->last_error .= $_socket->last_error;
				$this->flags['authenticated_tls'] = false;
				return;
			}
		}
		elseif ($name == 'FAILURE' || $name == 'STREAM:STREAM')
		{
			$this->socket->send ('</stream:stream>');
			$this->last_error = __('Authentication failure: wrong username or password.');
			$this->flags['authenticated_tls'] = false;
			return;
		}
		elseif ($name == 'SUCCESS')
		{
			$this->flags['authenticated_tls'] = true;
			return;
		}
		elseif ($name == 'STREAM:FEATURES')
		{
			jabber_feed_log ("Chosen authentication mechanism: " . $this->chosen_mechanism);
			if ($this->chosen_mechanism == '')
			{
				$this->last_error = __('No compatible authentication mechanism.');
				jabber_feed_log ($this->last_error);
				$this->flags['authenticated_tls'] = false;
			}
			else
			{
				if ($this->chosen_mechanism == "PLAIN")
				{
					$sasl = new Auth_SASL_Plain ();
					$uncoded = $sasl->getResponse ($this->node . '@' . $this->domain, $this->password);

					$coded = base64_encode ($uncoded);

					$mechanism = "<auth xmlns='urn:ietf:params:xml:ns:xmpp-sasl' mechanism='PLAIN'>";
					$mechanism .= $coded . "</auth>";
				}
				else
				{
					$mechanism = "<auth xmlns='urn:ietf:params:xml:ns:xmpp-sasl'";
					$mechanism .= " mechanism='" . $this->chosen_mechanism . "' />";
				}

				if (! $this->socket->send ($mechanism))
				{
					$this->last_error = __('Authentication failure: ');
					$this->last_error .= $this->socket->last_error;
					$this->flags['authenticated_tls'] = false;
					return;
				}
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
			$this->flags['bound'] = false;
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
					$this->flags['bound'] = false;
				}
			}
			else 
			{
				$this->last_error = __('Bind feature not available.');
				$this->flags['bound'] = false;
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
			$this->flags['session_success'] = true;
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
			$this->flags['session'] = false;
		}
			
		$this->common_start_handler ($name);
	} // }}}

	private function session_end_handler ($parser, $name) // {{{
	{
		$this->common_end_handler ();
		if ($name == 'IQ' && array_key_exists ('session_success', $this->flags))
		{
			unset ($this->flags['session_success']);
			$this->flags['session'] = true;
		}
	} // }}}

// Pubsub node configuration //

	private function configure_node_start_handler ($parser, $name, $attrs) // {{{
	{
		$changeable = array ('pubsub#deliver_payloads', 'pubsub#title', 'pubsub#max_items', 'pubsub#persist_items', 'pubsub#subscribe');
			
		$this->common_start_handler ($name);
		if ($name == 'IQ' && $attrs['TYPE'] == 'result' && $this->ids['configure'] == $attrs['ID'])
		{
			unset ($this->ids['configure']);
			$this->flags['configuration_form'] = true;
		}
		elseif ($name == 'IQ' && $attrs['TYPE'] == 'error' && $this->ids['configure'] == $attrs['ID'])
		{
			unset ($this->ids['configure']);
			$this->flags['configure_error'] = true;
		}
		elseif ($name == 'ERROR' && array_key_exists ('configure_error', $this->flags))
		{
			unset ($this->flags['configure_error']);
			$this->last_error = __('The request for configuration form returned an error of type "') . $attrs['TYPE'] . '".';
			$this->flags['configured'] = false;
		}
		elseif ($name == 'FIELD' && in_array ($attrs['VAR'], $changeable))
		{
			$this->flags[$attrs['VAR']] = true;
		}
		elseif ($name == 'IQ' && $attrs['ID'] == $this->ids['configure2'])
		{
			unset ($this->ids['configure2']);
			$this->flags['configure_done'] = true;
		}

	} // }}}

	private function configure_node_end_handler ($parser, $name) // {{{
	{
		$this->common_end_handler ();
		if ($name == 'IQ' && array_key_exists ('configuration_form', $this->flags))
		{
			unset ($this->flags['configuration_form']);
			// XXX: Now I will send my configuration...
			$iq_id = 'config' . time () . rand ();
			if (count ($this->conf) == 0)
			{
				// Nothing to change...
				// I just cancel
				$message = "<iq type='set' from='$this->jid' to='$this->temp_server' id='$iq_id'>"
					. "<pubsub xmlns='http://jabber.org/protocol/pubsub#owner'>"
					. "<configure node='$this->temp_node'><x xmlns='jabber:x:data' type='cancel'/>"
					. "</configure></pubsub></iq>";
			}
			else
			{
				$message = "<iq type='set' from='$this->jid' to='$this->temp_server' id='$iq_id'>"
					. "<pubsub xmlns='http://jabber.org/protocol/pubsub#owner'>"
					. "<configure node='$this->temp_node'><x xmlns='jabber:x:data' type='submit'>";
				foreach ($this->conf as $var => $value)
				{
					$message .= "<field var='$var'><value>$value</value></field>";
				}
				$message .= "</x></configure></pubsub></iq>";
			}

			if (! $this->socket->send ($message))
			{
				$this->last_error = __('Node configuration failure: ');
				$this->last_error .= $this->socket->last_error;
				$this->quit ();
				$this->flags['configured'] = false;
				return;
			}
	
			$this->ids['configure2'] = $iq_id;

		}
		elseif ($name == 'IQ' && array_key_exists ('configure_done', $this->flags))
		{
			unset ($this->flags['configure_done']);
			if ($attrs['TYPE'] == 'result')
				$this->flags['configured'] = true;
			else
			{
				$this->flags['configured'] = false;
				$this->last_error = 'Failure to configure.';
			}
			return;
			// XXX: I don't check for the configuration result because a failure on configuration is not fatale.
			// Maybe one will be anyway able to publish. Ex: bug on ejabberd which prevents max_items from being more than 20:
			// https://support.process-one.net/browse/EJAB-819
			// Then when you publish, the older item is removed... but this is better than stop publishing the latter post.
		}
		elseif ($name == 'VALUE' && array_key_exists ('pubsub#persist_items', $this->flags))
		{
			// Items are persistent.
			unset ($this->flags['pubsub#persist_items']);
			if ($this->current_cdata != '1')
				$this->conf['pubsub#persist_items'] = "1";
		}
		elseif ($name == 'VALUE' && array_key_exists ('pubsub#subscribe', $this->flags))
		{
			// Users can subscribe.
			unset ($this->flags['pubsub#subscribe']);
			if ($this->current_cdata != '1')
				$this->conf['pubsub#subscribe'] = "1";
		}
		elseif ($name == 'VALUE' && array_key_exists ('pubsub#deliver_payloads', $this->flags))
		{
			// Payloads (= post's contents) is delivered.
			unset ($this->flags['pubsub#deliver_payloads']);
			if ($this->current_cdata != 'true')
				$this->conf['pubsub#deliver_payloads'] = "true";
		}
		elseif ($name == 'VALUE' && array_key_exists ('pubsub#title', $this->flags))
		{
			unset ($this->flags['pubsub#title']);
			// I change the title only if it is empty. This way, if the admin want to set manually a title (through a pubsub program),
			// then it won't be overriden by this plugin.
			if ($this->current_cdata == '')
				$this->conf['pubsub#title'] = "Notification node for: " . get_bloginfo ('name'); 
		}
		elseif ($name == 'VALUE' && array_key_exists ('pubsub#max_items', $this->flags))
		{
			unset ($this->flags['pubsub#max_items']);
			if ($this->current_cdata >= $this->conf['pubsub#max_items'])
				unset ($this->conf['pubsub#max_items']);
		}
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
			$this->flags['published'] = false;
		}

		$this->common_start_handler ($name);
	} // }}}
	
	private function notification_end_handler ($parser, $name) // {{{
	{
		$this->common_end_handler ();
	} // }}}

// Item deletion //

	private function item_deletion_start_handler ($parser, $name, $attrs) // {{{
	{
		if ($name == 'IQ' && $attrs['TYPE'] == 'result' && $this->ids['delete'] == $attrs['ID'])
		{
			unset ($this->ids['delete']);
			$this->flags['item_deletion_success'] = true;
		}
		elseif ($name == 'IQ' && $attrs['TYPE'] == 'error' && $this->ids['delete'] == $attrs['ID'])
		{
			unset ($this->ids['delete']);
			$this->flags['item_deletion_error'] = true;
		}
		elseif ($name == 'ERROR' && array_key_exists ('item_deletion_error', $this->flags))
			$this->last_error = __('Item deletion returned an error of type "') . $attrs['TYPE'] . '".';

		$this->common_start_handler ($name);
	} // }}}
	
	private function item_deletion_end_handler ($parser, $name) // {{{
	{
		$this->common_end_handler ();
		if ($name == 'IQ' && array_key_exists ('item_deletion_error', $this->flags))
		{
			unset ($this->flags['item_deletion_error']);
			$this->flags['item_deleted'] = false;
		}
		elseif ($name == 'IQ' && array_key_exists ('item_deletion_success', $this->flags))
		{
			unset ($this->flags['item_deletion_success']);
			$this->flags['item_deleted'] = true;
		}
	} // }}}

// Node deletion //

	private function node_deletion_start_handler ($parser, $name, $attrs) // {{{
	{
		if ($name == 'IQ' && $attrs['TYPE'] == 'result' && $this->ids['delete'] == $attrs['ID'])
		{
			unset ($this->ids['delete']);
			$this->flags['node_deletion_success'] = true;
		}
		elseif ($name == 'IQ' && $attrs['TYPE'] == 'error' && $this->ids['delete'] == $attrs['ID'])
		{
			unset ($this->ids['delete']);
			$this->flags['node_deletion_error'] = true;
		}
		elseif ($name == 'ERROR' && array_key_exists ('node_deletion_error', $this->flags))
			$this->last_error = __('Node deletion returned an error of type "') . $attrs['TYPE'] . '".';

		$this->common_start_handler ($name);
	} // }}}
	
	private function node_deletion_end_handler ($parser, $name) // {{{
	{
		$this->common_end_handler ();
		if ($name == 'IQ' && array_key_exists ('node_deletion_error', $this->flags))
		{
			unset ($this->flags['node_deletion_error']);
			$this->flags['node_deleted'] = false;
		}
		elseif ($name == 'IQ' && array_key_exists ('node_deletion_success', $this->flags))
		{
			unset ($this->flags['node_deletion_success']);
			$this->flags['node_deleted'] = true;
		}
	} // }}}

// Leaf node creation //

	private function leaf_creation_start_handler ($parser, $name, $attrs) // {{{
	{
		if ($name == 'IQ' && $attrs['TYPE'] == 'result' && $this->ids['leaf'] == $attrs['ID'])
		{
			unset ($this->ids['leaf']);
			$this->flags['leaf_creation_success'] = true;
		}
		elseif ($name == 'IQ' && $attrs['TYPE'] == 'error' && $this->ids['leaf'] == $attrs['ID'])
		{
			unset ($this->ids['leaf']);
			$this->flags['leaf_creation_error'] = true;
		}
		elseif ($name == 'ERROR' && array_key_exists ('leaf_creation_error', $this->flags))
			$this->last_error = __('Leaf node creation returned an error of type "') . $attrs['TYPE'] . '".';

		$this->common_start_handler ($name);
	} // }}}
	
	private function leaf_creation_end_handler ($parser, $name) // {{{
	{
		$this->common_end_handler ();
		if ($name == 'IQ' && array_key_exists ('leaf_creation_error', $this->flags))
		{
			unset ($this->flags['leaf_creation_error']);
			$this->flags['leaf_created'] = false;
		}
		elseif ($name == 'IQ' && array_key_exists ('leaf_creation_success', $this->flags))
		{
			unset ($this->flags['leaf_creation_success']);
			$this->flags['leaf_created'] = true;
		}
	} // }}}

// Collection node creation //

	private function collection_creation_start_handler ($parser, $name, $attrs) // {{{
	{
		if ($name == 'IQ' && $attrs['TYPE'] == 'result' && $this->ids['collection'] == $attrs['ID'])
		{
			unset ($this->ids['collection']);
			$this->flags['collection_creation_success'] = true;
		}
		elseif ($name == 'IQ' && $attrs['TYPE'] == 'error' && $this->ids['collection'] == $attrs['ID'])
		{
			unset ($this->ids['collection']);
			$this->flags['collection_creation_error'] = true;
		}
		elseif ($name == 'ERROR' && array_key_exists ('collection_creation_error', $this->flags))
			$this->last_error = __('Collection node creation returned an error of type "') . $attrs['TYPE'] . '".';

		$this->common_start_handler ($name);
	} // }}}
	
	private function collection_creation_end_handler ($parser, $name) // {{{
	{
		$this->common_end_handler ();
		if ($name == 'IQ' && array_key_exists ('collection_creation_error', $this->flags))
		{
			unset ($this->flags['collection_creation_error']);
			$this->flags['collection_created'] = false;
		}
		elseif ($name == 'IQ' && array_key_exists ('collection_creation_success', $this->flags))
		{
			unset ($this->flags['collection_creation_success']);
			$this->flags['collection_created'] = true;
		}
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
	
	private function node_info_end_handler ($parser, $name) // {{{
	{
		if ($name == 'IQ' && array_key_exists ('node_info_error', $this->flags))
		{
			unset ($this->flags['node_info_error']);
			$this->flags['node_type'] = false;
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
