<?php

# Copyright (C) 2010+ Vitaliy Filippov <vitalif@mail.ru>
# Based on (C) 2005 TooooOld <tianshuen@gmail.com>, but heavily modified
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
 * Extension to create slide shows from wiki pages
 *
 * @author Vitaliy Filippov <vitalif@mail.ru>
 * @package MediaWiki
 * @subpackage Extensions
 */

if (!defined('MEDIAWIKI'))
    die();

$wgAutoloadClasses['DOMParseUtils'] = dirname(__FILE__).'/DOMParseUtils.php';

class S5SlideShow
{
    var $sTitle, $sArticle;
    var $mPageContent, $mSlides;
    var $style, $title, $subtitle, $author, $footer, $subfooter, $headingmark, $incmark, $pagebreak;

    /* Constructor. If $attr is not an array(), attributes will be taken from article content. */
    function __construct($sTitle, $sContent = NULL, $attr = NULL)
    {
        if (!is_object($sTitle))
        {
            wfDebug(__CLASS__.": Error! Pass a title object, NOT a title string!\n");
            return false;
        }
        $this->sArticle = new Article($sTitle);
        $this->sTitle = $sTitle;
        if ($sContent)
            $this->mPageContent = $sContent;
        else
            $this->mPageContent = $this->sArticle->getContent();
        if (!is_array($attr))
        {
            /* get attributes from article content using a hook */
            global $wgUser;
            $parser = new Parser;
            $parser->setHook('slide', array($this, 'slide_for_args'));
            $opt = ParserOptions::newFromUser($wgUser);
            $parser->parse($this->mPageContent, $this->sTitle, $opt);
        }
        else
            $this->setAttributes($attr);
    }

    /* Parse attribute hash and save them into $this */
    function setAttributes($attr)
    {
        global $wgContLang, $wgUser, $egS5SlideHeadingMark, $egS5SlideIncMark, $egS5SlidePageBreak;
        /* Get attributes from tag content */
        if (preg_match_all('/(?:^|\n)\s*;\s*([^:\s]*)\s*:\s*([^\n]*)/is', $attr['content'], $m, PREG_SET_ORDER) > 0)
            foreach ($m as $set)
                $attr[$set[1]] = $set[2];
        /* Default slide show title = page title */
        $attr['title'] = trim($attr['title']);
        if (!$attr['title'])
            $attr['title'] = $this->sTitle->getText();
        /* No subtitle by default */
        /* Default author = last revision's author */
        if (!array_key_exists('author', $attr))
        {
            $u = $this->sArticle->getLastNAuthors(1);
            $u = $u[0];
            if (!is_object($u))
                $u = User::newFromName($u);
            if (!is_object($u))
                $u = $wgUser;
            $attr['author'] = $u->getRealName();
        }
        /* Slide show title in the footer by default */
        if (!array_key_exists('footer', $attr))
            $attr['footer'] = $attr['title'];
        /* Author and date in the subfooter by default */
        if (!array_key_exists('subfooter', $attr))
        {
            $attr['subfooter'] = $attr['author'] . ', ' .
                $wgContLang->timeanddate($this->sArticle->getTimestamp(), true);
        }
        else
        {
            $attr['subfooter'] = str_ireplace(
                '{{date}}',
                $wgContLang->timeanddate($this->sArticle->getTimestamp(), true),
                $attr['subfooter']
            );
        }
        /* Default heading mark = $egS5SlideHeadingMark */
        $attr['headingmark'] = trim($attr['headingmark']);
        if (!$attr['headingmark'])
            $attr['headingmark'] = $egS5SlideHeadingMark;
        /* Default incremental mark = $egS5SlideIncMark */
        $attr['incmark'] = trim($attr['incmark']);
        if (!$attr['incmark'])
            $attr['incmark'] = $egS5SlideIncMark;
        /* Default page break sequence = $egS5SlidePageBreak */
        $attr['pagebreak'] = trim($attr['pagebreak']);
        if (!$attr['pagebreak'])
            $attr['pagebreak'] = $egS5SlidePageBreak;
        /* Default style = "default" */
        $attr['style'] = trim($attr['style']);
        if (!$attr['style'])
            $attr['style'] = 'default';
        /* Additional CSS */
        $attr['addcss'] = trim($attr['addcss']);
        /* Is each slide to be scaled independently? */
        $attr['scaled'] = strtolower(trim($attr['scaled']));
        $attr['scaled'] = $attr['scaled'] == 'true' || $attr['scaled'] == 'yes' || $attr['scaled'] == 1;
        /* Extract values into $this */
        $fields = 'style title subtitle author footer subfooter headingmark incmark pagebreak scaled addcss';
        foreach(split(' ', $fields) as $v)
            $this->$v = $attr[$v];
    }

    /* Extract slides from wiki-text $content */
    function loadContent($content)
    {
        $html = $this->parse($content);
        $document = DOMParseUtils::loadDOM($html);
        $slides = DOMParseUtils::getSections($document->documentElement, $this->headingmark);
        if (!$slides)
            return array();
        foreach ($slides as &$slide)
        {
            /* check for incremental mark */
            if ($this->incmark && ($new_node = DOMParseUtils::checkNode($slide['title'], $this->incmark)))
            {
                $slide['title'] = $new_node[0];
                $slide['incremental'] = true;
            }
            $slide['title_html'] = DOMParseUtils::saveChildren($slide['title']);
            /* optionally break slide into pages */
            if ($this->pagebreak)
            {
                $frags = DOMParseUtils::splitDOM($slide['content'], $document, $this->pagebreak);
                $slide['pages'] = array();
                foreach ($frags as $frag)
                    $slide['pages'][] = array(
                        'content' => $frag,
                        'content_html' => DOMParseUtils::saveChildren($frag),
                    );
            }
            else
            {
                $slide['pages'] = array(array(
                    'content' => $slide['content'],
                    'content_html' => DOMParseUtils::saveChildren($slide['content']),
                ));
            }
        }
        return $slides;
    }

    /* Parse text using a copy of $wgParser and return resulting HTML */
    var $slideParser, $parserOptions;
    function parse($text, $inline = false)
    {
        global $wgParser, $wgUser;
        if (!$this->slideParser)
        {
            $this->slideParser = clone $wgParser;
            $this->slideParser->setHook('slide', 'S5SlideShow::fakeSlide');
            $this->parserOptions = ParserOptions::newFromUser($wgUser);
            $this->parserOptions->setEditSection(false);
            $this->parserOptions->setNumberHeadings(false);
            $output = $this->slideParser->parse(" ", $this->sTitle, $this->parserOptions, false, true);
        }
        $this->slideParser->mShowToc = false;
        $text = str_replace("__TOC__", '', trim($text));
        $output = $this->slideParser->parse(
            "$text __NOTOC__ __NOEDITSECTION__", $this->sTitle,
            $this->parserOptions, !$inline, false
        );
        return $output->getText();
    }

    /* Generate presentation HTML code */
    function genSlideFile()
    {
        global $egS5SlideTemplateFile;
        global $wgUser, $wgContLang, $wgOut;

        if (!$this->mSlides)
            $this->mSlides = $this->loadContent($this->mPageContent);

        /* load template contents */
        $ce_slide_tpl = @file_get_contents($egS5SlideTemplateFile);
        if (!$ce_slide_tpl)
            return false;

        $fc = '';
        foreach ($this->mSlides as $slide)
        {
            $sc = count($slide['pages']);
            foreach ($slide['pages'] as $i => $page)
            {
                $title = $slide['title_html'];
                $slideContent = $page['content_html'];
                /* make slide lists incremental if needed */
                if ($slide['incremental'])
                {
                    $slideContent = str_replace('<ul>', '<ul class="anim">', $slideContent);
                    $slideContent = str_replace('<ol>', '<ol class="anim">', $slideContent);
                }
                $slideContent = "<div class=\"slidecontent\">$slideContent</div>";
                if (trim(strip_tags($title)))
                {
                    if ($sc > 1)
                        $title .= " (".($i+1)."/$sc)";
                    $fc .= "<div class=\"slide\"><h1>$title</h1>$slideContent</div>\n";
                }
                else
                    $fc .= "<div class=\"slide notitle\">$slideContent</div>\n";
            }
        }

        /* build replacement array for presentation template */
        $replace = array();
        foreach(split(' ', 'title subtitle author footer subfooter addcss') as $v)
            $replace["[$v]"] = $this->parse($this->$v, true);
        $replace['[addcss]'] = strip_tags($replace['[addcss]']);
        $replace['[style]'] = $this->style;
        $replace['[content]'] = $fc;
        $replace['[pageid]'] = $this->sArticle->getID();
        $replace['[scaled]'] = $this->scaled ? 'true' : 'false';

        /* substitute values */
        $fileContent = str_replace(
            array_keys($replace),
            array_values($replace),
            $ce_slide_tpl
        );
        $fileContent = $wgContLang->Convert($fileContent);

        /* output generated content */
        $wgOut->disable();
        header("Content-Type: text/html; charset=utf-8");
        echo $fileContent;
    }

    /* Function to replace URLs in S5 skin stylesheet
     * $m is the match array coming from preg_replace_callback
     */
    static function styleReplaceUrl($skin, $m)
    {
        $t = Title::newFromText("S5/$skin/".$m[1], NS_FILE);
        $f = wfLocalFile($t);
        if ($f->exists())
            return 'url('.$f->getFullUrl().')';
        // FIXME remove hardcode extensions/S5SlideShow/
        // Replace images with invalid names with blank.gif
        if (preg_match('/[^a-z0-9_\-\.]/is', $m[1]))
            return 'url(extensions/S5SlideShow/blank.gif)';
        return "url(extensions/S5SlideShow/$skin/".$m[1].')';
    }

    /* Generate CSS stylesheet for a given S5 skin */
    // TODO cache generated stylesheets and flush the cache after saving style articles
    static function genStyle($skin)
    {
        global $wgOut;
        $dir = dirname(__FILE__);
        $css = '';
        foreach (S5SlideShowHooks::$styles as $k => $file)
        {
            $title = Title::newFromText("S5/$skin/$k", NS_MEDIAWIKI);
            if ($title->exists())
            {
                $a = new Article($title);
                $c = $a->getContent();
            }
            else
                $c = @file_get_contents("$dir/".str_replace('$skin', $skin, $file));
            $c = preg_replace_callback('#url\(([^\)]*)\)#is', create_function('$m', 'return S5SlideShow::styleReplaceUrl("'.$skin.'", $m);'), $c);
            $css .= $c;
        }
        $wgOut->disable();
        header("Content-Type: text/css");
        echo $css;
    }

    /* Hook function to extract arguments from article content */
    function slide_for_args($content, $attr, $parser)
    {
        $attr['content'] = $content;
        $this->setAttributes($attr);
        return '';
    }

    /* Normal parser hook for <slide> */
    static function slide($content, $attr, $parser)
    {
        global $wgScriptPath, $wgParser;
        if (!($title = $parser->getTitle()))
        {
            wfDebug(__CLASS__.': no title object in parser');
            return '';
        }
        /* Create slide show object */
        $attr['content'] = $content;
        $slideShow = new S5SlideShow($title, NULL, $attr);
        $url = $title->escapeLocalURL("action=slide");
        $stylepreview = $wgScriptPath."/extensions/S5SlideShow/".$slideShow->style."/preview.png";
        return "<div class=\"floatright\"><span>
<a href=\"$url\" class=\"image\" title=\"Slide Show\" target=\"_blank\">
<img src=\"$stylepreview\" alt=\"Slide Show\" width=\"240px\" /><br />
Slide Show</a></span></div>" . $wgParser->parse($content, $title, $wgParser->mOptions, false, false)->getText();
    }

    /* Empty parser hook for <slide> */
    static function fakeSlide()
    {
        return '';
    }

    /* Check whether $haystack begins or ends with $needle, and if yes,
       remove $needle from it and return true. */
    static function strCheck(&$haystack, $needle)
    {
        $needle = mb_strtolower($needle);
        if (mb_strtolower(mb_substr($haystack, 0, mb_strlen($needle))) == $needle)
            $haystack = trim(mb_substr($haystack, mb_strlen($needle)));
        elseif (mb_strtolower(mb_substr($haystack, -mb_strlen($needle))) == $needle)
            $haystack = trim(mb_substr($haystack, 0, -mb_strlen($needle)));
        else
            return false;
        return true;
    }
}

// Used to display CSS files instead of unexisting special articles (MediaWiki:S5/<skin>/<stylesheet>)
class S5SkinArticle extends Article
{
    var $s5skin, $s5file;
    // Create the object and remember s5skin and s5file
    public function __construct($title, $s5skin, $s5file)
    {
        $this->mTitle = &$title;
        $this->mOldId = NULL;
        $this->s5skin = $s5skin;
        $this->s5file = $s5file;
    }
    // Get content from the file
    public function getContent()
    {
        if ($this->getID() == 0)
            $this->mContent = @file_get_contents($this->s5file);
        else
            $this->loadContent();
        return $this->mContent;
    }
    // Show default content from the file
    public function showMissingArticle()
    {
        global $wgOut, $wgRequest, $wgParser;
        // Copy-paste from includes/Article.php:
        // Show delete and move logs
        LogEventsList::showLogExtract( $wgOut, array( 'delete', 'move' ), $this->mTitle->getPrefixedText(), '',
            array(  'lim' => 10,
                'conds' => array( "log_action != 'revision'" ),
                'showIfEmpty' => false,
                'msgKey' => array( 'moveddeleted-notice' ) )
        );
        // Show error message
        $oldid = $this->getOldID();
        if ($oldid)
        {
            $text = wfMsgNoTrans(
                'missing-article', $this->mTitle->getPrefixedText(),
                wfMsgNoTrans('missingarticle-rev', $oldid)
            );
        }
        else
            $text = $this->getContent();
        if ($wgParser->mTagHooks['source'])
            $text = "<source lang='css'>\n$text\n</source>";
        $text = "<div class='noarticletext'>\n$text\n</div>";
        $wgOut->addWikiText($text);
    }
}
