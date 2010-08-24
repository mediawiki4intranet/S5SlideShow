<?php

# Copyright (C) 2010 Vitaliy Filippov <vitalif@mail.ru>
# Based on (C) 2005 TooooOld <tianshuen@gmail.com>
# http://meta.wikimedia.org/wiki/User:BR/use_S5_slide_system_in_the_mediawiki/en
#
# This program is free software; you can redistribute it and/or modify
# it under the terms of the GNU General Public License as published by
# the Free Software Foundation; either version 2 of the License, or
# (at your option) any later version.
#
# This program is distributed in the hope that it will be useful,
# but WITHOUT ANY WARRANTY; without even the implied warranty of
# MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
# GNU General Public License for more details.
#
# You should have received a copy of the GNU General Public License along
# with this program; if not, write to the Free Software Foundation, Inc.,
# 59 Temple Place - Suite 330, Boston, MA 02111-1307, USA.
# http://www.gnu.org/copyleft/gpl.html

/**
 * Extension to create slide show
 *
 * @author TooooOld <tianshuen@gmail.com>
 * @package MediaWiki
 * @subpackage Extensions
 */

if (!defined('MEDIAWIKI'))
    die();

/* Default configuration */

/* If set to a non-empty value, S5SlideShow will treat as slides
   only sections with headings matching this value in the end or in the beginning */
if (!$egS5SlideHeadingMark)
    $egS5SlideHeadingMark = '';

/* If set to a non-empty value, S5SlideShow will show slides
   with headings matching this value in the end or in the beginning
   incrementally */
if (!$egS5SlideIncMark)
    $egS5SlideIncMark = '(step)';

/* If set to a non-empty value, S5SlideShow will allow to break down slides by this value */
if (is_null($egS5SlidePageBreak))
    $egS5SlidePageBreak = "\\\\";

/* Filesystem path to presentation template file */
if (!$egS5SlideTemplateFile)
    $egS5SlideTemplateFile = dirname(__FILE__).'/slide.htm';

/* Extension setup */

$wgExtensionFunctions[] = 'S5SlideShowHooks::Setup';
$wgHooks['UnknownAction'][] = 'S5SlideShowHooks::UnknownAction';
$wgAutoloadClasses['S5SlideShow'] = dirname(__FILE__).'/S5SlideShow.class.php';

class S5SlideShowHooks
{
    static function Setup()
    {
        global $wgParser;
        $wgParser->setHook('slide', 'S5SlideShow::slide');
    }
    static function UnknownAction($action, $article)
    {
        if ($action == 'slide')
        {
            $title = $article->getTitle();
            if (method_exists($title, 'userCanReadEx') && !$title->userCanReadEx())
                return true;
            global $wgRequest;
            $content = $wgRequest->getVal('wpTextbox1');
            if (!$content)
            {
                $content = $_SESSION['wpTextbox1'];
                unset($_SESSION['wpTextbox1']);
            }
            $slideShow = new S5SlideShow($title, $content);
            if ($style = trim($wgRequest->getText('s5style')))
                $slideShow->style = $style;
            $slideShow->genSlideFile();
            return false;
        }
        return true;
    }
}
