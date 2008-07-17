<?php
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

function fixxhtml ($badxhtml) // {{{
{
	if (function_exists ("tidy_repair_string"))
	{
		$config = array(
			'bare' => TRUE,
			'doctype' => "omit",
			'drop-empty-paras' => TRUE,
			'drop-font-tags' => TRUE,
			'drop-proprietary-attributes' => TRUE,
			'fix-backslash' => TRUE,
			'hide-comments' => TRUE,
			'fix-backslash' => TRUE,
			'fix-uri' => TRUE,
			'logical-emphasis' => TRUE,
			'numeric-entities' => TRUE, // I don't want html entities!
			'output-xhtml' => TRUE,
			'show-body-only' => TRUE,
			'uppercase-attributes' => FALSE,
			'uppercase-tags' => FALSE,
			// Pretty print stuff. Not really useful, just not to have too big lines.
			'wrap' => 200);
		return tidy_repair_string ($badxhtml, $config, 'utf8');
	}
	else
		return false;

	//$tidy = new tidy;
	//$tidy->parseString($xhtml, $config, 'utf8');
	//$tidy->cleanRepair();
} // }}}

// This function transforms $xhtml, which is a normal xhtml content in the corresponding xhtml-im content.
// It supports currently only the core module of XEP-0071.
// cf. http://www.xmpp.org/extensions/xep-0071.html
// This first version does not fix badly html originally + do not remove illegal characters.
// http://openweb.eu.org/articles/xhtml_une_heure/
function xhtml2xhtmlim ($xhtml) // {{{
{
	$xhtml = fixxhtml ($xhtml);

	if ($xhtml == false)
		// no need to continue if I cannot even check xhtml integrity...
		return false;

	// From now, I am supposing the xhtml is compliant.
	// Or else it means "tidy" is bugged because I use the tidy package for this.
	$xhtmlim = "";
	$stack = array ();

	function start_handler ($parser, $name, $attrs) // {{{
	{
		// I don't have to ignore head, html and title elements in the context of this plugin,
		// as they are anyway (normally at least) not present in a post content.
		// TODO: section 7.2 -> only br, p and span? What about b, em and hX especially?!!
		// Section 7.2: br, and p only for now.
		if ($name == "br")
		{
			// no need to push on the stack as "normally" if tidy made well its job, it will close immediately.
			// But anyway, no risk to do it...
			array_push ($stack, true);
			$xhtmlim .= "<br/>";
		}
		elseif ($name == "p")
		{
			array_push ($stack, true);
			$xhtmlim .= "<p>";
		}
		// Section 7.3: only a with mandatory "href" and recommended "type".
		elseif ($name == "a")
		{
			if (array_key_exists ('href', $attrs))
			{
				array_push ($stack, true);
				$xhtmlim .= '<a href="' . $attrs['href'];
				if (array_key_exists ('type', $attrs))
					$xhtmlim .= '" type="' . $attrs['type'] . '">';
				else
					$xhtmlim .= '">"';
			}
			else
				array_push ($stack, false);
		}
		// section 7.4: only ol, ul and li recommended (what about "title" and accesskey for accessibility?!).
		// And why not def list? This is just done for IM but XMPP is more than just IM.
		elseif ($name == "ol" || $name == "ul" || $name == "li")
		{
			array_push ($stack, true);
			$xhtmlim .= '<' . $name . '>';
		}
		elseif  ($name == "img")
		{
			if (array_key_exists ('src', $attrs) && array_key_exists ('alt', $attrs))
			{
				$xhtmlim .= '<img src="' . $attrs['src'] . '" alt="' . $attrs['alt'];
				if (array_key_exists ('height', $attrs))
					$xhtmlim .= '" height="' . $attrs['height'];
				if (array_key_exists ('width', $attrs))
					$xhtmlim .= '" width="' . $attrs['width'];
				$xhtmlim .= '">';
			}
			else
				array_push ($stack, false);
		}
		else
			array_push ($stack, false);
	} // }}}

	function end_handler ($parser, $name) // {{{
	{
		$last_element_has_been_displayed = array_pop ($stack);
		if ($last_element_has_been_displayed)
			$xhtmlim .= "</" . $name . ">";
	} // }}}

	function cdata_handler ($parser, $data) // {{{
	{
		$xhtmlim .= $data;
	} // }}}

	$xml_parser = xml_parser_create();
	xml_parser_set_option ($parser, XML_OPTION_CASE_FOLDING, false);
	xml_set_element_handler ($xml_parser,
		array (&$this, $start_handler),
		array (&$this, $end_handler));
	xml_set_character_data_handler ($xml_parser, array (&$this, "cdata_handler"));

	$parse_status = xml_parse ($xml_parser, $xhtml, FALSE);
	xml_parser_free ($xml_parser);

	if ($parse_status == XML_STATUS_ERROR)
		return false;

	//$xhtmlim = html_entity_decode ($xhtml, ENT_QUOTES, "UTF-8");
	// maybe should I use char-encoding and input-encoding options of tidy instead?
	// '&' and '<' are the only characters which must be transformed in &amp; and &lt;.
	// The rest is utf-8, so I let them in their equivalent utf-8 (by html_entity_decode).
	// TODO: test &oelig;
	// numeric entities?!

	$xhtmlim = "<html xmlns='http://jabber.org/protocol/xhtml-im'><body xmlns='http://www.w3.org/1999/xhtml'>" . $xhtmlim . '</body></html>';
	return $xhtmlim;
} // }}}


function xhtml2bare ($xhtml) // Todo: shouldn't I rather use again the xml parser?!!
{
	$fixed_html = fixxhtml ($xhtml);
	if ($fixed_html != false)
	{
		$xml_parser = xml_parser_create();
		xml_set_element_handler ($xml_parser,
			array (&$this, $start_handler),
			array (&$this, $end_handler));
		xml_set_character_data_handler ($xml_parser, array (&$this, "cdata_handler"));

		xml_parser_free ($xml_parser);

		if ($parse_status != XML_STATUS_ERROR)
			return $bare;
	}

	// I am here if I could not fix the xhtml (most likely tidy is not installed),
	// or if the parse failed for some reason... So I will return with a more rudimentary method.

	// note: 'n&oelig;uds de publication' does not work. It must be utf8. Is it normal?
	$pattern[0] = "/( |\t)+/";
	$replacement[0] = ' ';

	$pattern[1] = '/<p>(.*)<\/p>/U';
	$replacement[1] = "\t" .'${1}' . "\n";

	$pattern[3] = '/<span[^>]*>(.*)<\/span>/U';
	$replacement[3] = '${1}';

	$pattern[4] = '/<a\s+[^>]*href=(\'|")([^\'"]*)\1[^>]*>(.*)<\/a>/U'; // here is it possible ' or " in a url?
	$replacement[4] = '${3} [ ${2} ]';

	$pattern[6] = '/<li[^>]*>((.|\n)*)<\/li>/U';
	$replacement[6] = "\n- " . '${1}';

// I simply remove all emphasing tags: strong, b, em, i.

	$pattern[10] = '/<b>(.*)<\/b>/U';
	$replacement[10] = '${1}';

	$pattern[11] = '/<em>(.*)<\/em>/U';
	$replacement[11] = '${1}';

	$pattern[12] = '/<strong>(.*)<\/strong>/U';
	$replacement[12] = '${1}';

	$pattern[13] = '/<i>(.*)<\/i>/U';
	$replacement[13] = '${1}';

	$pattern[14] = '/<blockquote>((.|\n)*)<\/blockquote>/U';
	$replacement[14] = "\n«\n" . '${1}' . "\n»\n";

	$pattern[15] = '/<code>((.|\n)*)<\/code>/U';
	$replacement[15] = "\n«\n" . '${1}' . "\n»\n";

	$pattern[7] = '/<(ul|ol)[^>]*>((.|\n)*)<\/\1>/U';
	$replacement[7] = '${2}' . "\n";
	// for ol, I may replace li by #somerandomnumber# then count the size and finally replace by X/.

	$pattern[16] = '/(\s*\n)+/';
	$replacement[16] = "\n";

	$pattern[2] = '/<div[^>]*>(.*)<\/div>/U';
	$replacement[2] = '${1}' . "\n";

	$pattern[5] = '/<br[^>]*>/';
	$replacement[5] = "\n";

// I remove all the other tags, as well as their content.
	$pattern[8] = '/<([^\s>]*)[^>]*>(.*)<\/\1>/';
	$replacement[8] = '';

	$pattern[9] = '/<[^>]*>/';
	$replacement[9] = '';

	$bare = html_entity_decode ((preg_replace ($pattern, $replacement, $xhtml)), ENT_NOQUOTES, "UTF-8");

	// normalement, une fois le html décodé, je retire < et &, non?
	// http://www.journaldunet.com/developpeur/tutoriel/xml/041027-xml-caracteres-speciaux.shtml

	$pattern2[1] = '/&/';
	$replacement2[1] = '&amp;';

	$pattern2[0] = '/</';
	$replacement2[0] = '&lt;';

	return (preg_replace ($pattern2, $replacement2, $bare));
}

?>