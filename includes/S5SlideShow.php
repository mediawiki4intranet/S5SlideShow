<?php
namespace MediaWiki\Extension\S5SlideShow;

use MediaWiki\MediaWikiServices;
use Parser;
use ParserOptions;
use MediaWiki\Title\Title;

class S5SlideShow {
    public $sTitle;
    public $pageContent;
    public $slideParser;
    public $parserOptions;
    public static $slideno = 0;
    public $slides = [];
    public $css = [];
    public $attr = [];

    public function __construct( Title $sTitle, $sContent = null, $attr = null ) {
        $this->sTitle = $sTitle;
        if ( $sContent !== null ) {
            $this->pageContent = $sContent;
        } else {
            $services = MediaWikiServices::getInstance();
            $wikiPage = $services->getWikiPageFactory()->newFromTitle( $sTitle );
            $this->pageContent = '';
            if ( $wikiPage->exists() ) {
                $contentObj = $wikiPage->getContent();
                if ( $contentObj instanceof \MediaWiki\Content\TextContent ) {
                    $this->pageContent = $contentObj->getText();
                }
            }
        }
        
        $this->setAttributes( is_array( $attr ) ? $attr : [] );
    }

    public function setAttributes( $attr ) {
        $services = MediaWikiServices::getInstance();
        $contLang = $services->getContentLanguage();
        $revStore = $services->getRevisionStore();

        if ( isset( $attr['content'] ) && preg_match_all( '/(?:^|\n)\s*;\s*([^:\s]*)\s*:\s*([^\n]*)/isu', $attr['content'], $m, PREG_SET_ORDER ) ) {
            foreach ( $m as $set ) {
                $attr[$set[1]] = trim( $set[2] );
            }
        }

        $attr += [
            'title'       => $this->sTitle->getText(),
            'subtitle'    => '',
            'footer'      => $this->sTitle->getText(),
            'headingmark' => '', // По умолчанию пустая строка означает "все заголовки"
            'incmark'     => '\(step\)',
            'centermark'  => '\(center\)',
            'style'       => 'default',
            'font'        => '',
            'addcss'      => '',
            'scaled'      => false,
        ];

        $attr['scaled'] = in_array( strtolower( (string)$attr['scaled'] ), [ 'true', 'yes', '1' ], true );

        if ( !isset( $attr['author'] ) ) {
            $rev = $revStore->getFirstRevision( $this->sTitle );
            if ( $rev && $rev->getUser() ) {
                // Принудительно загружаем полный профиль для получения реального имени
                $user = $services->getUserFactory()->newFromUserIdentity( $rev->getUser() );
                $user->load();
                $realName = $user->getRealName();
                $attr['author'] = $realName ? $realName : $user->getName();
            } else {
                $attr['author'] = '';
            }
        }

        $timestamp = $revStore->getKnownCurrentRevision( $this->sTitle ) ? $revStore->getKnownCurrentRevision( $this->sTitle )->getTimestamp() : wfTimestampNow();
        $dateStr = $contLang->timeanddate( $timestamp, true );

        if ( !isset( $attr['subfooter'] ) ) {
            $attr['subfooter'] = $attr['author'] ? $attr['author'] . ', ' . $dateStr : $dateStr;
        } else {
            $attr['subfooter'] = str_ireplace( '{{date}}', $dateStr, $attr['subfooter'] );
        }

        $this->attr = $attr + $this->attr;
    }

    public function extractSlidesFromWikitext( $text ) {
        $lines = explode( "\n", $text );
        $outText = "";
        $inSlide = false;
        $slideContent = "";
        $slideAttr = "";

        // Если маркер пустой (''), берем все заголовки. Если задан - фильтруем по нему.
        $hm = empty($this->attr['headingmark']) ? '' : preg_quote( $this->attr['headingmark'], '/' );
        $im = empty($this->attr['incmark']) ? '' : preg_quote( $this->attr['incmark'], '/' );
        $cm = empty($this->attr['centermark']) ? '' : preg_quote( $this->attr['centermark'], '/' );

        foreach ( $lines as $line ) {
            if ( preg_match( '/^(=+)\s*(.*?)\s*\1\s*$/u', trim( $line ), $m ) ) {
                $heading = $m[2];
                
                // ЛЮБОЙ заголовок прерывает текущий слайд (чтобы скрыть разделы без маркера)
                if ( $inSlide ) {
                    $outText .= "<slides{$slideAttr}>\n" . $slideContent . "\n</slides>\n";
                    $inSlide = false;
                }

                // Открываем новый слайд, если маркер пуст или присутствует в заголовке
                if ( $hm === '' || preg_match( '/' . $hm . '/u', $heading ) ) {
                    $inSlide = true;
                    $slideContent = "";
                    
                    $cleanHeading = $heading;
                    if ( $hm !== '' ) {
                        $cleanHeading = preg_replace( '/' . $hm . '/u', '', $cleanHeading );
                    }
                    
                    $inc = false;
                    $center = false;
                    
                    if ( $im !== '' && preg_match( '/' . $im . '/u', $cleanHeading ) ) {
                        $inc = true;
                        $cleanHeading = preg_replace( '/' . $im . '/u', '', $cleanHeading );
                    }
                    if ( $cm !== '' && preg_match( '/' . $cm . '/u', $cleanHeading ) ) {
                        $center = true;
                        $cleanHeading = preg_replace( '/' . $cm . '/u', '', $cleanHeading );
                    }
                    
                    $slideAttr = ' title="' . htmlspecialchars( trim( $cleanHeading ) ) . '"';
                    if ( $inc ) $slideAttr .= ' incremental="1"';
                    if ( $center ) $slideAttr .= ' center="1"';
                    continue;
                }
            }
            
            if ( $inSlide ) {
                $slideContent .= $line . "\n";
            } else {
                $outText .= $line . "\n";
            }
        }
        
        if ( $inSlide ) {
            $outText .= "<slides{$slideAttr}>\n" . $slideContent . "\n</slides>\n";
        }
        return $outText;
    }

    public function loadContent( $content = null ) {
        if ( $content === null ) {
            $content = $this->pageContent;
        }
        $services = MediaWikiServices::getInstance();
        $this->getParser();
        
        $p1 = $services->getParserFactory()->create();
        $p1->setHook( 'slideshow', [ $this, 'slideshow_parse' ] );
        $p1->setHook( 'slide', [ $this, 'slideshow_parse' ] );
        $p1->setHook( 'slides', [ self::class, 'empty_tag_hook' ] );
        $p1->setHook( 'slidecss', [ self::class, 'empty_tag_hook' ] );
        $p1->parse( $content, $this->sTitle, $this->parserOptions );

        $content = $this->extractSlidesFromWikitext( $content );

        $this->slides = [];
        $this->css = [];
        
        $p2 = $services->getParserFactory()->create();
        $p2->setHook( 'slideshow', [ self::class, 'empty_tag_hook' ] );
        $p2->setHook( 'slide', [ self::class, 'empty_tag_hook' ] );
        $p2->setHook( 'slides', [ $this, 'slides_parse' ] );
        $p2->setHook( 'slidecss', [ $this, 'slidecss_parse' ] );
        $p2->parse( $content, $this->sTitle, $this->parserOptions );

        foreach ( $this->slides as &$slide ) {
            $slide['content_html'] = $this->parse( $slide['content'] );
            $slide['title_html'] = $slide['title'] ? $this->parse( $slide['title'], true ) : '';
        }
        return $this->slides;
    }

    public function parse( $text, $inline = false, $title = null ) {
        if ( !$title ) {
            $title = $this->sTitle;
        }
        $text = str_replace( "__TOC__", '', trim( $text ) );
        $prev = Hooks::$parsingSlide;
        Hooks::$parsingSlide = true;
        
        $output = $this->getParser()->parse(
            $text . " __NOTOC__ __NOEDITSECTION__", 
            $title,
            $this->parserOptions, 
            !$inline, 
            false
        );
        
        Hooks::$parsingSlide = $prev;
        $html = trim( $output->getText() );
        
        // Безопасно вычищаем обертку mw-parser-output, которую добавляет MediaWiki 1.44
        if ( preg_match( '/^\s*<div\s+[^>]*class="[^"]*mw-parser-output[^"]*"[^>]*>(.*)<\/div>\s*$/isu', $html, $m ) ) {
            $html = trim( $m[1] );
        }
        // Для заголовков вычищаем еще и обертку <p>
        if ( $inline ) {
            if ( preg_match( '/^\s*<p>(.*)<\/p>\s*$/isu', $html, $m ) ) {
                $html = trim( $m[1] );
            }
        }
        
        return $html;
    }

    public function getParser() {
        if ( $this->slideParser ) {
            return $this->slideParser;
        }
        $services = MediaWikiServices::getInstance();
        $this->parserOptions = ParserOptions::newFromAnon();
        $this->parserOptions->enableLimitReport( false );
        
        $parser = $services->getParserFactory()->create();
        $parser->parse( "", $this->sTitle, $this->parserOptions, false, true );
        
        $parser->setHook( 'slideshow', [ self::class, 'empty_tag_hook' ] );
        $parser->setHook( 'slide', [ self::class, 'empty_tag_hook' ] );
        $parser->setHook( 'slides', [ $this, 'slides_parse' ] );
        $parser->setHook( 'slidecss', [ $this, 'slidecss_parse' ] );
        
        return $this->slideParser = $parser;
    }

    public function genSlideFile( $printPageSize = false ) {
        $services = MediaWikiServices::getInstance();
        $extPath = $services->getMainConfig()->get( 'ExtensionAssetsPath' ) . '/S5SlideShow';
        
        if ( !$this->slides ) {
            $this->loadContent();
        }

        $slide_template = file_get_contents( dirname( __DIR__ ) . '/slide.htm' );
        if ( !$slide_template ) return false;

        $replace = [];
        foreach ( [ 'title', 'subtitle', 'author', 'footer', 'subfooter', 'addcss' ] as $v ) {
            $replace["[$v]"] = $this->parse( $this->attr[$v] ?? '', true );
        }
        $replace['[addcss]'] = implode( "\n", $this->css );
        if ( $this->attr['font'] ) {
            $replace['[addcss]'] .= "\n.slide, div.header, div.footer { font-family: {$this->attr['font']}; }";
        }
        $replace['[addcss]'] = strip_tags( $replace['[addcss]'] );
        $replace['[addscript]'] = '';
        $replace['[style]'] = $this->attr['style'];
        $replace['[styleurl]'] = 'index.php?action=slide&s5skin=' . $this->attr['style'] . '&s5css=1';
        $replace['[scaled]'] = $this->attr['scaled'] ? 'true' : 'false';
        $replace['[defaultView]'] = 'slideshow';
        $replace['[extpath]'] = $extPath;
        $replace['[pageid]'] = $this->sTitle->getArticleID();
        $replace['[headitems]'] = ''; 

        if ( $printPageSize ) {
            $dpi = 96;
            $replace['[styleurl]'] .= '&print=' . implode( 'x', $printPageSize );
            $replace['[addcss]'] .= '@page {size: ' . $printPageSize[0] . 'mm ' . $printPageSize[1] . "mm;}\n" .
                '.body {width: ' . ( $w = floor( $printPageSize[0] * $dpi / 25.4 ) ) .
                'px; height: ' . ( $h = floor( $printPageSize[1] * $dpi / 25.4 ) ) . "px;}\n";
            $replace['[addscript]'] .= "var s5PrintPageSize = [ $w, $h ];\n";
            $replace['[defaultView]'] = 'print';
        }

        $slides_html = '';
        $slide0 = " visible";
        if ( trim( $replace['[author]'] ) !== '' && trim( $replace['[title]'] ) !== '' ) {
            $slides_html .= '<div class="slide' . $slide0 . '"><h1 class="stitle" style="margin-top: 0">' . $replace['[title]'] .
                '</h1><div class="slidecontent"><h1 style="margin-top: 0; font-size: 60%">' .
                $replace['[subtitle]'] . '</h1><h3>' . $replace['[author]'] . '</h3></div></div>';
            $slide0 = '';
        }
        
        foreach ( $this->slides as $slide ) {
            $c = $slide['content_html'];
            $t = $slide['title_html'];
            if ( $slide['incremental'] ) {
                $c = str_replace( '<ul>', '<ul class="anim">', $c );
                $c = str_replace( '<ol>', '<ol class="anim">', $c );
            }
            $c = "<div class='slidecontent'>$c</div>";
            if ( trim( strip_tags( $t ) ) ) {
                $center = $slide['center'] ? " notitle" : "";
                $slides_html .= "<div class='slide$slide0$center'><h1 class='stitle'>$t</h1>$c</div>\n";
            } else {
                $slides_html .= "<div class='slide$slide0 notitle'>$c</div>\n";
            }
            $slide0 = "";
        }

        $replace['[content]'] = $slides_html;
        $html = str_replace( array_keys( $replace ), array_values( $replace ), $slide_template );

        header( "Content-Type: text/html; charset=utf-8" );
        echo $html;
        die();
    }

    public static function styleReplaceUrl( $skin, $m ) {
        $services = MediaWikiServices::getInstance();
        $t = Title::newFromText( $m[1], NS_FILE );
        $f = $services->getRepoGroup()->getLocalRepo()->newFile( $t );
        if ( $f && $f->exists() ) {
            return 'url(' . $f->getFullUrl() . ')';
        }
        if ( preg_match( '/[^a-z0-9_\-\.]/is', $m[1] ) ) {
            return 'url(' . $services->getMainConfig()->get('ExtensionAssetsPath') . '/S5SlideShow/blank.gif)';
        }
        return 'url(' . $services->getMainConfig()->get('ExtensionAssetsPath') . "/S5SlideShow/$skin/" . $m[1] . ')';
    }

    public static function genStyle( $skin, $print = false ) {
        $dir = dirname( __DIR__ );
        $css = '';
        if ( $print ) {
            Hooks::$styles['print'] = 'print.css';
        }
        
        $services = MediaWikiServices::getInstance();
        foreach ( Hooks::$styles as $k => $file ) {
            $title = Title::newFromText( "S5/$skin/$k", NS_MEDIAWIKI );
            $c = '';
            if ( $title && $title->exists() ) {
                $wikiPage = $services->getWikiPageFactory()->newFromTitle( $title );
                $contentObj = $wikiPage->getContent();
                if ( $contentObj instanceof \MediaWiki\Content\TextContent ) {
                    $c = $contentObj->getText();
                }
            } else {
                $c = @file_get_contents( "$dir/" . str_replace( '$skin', $skin, $file ) );
            }
            
            $c = preg_replace_callback( '#url\(([^\)]*)\)#is', function($m) use ($skin) {
                return self::styleReplaceUrl($skin, $m);
            }, $c );
            $css .= $c;
        }
        
        header( "Content-Type: text/css" );
        echo $css;
        die();
    }

    public static function clone_options_parse( $content, $parser, $inline = false ) {
        if ( !$parser->getTitle() ) {
            return '';
        }
        $oldOpt = $parser->getOptions();
        $opt = clone $oldOpt;
        $opt->enableLimitReport( false );
        
        $magicWords = $inline ? "" : "\n__NOEDITSECTION__";
        $html = $parser->parse( $content . $magicWords, $parser->getTitle(), $opt, !$inline, false )->getText();
        
        $html = trim( $html );
        // Удаляем обертки и для макросов вроде clone_options_parse
        if ( preg_match( '/^\s*<div\s+[^>]*class="[^"]*mw-parser-output[^"]*"[^>]*>(.*)<\/div>\s*$/isu', $html, $m ) ) {
            $html = trim( $m[1] );
        }
        if ( $inline ) {
            if ( preg_match( '/^\s*<p>(.*)<\/p>\s*$/isu', $html, $m ) ) {
                $html = trim( $m[1] );
            }
        }
        return $html;
    }

    public static function slideshow_legacy( $content, $attr, $parser, $frame = null ) {
        return self::slideshow_view( $content, $attr, $parser, $frame,
            '<div style="width: 240px; color: red">Warning: legacy &lt;slide> hook used, use &lt;slideshow></div>'
        );
    }

    public static function slideshow_view( $content, $attr, $parser, $frame = null, $addmsg = '' ) {
        $services = MediaWikiServices::getInstance();
        $extPath = $services->getMainConfig()->get( 'ExtensionAssetsPath' ) . '/S5SlideShow';
        $title = $parser->getTitle();
        $parser->addTrackingCategory( 's5slideshow-tracking-category' );
        
        $attr['content'] = $content;
        $slideShow = new S5SlideShow( $title, null, $attr );
        $contentStr = '';
        
        $revStore = $services->getRevisionStore();
        $timestamp = $revStore->getKnownCurrentRevision( $title ) ? $revStore->getKnownCurrentRevision( $title )->getTimestamp() : wfTimestampNow();
        
        foreach ( [ 'title', 'subtitle', 'author', 'footer', 'subfooter' ] as $key ) {
            if ( isset( $slideShow->attr[$key] ) && $slideShow->attr[$key] !== '' ) {
                $value = $slideShow->attr[$key];
                if ( mb_strpos( $value, "{{date}}" ) !== false ) {
                    $value = str_ireplace( '{{date}}', $services->getContentLanguage()->timeanddate( $timestamp, true ), $value );
                }
                
                // Исправлено: берем локализованное название (Заголовок, Автор и т.д.)
                $msg = wfMessage( 's5slide-header-' . $key )->text();
                $contentStr .= "\n;{$msg}: " . $value;
            }
        }

        $url = htmlspecialchars( $title->getLocalUrl( [ 'action' => 'slide' ] ) );
        $style_preview = '<img src="' . $extPath . '/' . $slideShow->attr['style'] . '/preview.png" alt="Slide Show" width="240" />';
        
        $inside = self::clone_options_parse( $contentStr, $parser, true );
        $html = '<script src="' . $extPath . '/contentScale.js"></script>' .
                '<script src="' . $extPath . '/slideView.js"></script>' .
                '<div class="floatright" style="text-align: center"><span>' .
                '<a href="' . $url . '" class="image" title="Slide Show" target="_blank">' . $style_preview .
                '<br />Slide Show</a></span>' . $addmsg . '</div>' . $inside;
                
        if ( !empty( $slideShow->attr['font'] ) ) {
            $html = '<script>var wgSlideViewFont = "' . addslashes( $slideShow->attr['font'] ) . '";</script>' . $html;
        }
        return '<div id="slideshow-bundle">' . $html . '</div>';
    }


    public function slideshow_parse( $content, $attr, $parser ) {
        $attr['content'] = $content;
        $this->setAttributes( $attr );
        return '';
    }

    public static function slides_view( $content, $attr, $parser ) {
        if ( !empty( $attr['split'] ) ) {
            $slides = preg_split( '/' . str_replace( '/', '\\/', $attr['split'] ) . '/', $content );
        } else {
            $slides = [ $content ];
        }
        $html = '';
        $style = '';
        if ( !isset( $attr['float'] ) ) $style .= "float: left; ";
        if ( isset( $attr['width'] ) ) $style .= "width: {$attr['width']}px; ";
        if ( $style ) $style = " style='$style'";
        
        foreach ( $slides as $i => $slide ) {
            if ( isset( $attr['title'] ) && !$i ) {
                $slide = "== {$attr['title']} ==\n" . trim( $slide );
                $st = 'slide withtitle';
            } else {
                $st = 'slide';
            }
            $output = self::clone_options_parse( trim( $slide ), $parser, false );
            $html .= '<div class="' . $st . '" ' . $style . ' id="slide' . ( self::$slideno++ ) . '">' . $output . '</div>';
        }
        
        if ( !isset( $attr['float'] ) ) {
            $html .= '<div style="clear: both"></div>';
        } else {
            $margin = $attr['float'] == 'left' ? '0 1em 1em 0' : '0 0 0 1em';
            $html = "<div style='float: {$attr['float']}; margin: {$margin}'>$html</div>";
        }
        return $html;
    }

    public function slides_parse( $content, $attr, $parser ) {
        if ( isset( $attr['split'] ) ) {
            $slides = preg_split( '/' . str_replace( '/', '\\/', $attr['split'] ) . '/', $content );
        } else {
            $slides = [ $content ];
        }
        foreach ( $slides as $c ) {
            $this->slides[] = [ 'content' => trim( $c ) ] + $attr + [
                'title' => '', 'incremental' => false, 'center' => false,
            ];
        }
        return '';
    }

    public static function slidecss_view( $content, $attr, $parser ) {
        if ( !empty( $attr['view'] ) && ( $attr['view'] == 'true' || $attr['view'] == '1' ) ) {
            $parser->getOutput()->addHeadItem( '<style type="text/css">' . $content . '</style>' );
        }
        return '';
    }

    public function slidecss_parse( $content, $attr, $parser ) {
        $this->css[] = $content;
        return '';
    }

    public static function empty_tag_hook() {
        return '';
    }
}
