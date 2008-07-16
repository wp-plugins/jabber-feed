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
function xhtml2xhtmlim ($xhtml)
{
	// For this, I am supposing the xhtml is compliant! I use the tidy package for this.
	function callback ($match)
	{
		if ($match[1] == 'br')
			return "<br />"; // comment mettre id SI existant?
		if ($match[1] == 'blockquote|cite|code|div|em|h[1-6]|p|strong') // does div has any structural impact?
			return "<$match[1]>$match[2]</$match[1]>";
		if ($match[1] == 'span') // I don't keep the span because it is only for style... But I keep the content
			return $match[2];
		if ($match[1] == 'a')
			return "<a href='$match[2]' hreflang='$match[3]'>$match[4]</a>";
		else
			return ''; // If I see any unrecognized tag, I don't display it, nor its content. For instance images?
	}
	//$xhtmlim = html_entity_decode ($xhtml);

	// & must be transformed in &amp; but this is utf-8 and all others &... transform in equivalent utf-8, for instance &oelig;
	// 


	$xhtmlim = fixxhtml ($xhtml);
	if ($xhtmlim == false)
		return false;

	$xhtmlim = preg_replace_callback ('|<(\S*)[^>]*>([^<]</\1\s*>|', callback, xhtmlim);

	$xhtmlim = "<html xmlns='http://jabber.org/protocol/xhtml-im'><body xmlns='http://www.w3.org/1999/xhtml'>" . $xhtmlim . '</body></html>';
	return $xhtmlim;
	//$tidy = new tidy;
	//$tidy->parseString($xhtml, $config, 'utf8');
	//$tidy->cleanRepair();
}

function fixxhtml ($bad)
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
			'output-xhtml' => TRUE,
			'show-body-only' => TRUE,
			// Pretty print stuff. Not really useful, just not to have too big lines.
			'wrap' => 200);
		return tidy_repair_string ($bad, $config, 'utf8');
	}
	else
		return false;
}

function xhtml2bare ($xhtml) // Todo: shouldn't I rather use again the xml parser?!!
{
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
