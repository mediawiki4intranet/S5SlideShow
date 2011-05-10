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
$wgAutoloadClasses['S5SkinArticle'] = dirname(__FILE__).'/S5SlideShow.class.php';
$wgHooks['ArticleFromTitle'][] = 'S5SlideShowHooks::ArticleFromTitle';
$wgHooks['AlternateEdit'][] = 'S5SlideShowHooks::AlternateEdit';

class S5SlideShowHooks
{
    static $styles = array(
        'core.css'    => 's5-core.css',
        'base.css'    => 's5-base.css',
        'framing.css' => 's5-framing.css',
        'pretty.css'  => '$skin/pretty.css',
    );
    // Setup parser hook for <slide>
    static function Setup()
    {
        global $wgParser;
        $wgParser->setHook('slide', 'S5SlideShow::slide');
    }
    // Hook for ?action=slide
    static function UnknownAction($action, $article)
    {
        global $wgRequest;
        if ($action == 'slide')
        {
            $s5skin = trim($wgRequest->getVal('s5skin'));
            if (preg_match('/[^\w-]/', $s5skin))
                $s5skin = '';
            if ($wgRequest->getVal('s5css'))
            {
                // Get CSS for a given S5 style (from wiki-pages)
                S5SlideShow::genStyle($s5skin);
                return false;
            }
            // Check if the article is readable
            $title = $article->getTitle();
            if (!$title->userCanRead())
                return true;
            // Hack for CustIS live preview
            // TODO remove support for loading text from session object and replace it by support for save-staying-in-edit-mode extension
            $content = $wgRequest->getVal('wpTextbox1');
            if (!$content)
            {
                $content = $_SESSION['wpTextbox1'];
                unset($_SESSION['wpTextbox1']);
            }
            // Generate presentation HTML content
            $slideShow = new S5SlideShow($title, $content);
            if ($s5skin)
                $slideShow->style = $s5skin;
            $slideShow->genSlideFile();
            return false;
        }
        return true;
    }
    // Used to display CSS files on S5 skin CSS pages when they don't exist
    static function ArticleFromTitle(&$title, &$article)
    {
        if ($title->getNamespace() == NS_MEDIAWIKI &&
            preg_match('#^S5/([\w-]+)/((core|base|framing|pretty).css)$#s', $title->getText(), $m))
        {
            $file = dirname(__FILE__).'/'.str_replace('$skin', $m[1], self::$styles[$m[2]]);
            if (file_exists($file))
            {
                $article = new S5SkinArticle($title, $m[1], $file);
                return false;
            }
        }
        return true;
    }
    // Used to display CSS files on S5 skin CSS pages in edit mode
    static function AlternateEdit($editpage)
    {
        if ($editpage->mArticle instanceof S5SkinArticle &&
            !$editpage->mArticle->exists())
            $editpage->mPreloadText = $editpage->mArticle->getContent();
        return true;
    }
}
