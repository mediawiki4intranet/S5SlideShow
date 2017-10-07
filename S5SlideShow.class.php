<?php

/**
 * Extension to create slide shows from wiki pages using improved S5 (http://meyerweb.com/eric/tools/s5/)
 * Copyright (c) 2010+ Vitaliy Filippov <vitalif@mail.ru>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 59 Temple Place - Suite 330, Boston, MA 02111-1307, USA.
 * http://www.gnu.org/copyleft/gpl.html
 */

/**
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
        global $wgContLang, $wgUser, $egS5SlideHeadingMark, $egS5SlideIncMark, $egS5SlideCenterMark, $egS5DefaultStyle, $egS5Scaled;
        // Get attributes from tag content
        if (preg_match_all('/(?:^|\n)\s*;\s*([^:\s]*)\s*:\s*([^\n]*)/is', $attr['content'], $m, PREG_SET_ORDER) > 0)
            foreach ($m as $set)
                $attr[$set[1]] = trim($set[2]);
        // Default values
        $attr = $attr + array(
            'title'       => $this->sTitle->getText(),
            'subtitle'    => '',
            'footer'      => $this->sTitle->getText(),
            'headingmark' => $egS5SlideHeadingMark,
            'incmark'     => $egS5SlideIncMark,
            'centermark'  => $egS5SlideCenterMark,
            'style'       => $egS5DefaultStyle,
            'font'        => '',
            // Backwards compatibility: appended CSS
            'addcss'      => '',
            // Is each slide to be scaled independently?
            'scaled'      => $egS5Scaled,
        );
        // Boolean value
        $attr['scaled'] = $attr['scaled'] == 'true' || $attr['scaled'] == 'yes' || $attr['scaled'] == 1;
        // Default author = first revision's author
        if (!isset($attr['author']))
        {
            $rev = $this->sArticle->getOldestRevision();
            if ($rev)
            {
                $attr['author'] = User::newFromId($rev->getUser())->getRealName();
            }
            else
            {
                // Not saved yet
                $attr['author'] = $wgUser->getRealName();
            }
        }
        // Author and date in the subfooter by default
        if (!isset($attr['subfooter']))
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
        $this->attr = $attr + $this->attr;
    }

    /**
     * Checks heading text for headingmark, incmark, centermark
     * Returns NULL or array(title, incremental, centered)
     */
    function check_slide_heading($node)
    {
        $ot = trim($node->nodeValue, "= \n\r\t\v");
        $st = $this->attr['headingmark'] ? preg_replace($this->heading_re, '', $ot) : $ot;
        if (!$this->attr['headingmark'] || $st != $ot)
        {
            $inc = false;
            $center = false;
            if ($this->inc_re)
            {
                $t = preg_replace($this->inc_re, '', $st);
                if ($t != $st)
                {
                    $inc = true;
                    $st = $t;
                }
            }
            if ($this->center_re)
            {
                $t = preg_replace($this->center_re, '', $st);
                if ($t != $st)
                {
                    $center = true;
                    $st = $t;
                }
            }
            return array($st, $inc, $center);
        }
        return NULL;
    }

    /**
     * This function transforms section slides into <slides> tags
     */
    function transform_section_slides($content)
    {
        wfProfileIn(__METHOD__);
        $p = $this->getParser();
        $content = $p->preprocess($content, $this->sTitle, $this->parserOptions);
        $p->setOutputType(Parser::OT_WIKI);
        $node = $p->getPreprocessor()->preprocessToObj($content);
        if (!$node instanceof PPNode_DOM)
        {
            die("S5SlideShow extension requires DOM support and usage of DOM MediaWiki preprocessor");
        }
        $doc = $node->node->ownerDocument;
        $all = $node->node->childNodes;
        if ($this->attr['headingmark'])
        {
            $this->heading_re = '/'.str_replace('/', '\\/', preg_quote($this->attr['headingmark'])).'/';
        }
        if ($this->attr['incmark'])
        {
            $this->inc_re = '/'.str_replace('/', '\\/', preg_quote($this->attr['incmark'])).'/';
        }
        if ($this->attr['centermark'])
        {
            $this->center_re = '/'.str_replace('/', '\\/', preg_quote($this->attr['centermark'])).'/';
        }
        for ($i = 0; $i < $all->length; $i++)
        {
            $c = $all->item($i);
            if ($c->nodeName == 'h' && ($st = $this->check_slide_heading($c)) !== NULL)
            {
                /**
                 * Add <ext><name>slides</name><attr>ATTRS</attr><inner>CONTENT</inner></ext>
                 */
                $slide = $doc->createElement('ext');
                $e = $doc->createElement('name');
                $e->nodeValue = 'slides';
                $slide->appendChild($e);
                $e = $doc->createElement('attr');
                $v = ' title="'.htmlspecialchars($st[0]).'"';
                if ($st[1])
                    $v .= ' incremental="1"';
                if ($st[2])
                    $v .= ' center="1"';
                $e->nodeValue = htmlspecialchars($v);
                $slide->appendChild($e);
                $e = $doc->createElement('inner');
                $slide->appendChild($e);
                $e1 = $doc->createElement('close');
                $e1->nodeValue = "</slides>\n";
                $slide->appendChild($e1);
                $node->node->insertBefore($slide, $c);
                $node->node->removeChild($c);
                // Move children of $node to $e, up to first <ext>
                // with name="slide(s)" or heading of same or greater level
                for ($j = $i+1; $j < $all->length; )
                {
                    $d = $all->item($j);
                    if ($d->nodeName == 'h')
                    {
                        break;
                    }
                    if ($d->nodeName == 'ext')
                    {
                        $name = $d->getElementsByTagName('name');
                        if (count($name) != 1)
                        {
                            die(__METHOD__.': Internal error, <ext> without <name> in DOM text');
                        }
                        if (substr($name->item(0)->nodeValue, 0, 5) == 'slide')
                        {
                            break;
                        }
                    }
                    $node->node->removeChild($d);
                    $e->appendChild($d);
                }
            }
        }
        $frame = $p->getPreprocessor()->newFrame();
        $text = $frame->expand($node, PPFrame::RECOVER_ORIG);
        $text = $frame->parser->mStripState->unstripBoth($text);
        wfProfileOut(__METHOD__);
        return $text;
    }

    /**
     * Extract slides from wiki-text $content
     */
    function loadContent($content = NULL)
    {
        global $wgParser, $wgUser;
        if ($content === NULL)
        {
            $content = $this->pageContent;
        }
        $this->getParser();
        $p = new Parser;
        $p->extS5 = $this;
        $p->extS5Hooks = 'parse';
        $p->parse($content, $this->sTitle, $this->parserOptions);
        if ($this->attr['headingmark'] !== false)
        {
            $content = $this->transform_section_slides($content);
        }
        $this->slides = array();
        $this->css = array();
        $this->parse($content);
        foreach ($this->slides as &$slide)
        {
            $slide['content_html'] = $this->parse($slide['content']);
            if ($slide['title'])
            {
                $slide['title_html'] = $this->parse($slide['title'], true);
            }
            else
            {
                $slide['title_html'] = '';
            }
        }
        return $this->slides;
    }

    /**
     * Parse slide content using a copy of $wgParser,
     * save slides and slide stylesheets into $this and return resulting HTML
     */
    function parse($text, $inline = false, $title = NULL)
    {
        global $wgOut;
        if (!$title)
            $title = $this->sTitle;
        $text = str_replace("__TOC__", '', trim($text));
        $prev = S5SlideShowHooks::$parsingSlide;
        S5SlideShowHooks::$parsingSlide = true;
        $output = $this->getParser()->parse(
            "$text __NOTOC__ __NOEDITSECTION__", $title,
            $this->parserOptions, !$inline, false
        );
        S5SlideShowHooks::$parsingSlide = $prev;
        $wgOut->addParserOutput($output);
        return $output->getText();
    }

    // Create parser object for $this->parse()
    function getParser()
    {
        if ($this->slideParser)
            return $this->slideParser;
        global $wgParser, $wgUser;
        $this->parserOptions = ParserOptions::newFromUser($wgUser);
        $this->parserOptions->setEditSection(false);
        $this->parserOptions->setNumberHeadings(false);
        $this->parserOptions->enableLimitReport(false);
        // Since $this->parse() is only used in ?action=slide,
        // we can use it directly without cloning or creating a new object
        // But $wgParser may be a StubObject, so trigger unstub and first call init
        $wgParser->parse(" ", $this->sTitle, $this->parserOptions, false, true);
        $wgParser->setHook('slideshow', 'S5SlideShow::empty_tag_hook');
        $wgParser->setHook('slide', 'S5SlideShow::empty_tag_hook');
        $wgParser->setHook('slides', array($this, 'slides_parse'));
        $wgParser->setHook('slidecss', array($this, 'slidecss_parse'));
        $wgParser->mShowToc = false;
        return $this->slideParser = $wgParser;
    }

    function getHeadItems()
    {
        // Extract loader scripts and styles from OutputPage::headElement()
        global $wgOut;
        $wgOut->getContext()->setSkin($skin = new SkinApiClean());
        $s = $wgOut->headElement($skin);
        preg_match_all('/<script[^<>]*>.*?<\/script>|'.
            '<link[^<>]*rel="stylesheet"[^<>]*>|'.
            '<meta[^<>]*name="ResourceLoaderDynamicStyles"[^<>]*>/is', $s, $m, PREG_PATTERN_ORDER);
        return implode("\n", array_filter($m[0], function($s) { return strpos($s, 'commonPrint') === false; }));
     }

    /**
     * Generate presentation HTML code
     */
    function genSlideFile($printPageSize = false)
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
        foreach(explode(' ', 'title subtitle author footer subfooter addcss') as $v)
            $replace["[$v]"] = $this->parse($this->attr[$v], true);
        $replace['[addcss]'] = implode("\n", $this->css);
        if ($this->attr['font'])
            $replace['[addcss]'] .= "\n.slide, div.header, div.footer { font-family: {$this->attr['font']}; }";
        $replace['[addcss]'] = strip_tags($replace['[addcss]']);
        $replace['[addscript]'] = '';
        $replace['[style]'] = $this->attr['style'];
        $replace['[styleurl]'] = 'index.php?action=slide&s5skin='.$this->attr['style'].'&s5css=1';
        $replace['[pageid]'] = $this->sArticle->getID();
        $replace['[scaled]'] = $this->attr['scaled'] ? 'true' : 'false';
        $replace['[defaultView]'] = 'slideshow';

        if ($printPageSize)
        {
            // Default DPI
            $dpi = 96;
            $replace['[styleurl]'] .= '&print='.implode('x', $printPageSize = array_map('intval', $printPageSize));
            $replace['[addcss]'] .= '@page {size: '.$printPageSize[0].'mm '.$printPageSize[1]."mm;}\n".
                '.body {width: '.($w = floor($printPageSize[0]*$dpi/25.4)).
                'px; height: '.($h = floor($printPageSize[1]*$dpi/25.4))."px;}\n";
            $replace['[addscript]'] .= "var s5PrintPageSize = [ $w, $h ];\n";
            $replace['[defaultView]'] = 'print';
        }

        $slides_html = '';
        $slide0 = " visible";
        if (trim($replace['[author]']) !== '' && trim($replace['[title]']) !== '')
        {
            $slides_html .= '<div class="slide'.$slide0.'"><h1 class="stitle" style="margin-top: 0">'.$replace['[title]'].
                '</h1><div class="slidecontent"><h1 style="margin-top: 0; font-size: 60%">'.
                $replace['[subtitle]'].'</h1><h3>'.$replace['[author]'].'</h3></div></div>';
            $slide0 = '';
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
            {
                $center = $slide['center'] ? " notitle" : "";
                $slides_html .= "<div class='slide$slide0$center'><h1 class='stitle'>$t</h1>$c</div>\n";
            }
            else
                $slides_html .= "<div class='slide$slide0 notitle'>$c</div>\n";
            $slide0 = "";
        }

        // substitute values
        $replace['[headitems]'] = $this->getHeadItems();
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
    static function genStyle($skin, $print = false)
    {
        global $wgOut;
        $dir = dirname(__FILE__);
        $css = '';
        if ($print)
        {
            S5SlideShowHooks::$styles['print'] = 'print.css';
        }
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
    static function slideshow_legacy($content, $attr, $parser, $frame = NULL)
    {
        return self::slideshow_view($content, $attr, $parser, $frame,
            '<div style="width: 240px; color: red">Warning: legacy &lt;slide&gt;'.
            ' parser hook used, change it to &lt;slideshow&gt; please</div>'
        );
    }

    /**
     * Parse content using an existing parser and cloned options
     * without LimitReport, without EditSections
     */
    static function clone_options_parse($content, $parser, $inline = false)
    {
        if (!$parser->mTitle)
        {
            wfDebug(__METHOD__.": no title object in parser\n");
            return '';
        }
        $oldOpt = $parser->mOptions;
        if (!isset($parser->extClonedOptions))
        {
            if (!$oldOpt)
            {
                global $wgUser;
                $oldOpt = ParserOptions::newFromUser($wgUser);
            }
            $opt = clone $oldOpt;
            $opt->enableLimitReport(false);
            $opt->setEditSection(false);
            $parser->extClonedOptions = $opt;
        }
        $html = $parser->parse($content, $parser->mTitle, $parser->extClonedOptions, !$inline, false)->getText();
        $parser->mOptions = $oldOpt;
        return $html;
    }

    /**
     * <slideshow> - article view mode
     * displays a link to the slideshow and skin preview
     */
    static function slideshow_view($content, $attr, $parser, $frame = NULL, $addmsg = '')
    {
        global $wgScriptPath, $wgParser, $wgContLang;
        if (!$parser->mTitle)
        {
            wfDebug(__METHOD__.": no title object in parser\n");
            return '';
        }
        // Create slideshow object
        $attr['content'] = $content;
        $slideShow = new S5SlideShow($parser->mTitle, NULL, $attr);
        $content = '';
        $article = new Article($parser->mTitle);
        foreach (array('title', 'subtitle', 'author', 'footer', 'subfooter') as $key)
        {
            if (isset($slideShow->attr[$key]) && $slideShow->attr[$key] != '')
            {
                $value = $slideShow->attr[$key];
                if (mb_strpos($value, "{{date}}") !== false)
                {
                    $value = str_ireplace(
                        '{{date}}',
                        $wgContLang->timeanddate($article->getTimestamp(), true),
                        $value
                    );
                }
                $content .= "\n;" . wfMessage('s5slide-header-' . $key)->text() . ': '. $value;
            }
        }
        // FIXME remove hardcoded '.png', /extensions/S5SlideShow/, "Slide Show"
        $url = htmlspecialchars($parser->mTitle->getLocalUrl(array('action' => 'slide')));
        $style_title = Title::newFromText('S5-'.$slideShow->attr['style'].'-preview.png', NS_FILE);
        if ($style_title &&
            ($style_preview = wfLocalFile($style_title)) &&
            $style_preview->exists())
        {
            $style_preview = $style_preview->getTitle()->getPrefixedText();
            $style_preview = self::clone_options_parse("[[$style_preview|240px|link=]]", $wgParser, true);
        }
        else
        {
            $style_preview = '<img src="'.$wgScriptPath.'/extensions/S5SlideShow/'.
                $slideShow->attr['style'].'/preview.png" alt="Slide Show" width="240px" />';
        }
        $inside = self::clone_options_parse($content, $wgParser, true);
        $html =
            '<script type="text/javascript" src="'.$wgScriptPath.'/extensions/S5SlideShow/contentScale.js"></script>'.
            '<script type="text/javascript" src="'.$wgScriptPath.'/extensions/S5SlideShow/slideView.js"></script>'.
            '<div class="floatright" style="text-align: center"><span>'.
            '<a href="'.$url.'" class="image" title="Slide Show" target="_blank">'.$style_preview.
            '<br />'.
            'Slide Show</a></span>'.$addmsg.'</div>'.
            $inside;
        if (!empty($slideShow->attr['font']))
        {
            $html = '<script type="text/javascript">var wgSlideViewFont = "'.
                addslashes($slideShow->attr['font']).'";</script>' . $html;
        }
        $html = '<div id="slideshow-bundle">' . $html . '</div>';
        return $html;
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
        if (!empty($attr['split']))
            $slides = preg_split('/'.str_replace('/', '\\/', $attr['split']).'/', $content);
        else
            $slides = array($content);
        $html = '';
        $style = '';
        if (!isset($attr['float']))
            $style .= "float: left; ";
        if (isset($attr['width']))
            $style .= "width: $attr[width]px; ";
        if ($style)
            $style = " style='$style'";
        foreach ($slides as $i => $slide)
        {
            if (isset($attr['title']) && !$i)
            {
                $slide = "== $attr[title] ==\n".trim($slide);
                $st = 'slide withtitle';
            }
            else
                $st = 'slide';
            $output = self::clone_options_parse(trim($slide), $parser, false);
            $html .= '<div class="'.$st.'" '.$style.' id="slide'.(self::$slideno++).'">'.$output.'</div>';
        }
        if (!isset($attr['float']))
            $html .= '<div style="clear: both"></div>';
        else
            $html = "<div style='float: $attr[float]; margin: ".($attr['float'] == 'left' ? '0 1em 1em 0' : '0 0 0 1em')."'>$html</div>";
        return $html;
    }

    // <slides> - slideshow parse mode
    function slides_parse($content, $attr, $parser)
    {
        if (isset($attr['split']))
            $slides = preg_split('/'.str_replace('/', '\\/', $attr['split']).'/', $content);
        else
            $slides = array($content);
        foreach ($slides as $c)
        {
            $this->slides[] = array('content' => trim($c)) + $attr + array(
                'title' => '',
                'incremental' => false,
                'center' => false,
            );
            unset($attr['title']);
        }
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
        $this->mPage = $this->newPage( $title );
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
            $text = wfMessage(
                'missing-article', $this->mTitle->getPrefixedText(),
                wfMessage('missingarticle-rev', $oldid)->plain()
            )->plain();
        }
        else
            $text = $this->getContent();
        if ($wgParser->mTagHooks['source'])
            $text = "<source lang='css'>\n$text\n</source>";
        $text = "<div class='noarticletext'>\n$text\n</div>";
        $wgOut->addWikiText($text);
    }
}

class SkinApiClean extends SkinApi
{
    public function setupSkinUserCss(OutputPage $out)
    {
        SkinTemplate::setupSkinUserCss($out);
    }
}
