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
	$xhtmlim = "<html xmlns='http://jabber.org/protocol/xhtml-im'><body xmlns='http://www.w3.org/1999/xhtml'>";
	function callback ('$match')
	{
		if ()
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
}

function xhtml2bare ($xhtml)
{
}

?>
