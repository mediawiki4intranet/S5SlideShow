<?php
namespace MediaWiki\Extension\S5SlideShow;

use MediaWiki\MediaWikiServices;
use Parser;

class Hooks {
    public static $parsingSlide = false;
    public static $styles = [
        'core.css'    => 's5-core.css',
        'base.css'    => 's5-base.css',
        'framing.css' => 's5-framing.css',
        'pretty.css'  => '$skin/pretty.css',
    ];

    public static function onParserFirstCallInit( Parser $parser ) {
        $s5Hooks = null;
        $s5 = null;
        if ( method_exists( $parser, 'getData' ) ) {
            $s5Hooks = $parser->getData( 's5hooks' );
            $s5 = $parser->getData( 's5' );
        } else {
            if ( isset( $parser->extS5Hooks ) ) {
                $s5Hooks = $parser->extS5Hooks;
            }
            if ( isset( $parser->extS5 ) ) {
                $s5 = $parser->extS5;
            }
        }

        if ( $s5Hooks === null ) {
            $parser->setHook( 'slideshow', [ S5SlideShow::class, 'slideshow_view' ] );
            $parser->setHook( 'slide', [ S5SlideShow::class, 'slideshow_legacy' ] );
            $parser->setHook( 'slides', [ S5SlideShow::class, 'slides_view' ] );
            $parser->setHook( 'slidecss', [ S5SlideShow::class, 'slidecss_view' ] );
        } elseif ( $s5Hooks == 'parse' ) {
            $parser->setHook( 'slideshow', [ $s5, 'slideshow_parse' ] );
            $parser->setHook( 'slide', [ $s5, 'slideshow_parse' ] );
            $parser->setHook( 'slides', [ S5SlideShow::class, 'empty_tag_hook' ] );
            $parser->setHook( 'slidecss', [ S5SlideShow::class, 'empty_tag_hook' ] );
        } elseif ( $s5Hooks == 'parse2' ) {
            $parser->setHook( 'slideshow', [ S5SlideShow::class, 'empty_tag_hook' ] );
            $parser->setHook( 'slide', [ S5SlideShow::class, 'empty_tag_hook' ] );
            $parser->setHook( 'slides', [ $s5, 'slides_parse' ] );
            $parser->setHook( 'slidecss', [ $s5, 'slidecss_parse' ] );
        }
        $parser->setFunctionHook( 's5slideshow', function( $parser ) {
            return empty( self::$parsingSlide ) ? '' : '1';
        } );
        return true;
    }

    public static function onImageBeforeProduceHTML( $dummy, $title, $file, &$frameParams, &$handlerParams, $time, &$res ) {
        if ( empty( self::$parsingSlide ) || !$file || !$file->exists() || !isset( $handlerParams['width'] ) ) {
            return true;
        }
        $center = false;
        if ( isset( $frameParams['align'] ) && $frameParams['align'] == 'center' ) {
            $center = true;
            $frameParams['align'] = 'none';
        }
        $thumb = $file->getUnscaledThumb( isset( $handlerParams['page'] ) ? [ 'page' => $handlerParams['page'] ] : false );
        $params = [
            'alt' => $frameParams['alt'] ?? '',
            'title' => $frameParams['title'] ?? '',
            'override-height' => ceil( $thumb->getHeight() * $handlerParams['width'] / $thumb->getWidth() ),
            'override-width' => $handlerParams['width']
        ];

        if ( !empty( $frameParams['link-url'] ) ) {
            $params['custom-url-link'] = $frameParams['link-url'];
        } elseif ( !empty( $frameParams['link-title'] ) ) {
            $params['custom-title-link'] = $frameParams['link-title'];
        } elseif ( empty( $frameParams['no-link'] ) ) {
            $params['desc-link'] = true;
        }

        $res .= $thumb->toHtml( $params );
        if ( isset( $frameParams['thumbnail'] ) ) {
            $align = $frameParams['align'] ?? '';
            $caption = $frameParams['caption'] ?? '';
            $res = "<div class=\"thumb t{$align}\" style='border:0'><div class=\"thumbinner\">{$res}</div><div class='thumbcaption'>{$caption}</div></div>";
        }
        if ( isset( $frameParams['align'] ) && $frameParams['align'] ) {
            $res = "<div class=\"float{$frameParams['align']}\">{$res}</div>";
        }
        if ( $center ) {
            $res = "<div class=\"center\">{$res}</div>";
        }
        return false;
    }

    public static function onEditFormPreloadText( &$text, &$title ) {
        if ( $title->getNamespace() == NS_MEDIAWIKI && preg_match( '#^S5/([\w-]+)/((core|base|framing|pretty)\.css)$#s', $title->getText(), $m ) ) {
            $file = dirname( __DIR__ ) . '/' . str_replace( '$skin', $m[1], self::$styles[$m[2]] );
            if ( file_exists( $file ) ) {
                $text = file_get_contents( $file );
            }
        }
        return true;
    }
}
