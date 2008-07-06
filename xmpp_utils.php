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

// This function transform $xhtml, which is a normal xhtml content in the corresponding xhtml-im.
// It supports currently only the core module of XEP-0071.
// This first version does not fix badly html originally + do not remove illegal characters.
// http://openweb.eu.org/articles/xhtml_une_heure/
function xhtml2htmlim ($xhtml)
{
	// For this, I am supposing the xhtml is compliant! I use the tidy package for this.
	$xhtmlim = "<html xmlns='http://jabber.org/protocol/xhtml-im'><body xmlns='http://www.w3.org/1999/xhtml'>";
	function callback ('$match')
	{
		if ($match[1] == 'br')
			return "<br />"; // comment mettre id SI existant?
		if ($match[1] == 'blockquote|cite|code|div|em|h[1-6]|p|strong') // does div has any structural impact?
			// maybe I could transform div in p?
			return "<$match[1]>$match[2]</$match[1]>";
		if ($match[1] == 'span') // I don't keep the span because it is only for style... But I keep the content
			return $match[2];
		if ($match[1] == 'a')
			return "<a href='$match[2]' hreflang='$match[3]'>$match[4]</a>";
		else
			return ''; // If I see any unrecognized tag, I don't display it, nor its content. For instance images?
		return
	}
	//$xhtmlim = html_entity_decode ($xhtml);

	// & must be transformed in &amp; but this is utf-8 and all others &... transform in equivalent utf-8, for instance &oelig;
	// 

	$xhtmlim .= preg_replace_callback ('<(\S*)/');
	$xhtmlim .= "</body></html>";
	return $xhtmlim;
}

function fixxhtml ($bad)
{
	if (function_exists ("tidy_repair_string"))
	{
		$config = array('hide-comments' => TRUE,
			'fix-backslash' => TRUE,
			'fix-uri' => TRUE,
			'logical-emphasis' => TRUE,
			'output-xhtml' => TRUE,
			'wrap' => 200);
		return tidy_repair_string ($bad, $config, 'UTF8');
	}
	else
		return false;
}

function xhtml2bare ($xhtml) // Todo: shouldn't I rather use again the xml parser?!!
{
	$pattern[0] = '/\s+/';
	$replacement[0] = ' ';

	$pattern[1] = '/<p>(.*)<\/p>/U';
	$replacement[1] = '\t${1}\n';

	$pattern[2] = '/<div[^>]*>(.*)<\/div>/U';
	$replacement[2] = '${1}\n';

	$pattern[3] = '/<span[^>]*>(.*)<\/span>/U';
	$replacement[3] = '${1}';

	$pattern[4] = '/<a\s+[^>]* href=(\'|")([^\'"]*)\1[^>]*>(.*)<\/a>/U'; // here is it possible ' or " in a url?
	$replacement[4] = '${3} [ ${2} ]';

	$pattern[6] = '/<li[^>]*>(.*)<\/li>/U';
	$replacement[6] = '\n- ${1}';

	$pattern[7] = '/<(ul|ol)[^>]*>(.*)<\/\1>/U';
	$replacement[7] = '${2}\n';
	// for ol, I may replace li by #somerandomnumber# then count the size and finally replace by X/.

	$pattern[8] = '/<(\S*)[^>\/]*>(.*)<\/\1>/';
	$replacement[8] = '';

	$pattern[5] = '/<br[^>]*>/';
	$replacement[5] = '\n';

	$pattern[9] = '/<[^>]*>/';
	$replacement[9] = '';

	$bare = html_entity_decode ((preg_replace ($pattern, $replacement, $xhtml)), ENT_NOQUOTES, "UTF-8");

	// normalement, une fois le html décodé, je retire < et &, non?
	// http://www.journaldunet.com/developpeur/tutoriel/xml/041027-xml-caracteres-speciaux.shtml
	$pattern2[0] = '<';
	$replacement2[0] = '&lt;';

	$pattern2[1] = '&';
	$replacement2[1] = '&amp;';

	return (preg_replace ($pattern2, $replacement2, $bare));
}

?>
