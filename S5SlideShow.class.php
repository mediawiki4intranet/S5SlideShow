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

class S5SlideShow
{
    var $sTitle, $sArticle, $pageContent;

    var $slideParser, $parserOptions;
    static $slideno = 0;

    var $slides, $css, $attr;

    /**
     * Constructor. If $attr is not an array(), article content
     * will be parsed and attributes will be taken from there.
     */
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
            $this->pageContent = $sContent;
        else
            $this->pageContent = $this->sArticle->getContent();
        $this->attr = array();
        if (is_array($attr))
            $this->setAttributes($attr);
    }

    /**
     * Parse attribute hash and save them into $this
     */
    function setAttributes($attr)
    {
        global $wgContLang, $wgUser, $egS5SlideHeadingMark, $egS5SlideIncMark;
        // Get attributes from tag content
        if (preg_match_all('/(?:^|\n)\s*;\s*([^:\s]*)\s*:\s*([^\n]*)/is', $attr['content'], $m, PREG_SET_ORDER) > 0)
            foreach ($m as $set)
                $attr[$set[1]] = $set[2];
        // Default slide show title = page title
        if (!array_key_exists('title', $attr))
            $attr['title'] = $this->sTitle->getText();
        $attr['title'] = trim($attr['title']);
        // No subtitle by default
        // Default author = last revision's author
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
        // Slideshow title in the footer by default
        if (!array_key_exists('footer', $attr))
            $attr['footer'] = $attr['title'];
        // Author and date in the subfooter by default
        if (!array_key_exists('subfooter', $attr))
        {
            $attr['subfooter'] = $attr['author'];
            if ($attr['subfooter'])
                $attr['subfooter'] .= ', ';
            $attr['subfooter'] .= $wgContLang->timeanddate($this->sArticle->getTimestamp(), true);
        }
        else
        {
            $attr['subfooter'] = str_ireplace(
                '{{date}}',
                $wgContLang->timeanddate($this->sArticle->getTimestamp(), true),
                $attr['subfooter']
            );
        }
        // Default heading mark = $egS5SlideHeadingMark
        if (!array_key_exists('headingmark', $attr))
            $attr['headingmark'] = $egS5SlideHeadingMark;
        $attr['headingmark'] = trim($attr['headingmark']);
        // Default incremental mark = $egS5SlideIncMark
        $attr['incmark'] = trim($attr['incmark']);
        if (!$attr['incmark'])
            $attr['incmark'] = $egS5SlideIncMark;
        // Default style = "default"
        $attr['style'] = trim($attr['style']);
        if (!$attr['style'])
            $attr['style'] = 'default';
        // Backwards compatibility: appended CSS
        $attr['addcss'] = trim($attr['addcss']);
        // Is each slide to be scaled independently?
        $attr['scaled'] = strtolower(trim($attr['scaled']));
        $attr['scaled'] = $attr['scaled'] == 'true' || $attr['scaled'] == 'yes' || $attr['scaled'] == 1;
        $this->attr = $attr + $this->attr;
    }

    /**
     * Checks heading text for 'headingmark' and 'incmark'
     */
    function is_slide_heading($node)
    {
        $ot = trim($node->nodeValue, "= \n\r\t\v");
        $st = preg_replace($this->heading_re, '', $ot);
        if ($st != $ot)
        {
            if ($this->inc_re)
            {
                $it = preg_replace($this->inc_re, '', $st);
                if ($it != $st)
                    return array($it, true);
            }
            return array($st, false);
        }
        return NULL;
    }

    /**
     * This function transforms slides specified as subsection
     * into <slides> tags.
     */
    function transform_section_slides($content)
    {
        $p = $this->getParser();
        $p->setOutputType(Parser::OT_WIKI);
        $node = $p->getPreprocessor()->preprocessToObj($content);
        $doc = $node->node->ownerDocument;
        $all = $node->node->childNodes;
        $this->heading_re = '/'.str_replace('/', '\\/', $this->attr['headingmark']).'\s*/';
        if (strlen($this->incmark))
            $this->inc_re = '/'.str_replace('/', '\\/', $this->attr['incmark']).'\s*/';
        for ($i = 0; $i < $all->length; $i++)
        {
            $c = $all->item($i);
            if ($c->nodeName == 'h' && ($st = $this->is_slide_heading($c)) !== NULL)
            {
                $slide = $doc->createElement('ext');
                $e = $doc->createElement('name');
                $e->nodeValue = 'slides';
                $slide->appendChild($e);
                $e = $doc->createElement('attr');
                $e->nodeValue = ' title="'.htmlspecialchars($st[0]).'"';
                if ($st[1])
                    $e->nodeValue .= ' incremental="1"';
                $slide->appendChild($e);
                $e = $doc->createElement('inner');
                $slide->appendChild($e);
                $e1 = $doc->createElement('close');
                $e1->nodeValue = '</slides>';
                $slide->appendChild($e1);
                $node->node->insertBefore($slide, $c);
                $node->node->removeChild($c);
                for ($j = $i+1; $j < $all->length; )
                {
                    $d = $all->item($j);
                    if ($this->is_slide_heading($d) !== NULL)
                        break;
                    if ($d->nodeName == 'ext')
                    {
                        $name = $d->getElementsByTagName('name');
                        if (count($name) != 1)
                            die(__METHOD__.': Internal error, <ext> without <name> in DOM text');
                        if (substr($name->item(0)->nodeValue, 0, 5) == 'slide')
                            break;
                    }
                    $node->node->removeChild($d);
                    $e->appendChild($d);
                }
            }
        }
        $frame = $p->getPreprocessor()->newFrame();
        $text = $frame->expand($node, PPFrame::RECOVER_ORIG);
        $text = $frame->parser->mStripState->unstripBoth($text);
        return $text;
    }

    /**
     * Extract slides from wiki-text $content
     */
    function loadContent($content = NULL)
    {
        global $wgParser, $wgUser;
        if ($content === NULL)
            $content = $this->pageContent;
        $this->getParser();
        $p = new Parser;
        $p->setHook('slideshow', array($this, 'slideshow_parse'));
        $p->setHook('slide', array($this, 'slideshow_parse'));
        $p->setHook('slides', 'S5SlideShow::empty_tag_hook');
        $p->setHook('slidecss', 'S5SlideShow::empty_tag_hook');
        $p->parse($content, $this->sTitle, $this->parserOptions);
        if ($this->attr['headingmark'])
            $content = $this->transform_section_slides($content);
        $this->slides = array();
        $this->css = array();
        $this->parse($content);
        foreach ($this->slides as &$slide)
        {
            $slide['content_html'] = $this->parse($slide['content']);
            if ($slide['title'])
                $slide['title_html'] = $this->parse($slide['title'], true);
        }
        return $this->slides;
    }

    /**
     * Parse slide content using a copy of $wgParser,
     * save slides and slide stylesheets into $this and return resulting HTML
     */
    function parse($text, $inline = false, $title = NULL)
    {
        if (!$title)
            $title = $this->sTitle;
        $text = str_replace("__TOC__", '', trim($text));
        $output = $this->getParser()->parse(
            "$text __NOTOC__ __NOEDITSECTION__", $title,
            $this->parserOptions, !$inline, false
        );
        return $output->getText();
    }

    // Create parser object for $this->parse()
    function getParser()
    {
        if ($this->slideParser)
            return $this->slideParser;
        global $wgParser, $wgUser;
        $this->slideParser = clone $wgParser;
        $this->slideParser->setHook('slideshow', 'S5SlideShow::empty_tag_hook');
        $this->slideParser->setHook('slide',     'S5SlideShow::empty_tag_hook');
        $this->slideParser->setHook('slides',    array($this, 'slides_parse'));
        $this->slideParser->setHook('slidecss',  array($this, 'slidecss_parse'));
        $this->slideParser->mShowToc = false;
        $this->parserOptions = ParserOptions::newFromUser($wgUser);
        $this->parserOptions->setEditSection(false);
        $this->parserOptions->setNumberHeadings(false);
        $output = $this->slideParser->parse(" ", $this->sTitle, $this->parserOptions, false, true);
        return $this->slideParser;
    }

    /**
     * Generate presentation HTML code
     */
    function genSlideFile()
    {
        global $egS5SlideTemplateFile;
        global $wgUser, $wgContLang, $wgOut;

        if (!$this->slides)
            $this->loadContent();

        // load template contents
        $slide_template = @file_get_contents($egS5SlideTemplateFile);
        if (!$slide_template)
            return false;

        // build replacement array for slide show template
        $replace = array();
        foreach(split(' ', 'title subtitle author footer subfooter addcss') as $v)
            $replace["[$v]"] = $this->parse($this->attr[$v], true);
        $replace['[addcss]'] = implode("\n", $this->css);
        if ($this->attr['font'])
            $replace['[addcss]'] .= "\n.slide { font-family: {$this->attr['font']}; }";
        $replace['[addcss]'] .= strip_tags($replace['[addcss]']);
        $replace['[style]'] = $this->attr['style'];
        $replace['[pageid]'] = $this->sArticle->getID();
        $replace['[scaled]'] = $this->attr['scaled'] ? 'true' : 'false';

        $slides_html = '';
        if ($replace['[author]'] !== '' && $replace['[title]'] !== '')
        {
            $slides_html .= '<div class="slide"><h1 class="stitle" style="margin-top: 0">'.$replace['[title]'].
                '</h1><div class="slidecontent"><h1 style="margin-top: 0; font-size: 60%">'.
                $replace['[subtitle]'].'</h1><h3>'.$replace['[author]'].'</h3></div></div>';
        }
        foreach ($this->slides as $slide)
        {
            $c = $slide['content_html'];
            $t = $slide['title_html'];
            // make slide lists incremental if needed
            if ($slide['incremental'])
            {
                $c = str_replace('<ul>', '<ul class="anim">', $c);
                $c = str_replace('<ol>', '<ol class="anim">', $c);
            }
            $c = "<div class='slidecontent'>$c</div>";
            if (trim(strip_tags($t)))
                $slides_html .= "<div class='slide'><h1 class='stitle'>$t</h1>$c</div>\n";
            else
                $slides_html .= "<div class='slide notitle'>$c</div>\n";
        }

        // substitute values
        $replace['[content]'] = $slides_html;
        $html = str_replace(
            array_keys($replace),
            array_values($replace),
            $slide_template
        );
        $html = $wgContLang->convert($html);

        // output generated content
        $wgOut->disable();
        header("Content-Type: text/html; charset=utf-8");
        echo $html;
    }

    /**
     * Function to replace URLs in S5 skin stylesheet
     * $m is the match array coming from preg_replace_callback
     */
    static function styleReplaceUrl($skin, $m)
    {
        $t = Title::newFromText($m[1], NS_FILE);
        $f = wfLocalFile($t);
        if ($f->exists())
            return 'url('.$f->getFullUrl().')';
        // FIXME remove hardcode extensions/S5SlideShow/
        // Replace images with invalid names with blank.gif
        if (preg_match('/[^a-z0-9_\-\.]/is', $m[1]))
            return 'url(extensions/S5SlideShow/blank.gif)';
        return "url(extensions/S5SlideShow/$skin/".$m[1].')';
    }

    /**
     * Generate CSS stylesheet for a given S5 skin
     * TODO cache generated stylesheets and flush the cache after saving style articles
     */
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

    /**
     * <slideshow> - article view mode, backwards compatibility
     */
    static function slideshow_legacy($content, $attr, $parser)
    {
        return self::slideshow_view($content, $attr, $parser,
            '<div style="width: 240px; color: red">Warning: legacy &lt;slide&gt;'.
            ' parser hook used, change it to &lt;slideshow&gt; please</div>'
        );
    }

    /**
     * <slideshow> - article view mode
     * displays a link to the slideshow and skin preview
     */
    static function slideshow_view($content, $attr, $parser, $addmsg = '')
    {
        global $wgScriptPath, $wgParser;
        if (!($title = $parser->getTitle()))
        {
            wfDebug(__CLASS__.': no title object in parser');
            return '';
        }
        // Create slideshow object
        $attr['content'] = $content;
        $slideShow = new S5SlideShow($title, NULL, $attr);
        $url = $title->escapeLocalURL(array('action' => 'slide'));
        $stylepreview = $wgScriptPath."/extensions/S5SlideShow/".$slideShow->attr['style']."/preview.png";
        return
            '<script type="text/javascript">var wgSlideViewFont = "'.addslashes($slideShow->attr['font']).'";</script>'.
            '<script type="text/javascript" src="'.$wgScriptPath.'/extensions/S5SlideShow/contentScale.js"></script>'.
            '<script type="text/javascript" src="'.$wgScriptPath.'/extensions/S5SlideShow/slideView.js"></script>'.
            '<div class="floatright" style="text-align: center"><span>'.
            '<a href="'.$url.'" class="image" title="Slide Show" target="_blank">'.
            '<img src="'.$stylepreview.'" alt="Slide Show" width="240px" /><br />'.
            'Slide Show</a></span>'.$addmsg.'</div>'.
            $wgParser->parse($content, $title, $wgParser->mOptions, false, false)->getText();
    }

    // <slideshow> - slideshow parse mode
    // saves parameters into $this
    function slideshow_parse($content, $attr, $parser)
    {
        $attr['content'] = $content;
        $this->setAttributes($attr);
        return '';
    }

    // <slides> - article view mode
    static function slides_view($content, $attr, $parser)
    {
        if ($attr['split'])
            $slides = preg_split('/'.str_replace('/', '\\/', $attr['split']).'/', $content);
        else
            $slides = array($content);
        $html = '';
        foreach ($slides as $slide)
        {
            $output = $parser->parse($slide, $parser->mTitle, $parser->mOptions, true, false);
            $html .= '<div class="slide" id="slide'.(self::$slideno++).'">'.
                $output->getText().'</div>';
        }
        $html .= '<div style="clear: both"></div>';
        return $html;
    }

    // <slides> - slideshow parse mode
    function slides_parse($content, $attr, $parser)
    {
        if ($attr['split'])
            $slides = preg_split('/'.str_replace('/', '\\/', $attr['split']).'/', $content);
        else
            $slides = array($content);
        foreach ($slides as $c)
            $this->slides[] = array('content' => $c) + $attr;
    }

    // <slidecss> - article view mode
    static function slidecss_view($content, $attr, $parser)
    {
        // use this CSS only for <slidecss view="true">
        if ($attr['view'] == 'true' || $attr['view'] == '1')
            $parser->mOutput->addHeadItem('<style type="text/css">'.$content.'</style>');
        return '';
    }

    // <slidecss> - slideshow parse mode
    function slidecss_parse($content, $attr, $parser)
    {
        $this->css[] = $content;
    }

    // stub for tag hooks
    static function empty_tag_hook()
    {
        return '';
    }

    /**
     * Check whether $haystack begins or ends with $needle, and if yes,
     * remove $needle from it and return true.
     */
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

// Used to display CSS files instead of non-existing special articles (MediaWiki:S5/<skin>/<stylesheet>)
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
